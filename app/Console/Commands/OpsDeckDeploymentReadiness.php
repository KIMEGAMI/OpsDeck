<?php

namespace App\Console\Commands;

use App\Services\Ai\AiModelPricing;
use App\Services\BackupHealthService;
use App\Services\BackupRestoreHealthService;
use App\Services\BackupRestoreTargetConfiguration;
use App\Services\CommercialInformationReadiness;
use App\Services\DatabaseClientVersionDetector;
use App\Services\DatabaseDumpBinaryLocator;
use App\Services\DatabaseMigrationHealthService;
use App\Services\DatabaseTlsConfiguration;
use App\Services\GoogleLoginCallbackReadiness;
use App\Services\PhpExtensionReadiness;
use App\Services\QueueHealthService;
use App\Services\R2UploadCommandOptions;
use App\Services\ResolvedDatabaseConfiguration;
use App\Services\RuntimeDirectoryReadiness;
use App\Services\SchedulerHealthService;
use App\Services\StripeTaxRegistrationReadiness;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Schema\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\StreamHandler;
use Pheanstalk\Pheanstalk;
use Predis\Client;
use Throwable;

#[Signature('opsdeck:deployment-check {--json : Output machine-readable JSON} {--skip-provider-verification : Skip live Resend and Stripe checks and report them as unverified} {--skip-stripe-verification : Skip the live Stripe check and report it as unverified}')]
#[Description('Check production deployment configuration without revealing secret values.')]
class OpsDeckDeploymentReadiness extends Command
{
    private const MIN_QUEUE_RETRY_SECONDS = 1;

    private const MAX_NETWORK_PORT = 65535;

    private const STRIPE_PRICE_CONNECT_TIMEOUT_SECONDS = 3;

    private const STRIPE_PRICE_TIMEOUT_SECONDS = 10;

    private const STRIPE_PORTAL_CONFIGURATION_LIMIT = 1;

    private const STRIPE_PORTAL_CANCELLATION_MODE = 'at_period_end';

    private const STRIPE_PORTAL_CANCELLATION_PRORATION_BEHAVIOR = 'none';

    private const RESEND_DOMAIN_CONNECT_TIMEOUT_SECONDS = 3;

    private const RESEND_DOMAIN_TIMEOUT_SECONDS = 10;

    private const RESEND_DOMAIN_PAGE_SIZE = 100;

    private const RESEND_SMTP_HOST = 'smtp.resend.com';

    private const RESEND_SMTP_PORT = 587;

    private const CACHE_LOCK_TTL_SECONDS = 10;

    private const WORLD_PERMISSION_MASK = 0007;

    private const PRODUCTION_LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'warn', 'error', 'critical', 'alert', 'emergency'];

    private const PRODUCTION_LOG_OUTPUT_DRIVERS = ['daily', 'monthly', 'stderr', 'errorlog', 'syslog', 'slack', 'monolog'];

    /** @var array<string, string> */
    private array $checks = [];

    private ?bool $durableQueueReadiness = null;

    private ?bool $sharedCacheReadiness = null;

    private ?bool $resendSendingDomainReadiness = null;

    private ?string $databaseConnectionIssue = null;

    private ?string $mailReadinessIssue = null;

    private ?string $stripeReadinessIssue = null;

    private ?string $backupRestoreTargetIssue = null;

    /** @var list<string> */
    private array $backupRestoreTargetConfigurationIssues = [];

    /** @var list<string> */
    private array $queueReadinessIssues = [];

    /** @var list<string> */
    private array $backupEncryptionIssues = [];

    /** @var array<string, Builder> */
    private array $databaseSchemaBuilders = [];

    public function handle(DatabaseDumpBinaryLocator $dumpBinaryLocator, DatabaseClientVersionDetector $clientVersionDetector, BackupHealthService $backupHealth, BackupRestoreHealthService $backupRestoreHealth, BackupRestoreTargetConfiguration $restoreTargetConfiguration, DatabaseTlsConfiguration $databaseTls, ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration, PhpExtensionReadiness $phpExtensionReadiness, RuntimeDirectoryReadiness $runtimeDirectoryReadiness, DatabaseMigrationHealthService $migrationHealth, SchedulerHealthService $schedulerHealth, AiModelPricing $aiModelPricing, GoogleLoginCallbackReadiness $googleLoginCallbackReadiness): int
    {
        $this->durableQueueReadiness = null;
        $this->sharedCacheReadiness = null;
        $this->resendSendingDomainReadiness = null;
        $this->databaseConnectionIssue = null;
        $this->mailReadinessIssue = null;
        $this->stripeReadinessIssue = null;
        $this->backupRestoreTargetIssue = null;
        $this->backupRestoreTargetConfigurationIssues = [];
        $this->queueReadinessIssues = [];
        $this->backupEncryptionIssues = $this->backupEncryptionConfigurationIssues();
        $missingPhpExtensions = $phpExtensionReadiness->missingExtensions();
        $unavailableRuntimeDirectories = $runtimeDirectoryReadiness->unavailableDirectories($this->runtimeDirectories());
        $databaseConnectionReady = $this->canConnectToDatabase($databaseTls, $resolvedDatabaseConfiguration);
        $pendingMigrationCount = $databaseConnectionReady ? $migrationHealth->pendingCount() : null;
        $schedulerHeartbeatStatus = 'unknown';
        if ($databaseConnectionReady) {
            try {
                $schedulerHeartbeatStatus = $schedulerHealth->status();
            } catch (Throwable) {
                $schedulerHeartbeatStatus = 'unknown';
            }
        }
        $skipProviderVerification = (bool) $this->option('skip-provider-verification');
        $skipStripeVerification = $skipProviderVerification || (bool) $this->option('skip-stripe-verification');
        $billingEnabled = (bool) config('opsdeck.billing.enabled');
        $skippedProviderChecks = $skipProviderVerification ? ['mail', 'failure_alert'] : [];
        if ($billingEnabled && $skipStripeVerification) {
            $skippedProviderChecks[] = 'stripe';
        }
        $productionMailTransportReady = $skipProviderVerification ? null : $this->hasProductionMailTransport();
        $this->backupRestoreTargetIssue = $restoreTargetConfiguration->readinessStatus();
        $latestBackupStatus = 'not_applicable';
        $backupRestoreTestStatus = 'not_applicable';
        if (app()->environment('production')) {
            try {
                $latestBackupStatus = $backupHealth->report()['status'] ?? 'invalid';
            } catch (Throwable) {
                $latestBackupStatus = 'invalid';
            }
            try {
                $backupRestoreTestStatus = $backupRestoreHealth->report()['status'] ?? 'invalid';
            } catch (Throwable) {
                $backupRestoreTestStatus = 'invalid';
            }
        }
        if ($this->backupRestoreTargetIssue === 'unsafe_configuration') {
            $this->backupRestoreTargetConfigurationIssues = $restoreTargetConfiguration->configurationIssues();
        }
        $this->checks = [
            'environment' => app()->environment('production') ? 'pass' : 'fail',
            'release_scope' => $this->hasRequiredReleaseScope() ? 'pass' : 'fail',
            'app_key' => $this->hasValidApplicationKey() ? 'pass' : 'fail',
            'debug' => config('app.debug') === false ? 'pass' : 'fail',
            'https_url' => $this->hasHttpsApplicationUrl() ? 'pass' : 'fail',
            'hsts' => $this->hasConfiguredHsts() ? 'pass' : 'fail',
            'session_cookie_security' => $this->hasSecureSessionCookies() ? 'pass' : 'fail',
            'private_file_storage' => $this->hasPrivateSensitiveFileStorage() ? 'pass' : 'fail',
            'production_logging' => $this->hasProductionLogging() ? 'pass' : 'fail',
            'runtime_directories' => $unavailableRuntimeDirectories === [] ? 'pass' : 'fail',
            'php_extensions' => $missingPhpExtensions === [] ? 'pass' : 'fail',
            'database_driver' => in_array(config('database.default'), ['mysql', 'mariadb'], true) ? 'pass' : 'fail',
            'database_connection' => $databaseConnectionReady ? 'pass' : 'fail',
            'database_migrations' => $databaseConnectionReady
                ? ($pendingMigrationCount === 0 ? 'pass' : 'fail')
                : 'unverified',
            'database_backup_tool' => $this->hasDatabaseBackupTool($dumpBinaryLocator, $clientVersionDetector, $resolvedDatabaseConfiguration)
                && config('opsdeck.backup.remote_enabled') === false
                && (! app()->environment('production') || $latestBackupStatus === 'ok') ? 'pass' : 'fail',
            'database_restore_tool' => $this->hasDatabaseRestoreTool($dumpBinaryLocator, $clientVersionDetector, $resolvedDatabaseConfiguration)
                && (! app()->environment('production') || $backupRestoreTestStatus === 'ok') ? 'pass' : 'fail',
            'backup_restore_target' => $this->backupRestoreTargetIssue === 'ready' ? 'pass' : 'fail',
            'queue' => $this->usesDurableQueue() ? 'pass' : 'fail',
            'scheduler_heartbeat' => $schedulerHeartbeatStatus === 'ok' ? 'pass' : 'fail',
            'cache_lock' => $this->usesSharedCache() ? 'pass' : 'fail',
            'mail' => $productionMailTransportReady === null ? 'unverified' : ($productionMailTransportReady ? 'pass' : 'fail'),
            'failure_alert' => $skipProviderVerification
                ? 'unverified'
                : ($this->hasProductionFailureAlert($productionMailTransportReady) ? 'pass' : 'fail'),
            'stripe' => ! $billingEnabled
                ? 'not_applicable'
                : ($skipStripeVerification ? 'unverified' : ($this->hasProductionStripeCredentials() ? 'pass' : 'fail')),
            'commercial_information' => app()->environment('production') && $billingEnabled
                ? (app(CommercialInformationReadiness::class)->isConfigured() ? 'pass' : 'fail')
                : 'not_applicable',
            'frontend_assets' => app()->environment('production')
                ? ($this->hasBuiltFrontendAssets() ? 'pass' : 'fail')
                : 'not_applicable',
        ];
        if (config('services.google.login_enabled') === true) {
            $this->checks['google_login_callback'] = $googleLoginCallbackReadiness->matchesApplicationUrl() ? 'pass' : 'fail';
        }
        $aiEnabled = config('opsdeck.ai.enabled') === true;
        $aiUnavailableStatus = app()->environment('production') ? 'fail' : 'not_applicable';
        $this->checks['ai_mapping'] = ! $aiEnabled
            ? $aiUnavailableStatus
            : ($this->hasAiMappingConfiguration() ? 'pass' : 'fail');
        $this->checks['ai_cost'] = ! $aiEnabled
            ? $aiUnavailableStatus
            : ($this->hasAiCostConfiguration($aiModelPricing) ? 'pass' : 'fail');
        if (app()->environment('production') && config('opsdeck.storage_quotas.platform_limit_megabytes') === null) {
            $this->checks['platform_storage_capacity'] = 'fail';
        }

        $ready = ! array_intersect(['fail', 'unverified'], $this->checks);

        if ($this->option('json')) {
            $output = [
                'status' => $ready ? 'ready' : 'not_ready',
                'checks' => $this->checks,
            ];
            $diagnostics = [];
            if ($this->databaseConnectionIssue !== null) {
                $diagnostics['database_connection'] = $this->databaseConnectionIssue;
            }
            if ($this->mailReadinessIssue !== null) {
                $diagnostics['mail'] = $this->mailReadinessIssue;
            }
            if ($this->stripeReadinessIssue !== null) {
                $diagnostics['stripe'] = $this->stripeReadinessIssue;
            }
            if ($this->backupRestoreTargetIssue !== null && $this->backupRestoreTargetIssue !== 'ready') {
                $diagnostics['backup_restore_target'] = $this->backupRestoreTargetIssue;
            }
            if ($this->backupRestoreTargetConfigurationIssues !== []) {
                $diagnostics['backup_restore_target_issues'] = $this->backupRestoreTargetConfigurationIssues;
            }
            if ($missingPhpExtensions !== []) {
                $diagnostics['php_extensions'] = $missingPhpExtensions;
            }
            if ($unavailableRuntimeDirectories !== []) {
                $diagnostics['runtime_directories'] = $unavailableRuntimeDirectories;
            }
            if ($this->backupEncryptionIssues !== []) {
                $diagnostics['backup_encryption'] = $this->backupEncryptionIssues;
            }
            if ($this->queueReadinessIssues !== []) {
                $diagnostics['queue'] = $this->queueReadinessIssues;
            }
            if ($schedulerHeartbeatStatus !== 'ok') {
                $diagnostics['scheduler_heartbeat'] = $schedulerHeartbeatStatus;
            }
            if ($skippedProviderChecks !== []) {
                $diagnostics['provider_verification'] = [
                    'status' => 'skipped',
                    'checks' => $skippedProviderChecks,
                ];
            }
            if ($diagnostics !== []) {
                $output['diagnostics'] = $diagnostics;
            }

            $this->line(json_encode($output, JSON_THROW_ON_ERROR));
        } else {
            foreach ($this->checks as $check => $status) {
                $this->line(sprintf(
                    '%s（%s）: %s',
                    $this->checkDisplayName($check),
                    $check,
                    $this->statusDisplayName($status),
                ));
            }

            if (! $billingEnabled) {
                $this->line('課金機能が無効のため、Stripeと販売者情報の確認は対象外です。');
            } elseif ($skipProviderVerification) {
                $this->line('ResendとStripeの外部検証は省略されました。リリース前に通常の導入チェックを実行してください。');
            } elseif ($skipStripeVerification) {
                $this->line('Stripeの外部検証は省略されました。リリース前に通常の導入チェックを実行してください。');
            }

            $this->newLine();
            $this->line($ready ? '本番導入チェックはすべて合格しました。' : '本番導入の前に、要対応の項目があります。');
            if (! $ready) {
                $this->writeRemediationGuidance($missingPhpExtensions, $unavailableRuntimeDirectories, $skippedProviderChecks);
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $missingPhpExtensions @param list<string> $unavailableRuntimeDirectories @param list<string> $skippedProviderChecks */
    private function writeRemediationGuidance(array $missingPhpExtensions, array $unavailableRuntimeDirectories, array $skippedProviderChecks): void
    {
        $this->newLine();
        $this->line('確認・修正する項目:');
        if (! app()->environment('production')) {
            $this->line('このチェックは本番以外（'.app()->environment().'）で実行されています。本番設定は本番サーバーに設定し、開発PCの.envは変更しないでください。');
        }

        foreach ($this->checks as $check => $status) {
            if (! in_array($status, ['fail', 'unverified'], true)) {
                continue;
            }

            if (in_array($check, $skippedProviderChecks, true)) {
                $this->line(sprintf('- %s（%s）: 外部検証を省略したため未確認です。リリース前に通常の導入チェックを実行してください', $this->checkDisplayName($check), $check));

                continue;
            }

            if ($check === 'php_extensions') {
                $this->line(sprintf('- %s（%s）: PHP拡張を有効化: %s', $this->checkDisplayName($check), $check, implode(', ', $missingPhpExtensions)));

                continue;
            }

            if ($check === 'runtime_directories') {
                $this->line(sprintf('- %s（%s）: 不足・書込不可、または全員書込可能な保存先を修正し、このコマンドの実行ユーザーに必要最小限の書込権限を付与してください。chmod 777は使わないでください。PHP-FPMの実行ユーザーが異なる場合は、そのユーザーからも別途確認してください。対象: %s', $this->checkDisplayName($check), $check, implode(', ', $unavailableRuntimeDirectories)));

                continue;
            }

            $maximumBackupAgeHours = max(1, (int) config('opsdeck.backup.max_age_hours'));
            $maximumRestoreTestAgeDays = max(1, (int) config('opsdeck.backup.restore_test_max_age_days'));
            $guidance = match ($check) {
                'environment' => '環境変数 APP_ENV を production に設定する',
                'release_scope' => '組織ごとの保存容量を固定し、追加容量販売を無効にしたうえで対象外機能を無効にする: OPSDECK_RELEASE_GOOGLE_SHEETS=false、OPSDECK_RELEASE_IMPACT_REPORT=false、OPSDECK_RELEASE_QUESTIONNAIRE_AI_DRAFT=false、OPSDECK_RELEASE_REFERRALS=false、OPSDECK_RELEASE_WORK_TIME_TRACKING=false',
                'app_key' => 'Laravelの暗号方式に合う APP_KEY を安全に生成して設定する',
                'debug' => '環境変数 APP_DEBUG を false に設定する',
                'https_url' => '環境変数 APP_URL に https:// から始まる公開URLを設定する',
                'hsts' => 'OPSDECK_SECURITY_HSTS_MAX_AGE_SECONDS に正の秒数を設定する',
                'session_cookie_security' => 'SESSION_SECURE_COOKIE=true、SESSION_HTTP_ONLY=true、SESSION_SAME_SITE=lax を設定する',
                'google_login_callback' => 'APP_URLとGOOGLE_LOGIN_REDIRECT_URIのscheme・host・portを一致させ、戻り先を/auth/google/callbackにする。Google Cloud Consoleにも同一URIを登録する',
                'private_file_storage' => 'Import/Evidenceを非公開ディスクへ保存し、R2ではOPSDECK_STORAGE_KEY / SECRET / REGION / BUCKET / ENDPOINTを設定する。S3はvisibility=privateとバケットの公開拒否、SFTPは非公開権限を設定する',
                'production_logging' => '本番ログをwarning以上にし、daily/monthlyの保存期間を設定するか、stderr/syslog等の監視可能な出力先へ送る',
                'frontend_assets' => '本番公開前に npm run build を実行し、public/build/manifest.json と参照されるCSS・JavaScript一式を配置する。開発用 public/hot ファイルは本番へ置かない',
                'database_driver' => '環境変数 DB_CONNECTION を mysql または mariadb に設定する',
                'database_connection' => $this->databaseConnectionIssue === 'remote_database_requires_verified_tls'
                    ? 'リモートMySQL/MariaDBではMYSQL_ATTR_SSL_CAにCA証明書を設定し、サーバー証明書検証を有効にする'
                    : 'DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME を確認し、DB_PASSWORDは本番Secret ManagerまたはVPS上の権限制限した.envで設定する。DBサーバーの稼働・ネットワーク/ポート到達性も確認する',
                'database_migrations' => $status === 'unverified'
                    ? 'DB接続失敗のため未確認です。先に接続先と対象DBが正しいことを確認し、再チェックしてください。未適用と確認できるまではマイグレーションを実行しないでください'
                    : '未適用マイグレーションを確認し、対象DBと事前バックアップを確認したうえで php artisan migrate --force を実行する',
                'database_backup_tool' => 'mysqldump または mariadb-dump を用意し、必要なら OPSDECK_DATABASE_DUMP_DIRECTORY を設定する。1.0はローカル保存専用のため OPSDECK_BACKUP_REMOTE_ENABLED=false にする。バックアップはVPSローカルの OPSDECK_BACKUP_PATH（未指定時は storage/app/backups）へDB・証跡・取込元を保存する。直近'.$maximumBackupAgeHours.'時間以内の有効なバックアップが必要です。未作成・古い場合は php artisan opsdeck:backup を実行して検証してください。APP_KEYは復元時も維持します。暗号化は任意で、無効のままならsodiumと暗号鍵は不要',
                'database_restore_tool' => 'mysql または mariadb クライアントと、接頭辞付きの専用復元DB設定を確認する。本番では直近'.$maximumRestoreTestAgeDays.'日以内の復元テスト成功が必要です。未実施・失敗・期限切れの場合は専用DBの安全確認後、php artisan opsdeck:backup:restore-test を実行してください',
                'backup_restore_target' => match ($this->backupRestoreTargetIssue) {
                    'unsafe_configuration' => 'OPSDECK_BACKUP_RESTORE_DB_*を確認する。接頭辞 opsdeck_restore_test_ の専用DB名、アプリDBと異なるユーザー・DB名、アプリと同じMySQL系ドライバー、接続先の安全なTLS設定が必要です',
                    'credentials_rejected' => '復元専用DBのOPSDECK_BACKUP_RESTORE_DB_USERNAMEとOPSDECK_BACKUP_RESTORE_DB_PASSWORD、MySQL側のユーザー名・接続元ホストを確認してください。パスワードはログやチャットに貼り付けないでください',
                    'database_access_denied' => '復元専用ユーザーにOPSDECK_BACKUP_RESTORE_DB_DATABASEへの必要な権限があるか、DB管理者に確認してください。アプリDBではなく接頭辞付きの専用復元DBだけを対象にしてください',
                    'database_not_found' => 'OPSDECK_BACKUP_RESTORE_DB_DATABASEで指定した専用復元DBが存在するか、DB管理者に確認してください',
                    'connection_unavailable' => '復元専用DBへの接続を確認する。DBサーバーの稼働、接続先、ポート、専用ユーザーの接続権限を確認してください',
                    'target_not_empty' => '復元専用DBに既存テーブルまたはビューがあります。中身を確認し、専用の空DBを設定してください。このチェックはDBを変更しません',
                    default => 'OPSDECK_BACKUP_RESTORE_DB_*の設定値と接続疎通を確認し、接頭辞 opsdeck_restore_test_ の復元専用DBが空であることを確認してください',
                },
                'scheduler_heartbeat' => '本番サーバーで毎分 php artisan schedule:run をプロセスマネージャー／cronに設定し、heartbeatを確認する',
                'queue' => '永続Queueの設定とjobsテーブルを確認し、QUEUE_FAILED_DRIVER=database-uuids / failed_jobsがメインDBにあること、Queue workerの稼働を確認する',
                'cache_lock' => '共有Cacheの接続、DB利用時はcache/cache_locksテーブルを確認し、一時ロックの取得・解放ができることを確認する',
                'mail' => 'Resendを使う場合は、既存のMAIL_MAILER=smtp、MAIL_HOST=smtp.resend.com、MAIL_PORT=587、MAIL_SCHEME=smtp、MAIL_USERNAME=resend、MAIL_PASSWORD、MAIL_FROM_ADDRESS、MAIL_FROM_NAME、CONTACT_TO_ADDRESSを設定する。問い合わせ先CONTACT_TO_ADDRESSは送信元と分ける。メール送信の再試行間隔MAIL_DELIVERY_JOB_BACKOFF_SECONDSも確認する。OpsDeckの本番メール確認では送信元ドメインの状態も検証するため、送信方式にかかわらずRESEND_DOMAIN_CHECK_API_KEYが必要。',
                'failure_alert' => 'OPSDECK_PLATFORM_ADMIN_EMAILSに有効な管理者メールを設定し、共有Cacheとメール送信設定を確認する',
                'stripe' => 'Premium課金を公開するためOPSDECK_BILLING_ENABLED=trueを設定し、OPSDECK_STRIPE_ENVIRONMENT=live、OPSDECK_STRIPE_LIVE_SECRET_KEY / OPSDECK_STRIPE_LIVE_PRICE_PREMIUM / OPSDECK_STRIPE_LIVE_WEBHOOK_SECRETを本番モードの値にする。追加容量にはOPSDECK_STRIPE_LIVE_PRICE_STORAGE_ADDON_GIBも設定する',
                'ai_mapping' => 'OPSDECK_AI_ENABLED=true、OPSDECK_AI_PROVIDER=openai、OPENAI_API_KEY と OPENAI_API_ENDPOINT=https://api.openai.com/v1/responses を設定してください。この確認は外部APIへ接続しません。AI障害時は手動マッピングを利用できます',
                'ai_cost' => 'OPSDECK_AI_ENABLED=true、OPSDECK_AI_USD_TO_JPY_RATE と OPSDECK_AI_MODEL_PRICES_PER_MILLION_TOKENS を設定し、利用モデルの入力・出力単価をAI事業者の最新料金表に合わせる',
                'commercial_information' => 'OPSDECK_SELLER_NAME / OPSDECK_SELLER_ADDRESS / OPSDECK_SELLER_PHONE / OPSDECK_SELLER_REPRESENTATIVE に正確な販売者情報を設定する',
                'platform_storage_capacity' => '他サービスと共有するディスク容量・バックアップ・運用予備を考慮し、OPSDECK_PLATFORM_STORAGE_LIMIT_MIB にOpsDeck専用の上限MiBを設定する',
                default => null,
            };

            if ($guidance !== null) {
                $this->line(sprintf('- %s（%s）: %s', $this->checkDisplayName($check), $check, $guidance));
            }

            if ($check === 'database_connection' && $this->databaseConnectionIssue !== null) {
                $detailCheck = 'database_connection_detail';
                $this->line(sprintf('- %s（%s）: %s', $this->checkDisplayName($detailCheck), $detailCheck, $this->databaseConnectionIssue));

                if ($this->databaseConnectionIssue === 'credentials_rejected') {
                    $this->line('- MySQLの確認: サーバーには接続できています。DB_USERNAME / DB_PASSWORDと、MySQLユーザーの接続元ホスト設定をDB管理者に確認してください。パスワードをログやチャットに貼り付けないでください。');
                } elseif ($this->databaseConnectionIssue === 'database_access_denied') {
                    $this->line('- MySQLの確認: 認証後に対象DBへのアクセスが拒否されています。DB_DATABASEと、アプリ専用ユーザーに対象DBの権限が付与されているかをDB管理者に確認してください。');
                } elseif ($this->databaseConnectionIssue === 'database_not_found') {
                    $this->line('- MySQLの確認: DB_DATABASEで指定したデータベースが見つかりません。接続先とDB名を確認し、必要ならDB管理者に専用データベースの作成を依頼してください。');
                }

                if ($this->databaseConnectionIssue === 'endpoint_unreachable' && PHP_OS_FAMILY === 'Windows') {
                    $this->line('- Windowsの接続確認（PowerShell）: Test-NetConnection -ComputerName "DB_HOST" -Port DB_PORT（DB_HOSTとDB_PORTは`.env`の値に置き換えてください）');

                    if ($this->usesLocalDatabaseHost()) {
                        $this->line('- ローカルのMySQL/MariaDBサービス確認（読み取りのみ）: Get-Service | Where-Object { $_.Name -match "mysql|maria" -or $_.DisplayName -match "MySQL|MariaDB" } | Format-Table Status,Name,DisplayName');
                    }

                    $this->line('- この確認ではDBサービスを起動しません。停止中の場合は、サービス名と対象DBを確認してから管理者に相談してください。');
                }
            }
            if ($check === 'mail' && $this->mailReadinessIssue !== null) {
                $this->line('- メール設定の詳細: '.$this->providerReadinessIssueMessage($this->mailReadinessIssue));
            }
            if ($check === 'stripe' && $this->stripeReadinessIssue !== null) {
                $this->line('- Stripe設定の詳細: '.$this->providerReadinessIssueMessage($this->stripeReadinessIssue));
            }
            if ($check === 'scheduler_heartbeat') {
                $this->line('- 実行予定の確認（ジョブは実行しません。ロック状態の確認でCacheを一時利用します）: php artisan schedule:list');
                $this->line('- 注意: 週次サマリーとメール再送処理が含まれます。メール送信上限・許可時間と送信予定を確認し、管理者承認後に本番サーバーへ設定してください。');
                $this->line('- 開発PCでは起動しないでください。');
            }
            if ($check === 'queue') {
                $queueMonitorCommand = $this->queueMonitorCommand();
                if ($queueMonitorCommand !== null) {
                    $this->line('- 待機件数の確認（ジョブは実行しません）: '.$queueMonitorCommand);
                }
                $this->line('- 注意: Queue workerは待機中の処理を実行します（メール送信を含みます）。');
                $this->line('- 起動前に送信予定と送信枠を確認し、承認後に本番サーバーだけで起動してください。');
                $this->line('- 開発PCでは起動しないでください。');
            }
            if ($check === 'backup_restore_target' && $this->backupRestoreTargetIssue !== null) {
                $detailCheck = 'backup_restore_target_detail';
                $this->line(sprintf('- %s（%s）: %s', $this->checkDisplayName($detailCheck), $detailCheck, $this->backupRestoreTargetIssue));
                if ($this->backupRestoreTargetConfigurationIssues !== []) {
                    $issuesCheck = 'backup_restore_target_issues';
                    $this->line(sprintf('- %s（%s）: %s', $this->checkDisplayName($issuesCheck), $issuesCheck, implode(', ', $this->backupRestoreTargetConfigurationIssues)));
                    foreach ($this->backupRestoreTargetConfigurationIssues as $configurationIssue) {
                        $issueRemediation = $this->backupRestoreTargetIssueRemediation($configurationIssue);
                        if ($issueRemediation !== null) {
                            $actionCheck = 'backup_restore_target_action';
                            $this->line(sprintf('- %s（%s）: %s', $this->checkDisplayName($actionCheck), $actionCheck, $issueRemediation));
                        }
                    }
                }
            }
        }

        $this->line('秘密値はこのチェックでは表示しません。');
    }

    private function usesLocalDatabaseHost(): bool
    {
        $connectionName = (string) config('database.default');
        $host = config('database.connections.'.$connectionName.'.host');

        return is_string($host)
            && in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function queueMonitorCommand(): ?string
    {
        $connectionName = (string) config('queue.default');
        $connection = config('queue.connections.'.$connectionName);
        if (! is_array($connection)) {
            return null;
        }

        $driver = $connection['driver'] ?? $connectionName;
        $queueName = $connection['queue'] ?? 'default';
        if (! in_array($driver, ['database', 'redis', 'beanstalkd', 'sqs'], true)
            || preg_match('/\A[a-zA-Z0-9_.-]+\z/', $connectionName) !== 1
            || ! is_string($queueName)
            || preg_match('/\A[a-zA-Z0-9_.-]+\z/', $queueName) !== 1) {
            return null;
        }

        return sprintf('php artisan queue:monitor %s:%s --json', $connectionName, $queueName);
    }

    private function checkDisplayName(string $check): string
    {
        return (string) config('opsdeck.deployment_check_labels.'.$check, $check);
    }

    /** @return array<string, string> */
    private function runtimeDirectories(): array
    {
        $directories = [
            'bootstrap/cache' => base_path('bootstrap/cache'),
            'compiled_views' => is_string(config('view.compiled'))
                ? config('view.compiled')
                : storage_path('framework/views'),
            'backup_directory' => (string) config('opsdeck.backup.path'),
        ];

        foreach ($this->fileLogDirectories() as $directoryName => $path) {
            $directories[$directoryName] = $path;
        }

        if (config('session.driver') === 'file' && is_string(config('session.files'))) {
            $directories['session_files'] = config('session.files');
        }

        $cacheStore = config('cache.stores.'.config('cache.default'));
        if (is_array($cacheStore) && ($cacheStore['driver'] ?? null) === 'file') {
            foreach (['path', 'lock_path'] as $pathKey) {
                if (is_string($cacheStore[$pathKey] ?? null)) {
                    $directories['file_cache_'.$pathKey] = $cacheStore[$pathKey];
                }
            }
        }

        foreach (['opsdeck.import.disk' => 'import_storage', 'opsdeck.evidence.disk' => 'evidence_storage'] as $diskConfigKey => $directoryName) {
            $diskName = config($diskConfigKey);
            $disk = is_string($diskName) ? config('filesystems.disks.'.$diskName) : null;
            if (is_array($disk) && ($disk['driver'] ?? null) === 'local' && is_string($disk['root'] ?? null)) {
                $directories[$directoryName] = $disk['root'];
            }
        }

        return $directories;
    }

    /** @return array<string, string> */
    private function fileLogDirectories(): array
    {
        $defaultChannelName = config('logging.default');
        $defaultChannel = is_string($defaultChannelName) ? config('logging.channels.'.$defaultChannelName) : null;
        if (! is_array($defaultChannel)) {
            return [];
        }

        $channelNames = ($defaultChannel['driver'] ?? null) === 'stack'
            ? ($defaultChannel['channels'] ?? null)
            : [$defaultChannelName];
        if (is_string($channelNames)) {
            $channelNames = explode(',', $channelNames);
        }
        if (! is_array($channelNames)) {
            return [];
        }

        $directories = [];
        foreach ($channelNames as $channelName) {
            if (! is_string($channelName)) {
                continue;
            }

            $normalizedChannelName = trim($channelName);
            $channel = config('logging.channels.'.$normalizedChannelName);
            if (! is_array($channel)) {
                continue;
            }

            $driver = $channel['driver'] ?? null;
            $filePath = in_array($driver, ['single', 'daily', 'monthly'], true)
                ? ($channel['path'] ?? null)
                : null;

            $handler = $channel['handler'] ?? null;
            $handlerConfiguration = $channel['handler_with'] ?? [];
            if ($driver === 'monolog'
                && is_string($handler)
                && is_a($handler, RotatingFileHandler::class, true)
                && is_array($handlerConfiguration)) {
                $filePath = $handlerConfiguration['filename'] ?? null;
            }

            if (is_string($filePath) && trim($filePath) !== '') {
                $directories['log_directory_'.$normalizedChannelName] = dirname($filePath);
            }
        }

        return $directories;
    }

    private function statusDisplayName(string $status): string
    {
        return match ($status) {
            'pass' => '合格',
            'fail' => '要対応',
            'unverified' => '未確認',
            'not_applicable' => '対象外',
            default => $status,
        };
    }

    private function backupRestoreTargetIssueRemediation(string $configurationIssue): ?string
    {
        return match ($configurationIssue) {
            'restore_connection_not_separate' => 'OPSDECK_BACKUP_RESTORE_CONNECTIONにアプリDBとは別の接続名を設定する',
            'restore_driver_must_match_mysql_application_driver' => 'OPSDECK_BACKUP_RESTORE_DB_DRIVERをアプリDBと同じmysqlまたはmariadbにする',
            'restore_database_name_must_use_reserved_prefix' => 'OPSDECK_BACKUP_RESTORE_DB_DATABASEをopsdeck_restore_test_で始まる専用DB名にする',
            'restore_database_must_differ_from_application_database' => '復元DB名をDB_DATABASEと異なる値にする',
            'restore_database_user_must_be_dedicated' => 'OPSDECK_BACKUP_RESTORE_DB_USERNAMEにDB_USERNAMEとは別の専用ユーザーを設定する',
            'restore_database_host_or_socket_is_invalid' => 'OPSDECK_BACKUP_RESTORE_DB_HOSTまたはOPSDECK_BACKUP_RESTORE_DB_SOCKETに有効な接続先を設定する',
            'restore_database_port_is_invalid' => 'OPSDECK_BACKUP_RESTORE_DB_PORTを有効なポートにする',
            'remote_restore_database_requires_verified_tls' => 'リモート復元DBではOPSDECK_BACKUP_RESTORE_DB_SSL_CAを設定し、証明書検証を有効にする',
            default => null,
        };
    }

    private function hasHttpsApplicationUrl(): bool
    {
        $url = parse_url((string) config('app.url'));
        if (! is_array($url)
            || strtolower((string) ($url['scheme'] ?? '')) !== 'https'
            || ! filled($url['host'] ?? null)
            || array_key_exists('user', $url)
            || array_key_exists('pass', $url)
            || array_key_exists('query', $url)
            || array_key_exists('fragment', $url)) {
            return false;
        }

        $host = strtolower(trim((string) $url['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        $domain = rtrim($host, '.');

        return str_contains($domain, '.')
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function hasBuiltFrontendAssets(): bool
    {
        if (is_file(public_path('hot'))) {
            return false;
        }

        $manifestContents = @file_get_contents(public_path('build/manifest.json'));
        if (! is_string($manifestContents)) {
            return false;
        }

        $manifest = json_decode($manifestContents, true);
        $requiredEntries = config('opsdeck.deployment_vite_entries');
        $buildDirectory = realpath(public_path('build'));
        if (! is_array($manifest)
            || $manifest === []
            || ! is_array($requiredEntries)
            || $requiredEntries === []
            || ! is_string($buildDirectory)) {
            return false;
        }

        foreach ($requiredEntries as $requiredEntry) {
            if (! is_string($requiredEntry) || ! is_array($manifest[$requiredEntry] ?? null)) {
                return false;
            }
        }

        foreach ($manifest as $entry) {
            if (! is_array($entry) || ! is_string($entry['file'] ?? null)) {
                return false;
            }

            foreach (['file', 'css', 'assets'] as $assetField) {
                if ($assetField === 'file') {
                    $assetPaths = [$entry[$assetField]];
                } else {
                    $assetPaths = $entry[$assetField] ?? [];
                    if (! is_array($assetPaths) || ! array_is_list($assetPaths)) {
                        return false;
                    }
                }

                foreach ($assetPaths as $assetPath) {
                    if (! $this->isContainedBuiltFrontendAsset($buildDirectory, $assetPath)) {
                        return false;
                    }
                }
            }

            foreach (['imports', 'dynamicImports'] as $dependencyField) {
                $dependencies = $entry[$dependencyField] ?? [];
                if (! is_array($dependencies) || ! array_is_list($dependencies)) {
                    return false;
                }

                foreach ($dependencies as $dependency) {
                    if (! is_string($dependency) || ! is_array($manifest[$dependency] ?? null)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function isContainedBuiltFrontendAsset(string $buildDirectory, mixed $assetPath): bool
    {
        if (! is_string($assetPath)
            || trim($assetPath) === ''
            || str_starts_with($assetPath, '/')
            || str_contains($assetPath, '\\')) {
            return false;
        }

        $segments = explode('/', $assetPath);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return false;
        }

        $resolvedAssetPath = realpath($buildDirectory.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments));
        if (! is_string($resolvedAssetPath) || ! is_file($resolvedAssetPath)) {
            return false;
        }

        $buildDirectoryPrefix = rtrim($buildDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($resolvedAssetPath, $buildDirectoryPrefix, strlen($buildDirectoryPrefix)) === 0
            : str_starts_with($resolvedAssetPath, $buildDirectoryPrefix);
    }

    private function hasConfiguredHsts(): bool
    {
        return is_numeric(config('opsdeck.security.hsts_max_age_seconds'))
            && (int) config('opsdeck.security.hsts_max_age_seconds') > 0;
    }

    private function hasSecureSessionCookies(): bool
    {
        return config('session.secure') === true
            && config('session.http_only') === true
            && config('session.same_site') === 'lax';
    }

    private function hasProductionLogging(): bool
    {
        $defaultChannelName = config('logging.default');
        if (! is_string($defaultChannelName) || trim($defaultChannelName) === '') {
            return false;
        }

        $defaultChannel = config('logging.channels.'.$defaultChannelName);
        if (! is_array($defaultChannel)) {
            return false;
        }

        $channelNames = $defaultChannel['driver'] === 'stack'
            ? $defaultChannel['channels'] ?? null
            : [$defaultChannelName];
        if (is_string($channelNames)) {
            $channelNames = explode(',', $channelNames);
        }

        if (! is_array($channelNames) || $channelNames === []) {
            return false;
        }

        $minimumLevel = array_search('warning', self::PRODUCTION_LOG_LEVELS, true);
        $hasRotatedOrExternalOutput = false;

        foreach ($channelNames as $channelName) {
            if (! is_string($channelName) || trim($channelName) === '') {
                return false;
            }

            $channel = config('logging.channels.'.trim($channelName));
            if (! is_array($channel) || ! is_string($channel['driver'] ?? null)) {
                return false;
            }

            $driver = $channel['driver'];
            if (! in_array($driver, self::PRODUCTION_LOG_OUTPUT_DRIVERS, true)) {
                return false;
            }

            $level = $channel['level'] ?? 'debug';
            if (! is_string($level)) {
                return false;
            }

            $levelRank = array_search(strtolower($level), self::PRODUCTION_LOG_LEVELS, true);
            if ($levelRank === false || $levelRank < $minimumLevel) {
                return false;
            }

            if (in_array($driver, ['daily', 'monthly'], true)) {
                if (! filled($channel['path'] ?? null) || ! $this->isPositiveInteger($channel['max_files'] ?? null)) {
                    return false;
                }

                $hasRotatedOrExternalOutput = true;
            } elseif ($driver === 'single') {
                return false;
            } elseif ($driver === 'monolog') {
                if (! $this->hasMonitorableMonologHandler($channel)) {
                    return false;
                }

                $hasRotatedOrExternalOutput = true;
            } else {
                $hasRotatedOrExternalOutput = true;
            }

            if ($driver === 'slack' && ! $this->hasHttpsWebhookUrl($channel['url'] ?? null)) {
                return false;
            }
        }

        return $hasRotatedOrExternalOutput;
    }

    /** @param array<string, mixed> $channel */
    private function hasMonitorableMonologHandler(array $channel): bool
    {
        $handler = $channel['handler'] ?? null;
        $handlerConfiguration = $channel['handler_with'] ?? [];
        if (! is_string($handler)
            || ! class_exists($handler)
            || ! is_subclass_of($handler, HandlerInterface::class)
            || ! is_array($handlerConfiguration)
            || is_a($handler, NullHandler::class, true)) {
            return false;
        }

        if (is_a($handler, RotatingFileHandler::class, true)) {
            return filled($handlerConfiguration['filename'] ?? null)
                && $this->isPositiveInteger($handlerConfiguration['maxFiles'] ?? null);
        }

        if (is_a($handler, StreamHandler::class, true)) {
            return in_array($handlerConfiguration['stream'] ?? null, ['php://stderr', 'php://stdout'], true);
        }

        if (is_a($handler, SocketHandler::class, true)) {
            $connectionTimeout = filter_var($handlerConfiguration['connectionTimeout'] ?? null, FILTER_VALIDATE_FLOAT);
            $writingTimeout = filter_var($handlerConfiguration['writingTimeout'] ?? null, FILTER_VALIDATE_FLOAT);

            return $this->hasTlsSocketConnection($handlerConfiguration['connectionString'] ?? null)
                && $connectionTimeout !== false
                && $connectionTimeout > 0
                && $writingTimeout !== false
                && $writingTimeout > 0;
        }

        return false;
    }

    private function hasTlsSocketConnection(mixed $connectionString): bool
    {
        $parsedUrl = is_string($connectionString) ? parse_url($connectionString) : false;
        if (! is_array($parsedUrl)) {
            return false;
        }

        $port = filter_var($parsedUrl['port'] ?? null, FILTER_VALIDATE_INT);

        return strtolower((string) ($parsedUrl['scheme'] ?? '')) === 'tls'
            && $this->isValidHostnameOrIp($parsedUrl['host'] ?? null)
            && $port !== false
            && $port >= 1
            && $port <= self::MAX_NETWORK_PORT
            && ! array_key_exists('user', $parsedUrl)
            && ! array_key_exists('pass', $parsedUrl)
            && ! array_key_exists('path', $parsedUrl)
            && ! array_key_exists('query', $parsedUrl)
            && ! array_key_exists('fragment', $parsedUrl);
    }

    private function hasProductionFailureAlert(?bool $mailTransportReady = null): bool
    {
        $adminEmails = config('opsdeck.platform_admin_emails');
        if (! is_array($adminEmails) || $adminEmails === []) {
            return false;
        }

        foreach ($adminEmails as $email) {
            if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
        }

        return ($mailTransportReady ?? $this->hasProductionMailTransport())
            && $this->usesSharedCache()
            && $this->isPositiveInteger(config('opsdeck.error_alerts.cooldown_seconds'));
    }

    private function hasAiCostConfiguration(AiModelPricing $aiModelPricing): bool
    {
        $exchangeRate = config('opsdeck.ai.usd_to_jpy_rate');
        $premiumBudget = config('opsdeck.ai.monthly_budget_jpy.premium');
        $modelPrices = config('opsdeck.ai.model_prices_per_million_tokens');
        if (! is_numeric($exchangeRate)
            || (float) $exchangeRate <= 0
            || ! is_numeric($premiumBudget)
            || (float) $premiumBudget <= 0
            || ! is_array($modelPrices)) {
            return false;
        }

        $models = array_unique([
            config('opsdeck.ai.models.fast'),
            config('opsdeck.ai.models.deep'),
        ]);
        foreach ($models as $model) {
            if (! is_string($model) || $model === '') {
                return false;
            }

            $pricing = $modelPrices[$model] ?? null;
            if (! $aiModelPricing->hasValidRates($pricing)) {
                return false;
            }
        }

        return true;
    }

    private function hasAiMappingConfiguration(): bool
    {
        $endpoint = config('opsdeck.ai.endpoint');
        $parts = is_string($endpoint) ? parse_url($endpoint) : false;

        return config('opsdeck.ai.enabled') === true
            && config('opsdeck.ai.provider') === 'openai'
            && $this->hasConfiguredAiApiKey()
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'api.openai.com'
            && ($parts['path'] ?? null) === '/v1/responses'
            && (! isset($parts['port']) || $parts['port'] === 443)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private function hasConfiguredAiApiKey(): bool
    {
        $apiKey = config('opsdeck.ai.api_key');
        if (! is_string($apiKey)) {
            return false;
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '' || str_contains($apiKey, '<') || str_contains($apiKey, '>')) {
            return false;
        }

        return preg_match('/\A(?:your|replace|change|placeholder|example|tbd|todo)(?:[\s:_-]|\z)/i', $apiKey) !== 1;
    }

    private function hasHttpsWebhookUrl(mixed $url): bool
    {
        $parsedUrl = is_string($url) ? parse_url($url) : false;

        return is_array($parsedUrl)
            && strtolower((string) ($parsedUrl['scheme'] ?? '')) === 'https'
            && filled($parsedUrl['host'] ?? null)
            && filled($parsedUrl['path'] ?? null);
    }

    private function hasPrivateSensitiveFileStorage(): bool
    {
        if (! $this->backupPathIsOutsidePublicDirectory()) {
            return false;
        }

        foreach (['opsdeck.import.disk', 'opsdeck.evidence.disk'] as $diskConfigKey) {
            $diskName = config($diskConfigKey);
            if (! is_string($diskName) || trim($diskName) === '') {
                return false;
            }

            if (app()->isProduction() && $diskName !== 'opsdeck_objects') {
                return false;
            }

            $disk = config('filesystems.disks.'.$diskName);
            if (! is_array($disk) || ! is_string($disk['driver'] ?? null)) {
                return false;
            }

            if (app()->isProduction() && $diskName === 'opsdeck_objects') {
                $expectedProductionBucket = config('opsdeck.storage.expected_production_bucket');
                $expectedDevelopmentBucket = config('opsdeck.storage.expected_development_bucket');
                if (! is_string($expectedProductionBucket)
                    || trim($expectedProductionBucket) === ''
                    || ! is_string($disk['bucket'] ?? null)
                    || trim($disk['bucket']) !== trim($expectedProductionBucket)
                    || ! is_string($expectedDevelopmentBucket)
                    || trim($expectedDevelopmentBucket) === ''
                    || trim($disk['bucket']) === trim($expectedDevelopmentBucket)) {
                    return false;
                }
            }

            if ($diskName === 'opsdeck_objects' && $disk['driver'] !== 's3') {
                return false;
            }

            if (($disk['visibility'] ?? null) === 'public') {
                return false;
            }

            $isPrivate = match ($disk['driver']) {
                'local' => $this->localDiskStoresOutsidePublicDirectory($disk),
                's3' => ($disk['visibility'] ?? null) === 'private'
                    && class_exists(AwsS3V3Adapter::class)
                    && ($diskName !== 'opsdeck_objects' || $this->opsDeckObjectsDiskConfigurationIsComplete($disk))
                    && $this->s3DiskHasPrivateAccessControlOptions($disk),
                'sftp' => class_exists(SftpAdapter::class)
                    && $this->sftpDiskPermissionsArePrivate($disk),
                default => false,
            };

            if (! $isPrivate) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $disk */
    private function opsDeckObjectsDiskConfigurationIsComplete(array $disk): bool
    {
        foreach (['key', 'secret', 'region', 'bucket'] as $requiredValue) {
            if (! is_string($disk[$requiredValue] ?? null) || trim($disk[$requiredValue]) === '') {
                return false;
            }
        }

        $endpoint = $disk['endpoint'] ?? null;
        $parsedEndpoint = is_string($endpoint) ? parse_url($endpoint) : false;

        $options = $disk['options'] ?? null;

        if (! is_array($parsedEndpoint)
            || strtolower((string) ($parsedEndpoint['scheme'] ?? '')) !== 'https'
            || ! is_string($parsedEndpoint['host'] ?? null)
            || trim($parsedEndpoint['host']) === ''
            || filled($disk['url'] ?? null)
            || ! is_array($options)
            || ($options['before_upload'] ?? null) !== [R2UploadCommandOptions::class, 'removeUnsupportedAcl']
            || ($options['mup_threshold'] ?? null) !== PHP_INT_MAX) {
            return false;
        }

        foreach (['ACL', 'GrantRead', 'GrantReadACP', 'GrantFullControl', 'GrantWrite', 'GrantWriteACP'] as $unsupportedOption) {
            if (array_key_exists($unsupportedOption, $options)) {
                return false;
            }
        }

        return true;
    }

    private function backupPathIsOutsidePublicDirectory(): bool
    {
        $backupPath = config('opsdeck.backup.path');
        $publicPath = realpath(public_path());
        if (! is_string($backupPath)
            || trim($backupPath) === ''
            || ! $this->isAbsoluteFilesystemPath($this->normalizeFilesystemPath($backupPath))
            || ! is_string($publicPath)) {
            return false;
        }

        $resolvedBackupPath = realpath($backupPath);
        if ($resolvedBackupPath === false) {
            $existingPath = $backupPath;
            $missingPathSegments = [];
            while (! is_dir($existingPath)) {
                $parentPath = dirname($existingPath);
                if ($parentPath === $existingPath) {
                    return false;
                }

                array_unshift($missingPathSegments, basename($existingPath));
                $existingPath = $parentPath;
            }

            $resolvedExistingPath = realpath($existingPath);
            if (! is_string($resolvedExistingPath)) {
                return false;
            }

            $resolvedBackupPath = rtrim($resolvedExistingPath, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .implode(DIRECTORY_SEPARATOR, $missingPathSegments);
        }

        $normalizedBackupPath = $this->normalizeFilesystemPath($resolvedBackupPath);
        $normalizedPublicPath = $this->normalizeFilesystemPath($publicPath);

        return $normalizedBackupPath !== $normalizedPublicPath
            && ! str_starts_with($normalizedBackupPath, $normalizedPublicPath.'/');
    }

    /** @param array<string, mixed> $disk */
    private function localDiskStoresOutsidePublicDirectory(array $disk): bool
    {
        if (($disk['visibility'] ?? 'private') !== 'private'
            || ($disk['directory_visibility'] ?? 'private') !== 'private') {
            return false;
        }

        $root = $disk['root'] ?? null;
        if (! is_string($root) || trim($root) === '') {
            return false;
        }

        $normalizedRoot = $this->normalizeFilesystemPath($root);
        if (! $this->isAbsoluteFilesystemPath($normalizedRoot)) {
            return false;
        }

        $resolvedRoot = $this->resolveFilesystemPathWithMissingSegments($root);
        $resolvedPublicPath = realpath(public_path());
        if (! is_string($resolvedRoot) || ! is_string($resolvedPublicPath)) {
            return false;
        }

        $normalizedRoot = $this->normalizeFilesystemPath($resolvedRoot);
        $normalizedPublicPath = $this->normalizeFilesystemPath($resolvedPublicPath);

        return $normalizedRoot !== $normalizedPublicPath
            && ! str_starts_with($normalizedRoot, $normalizedPublicPath.'/');
    }

    private function resolveFilesystemPathWithMissingSegments(string $path): ?string
    {
        $resolvedPath = realpath($path);
        if (is_string($resolvedPath)) {
            return $resolvedPath;
        }

        $existingPath = $path;
        $missingPathSegments = [];
        while (! is_dir($existingPath)) {
            $parentPath = dirname($existingPath);
            if ($parentPath === $existingPath) {
                return null;
            }

            array_unshift($missingPathSegments, basename($existingPath));
            $existingPath = $parentPath;
        }

        $resolvedExistingPath = realpath($existingPath);
        if (! is_string($resolvedExistingPath)) {
            return null;
        }

        if ($missingPathSegments === []) {
            return $resolvedExistingPath;
        }

        return rtrim($resolvedExistingPath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .implode(DIRECTORY_SEPARATOR, $missingPathSegments);
    }

    /** @param array<string, mixed> $disk */
    private function sftpDiskPermissionsArePrivate(array $disk): bool
    {
        $permissions = $disk['permissions'] ?? [];
        if (! is_array($permissions)) {
            return false;
        }

        $filePermissions = $permissions['file'] ?? [];
        $directoryPermissions = $permissions['dir'] ?? [];
        if (! is_array($filePermissions) || ! is_array($directoryPermissions)) {
            return false;
        }

        $privateFileMode = $filePermissions['private'] ?? 0600;
        $privateDirectoryMode = $directoryPermissions['private'] ?? 0700;

        return is_int($privateFileMode)
            && is_int($privateDirectoryMode)
            && ($privateFileMode & self::WORLD_PERMISSION_MASK) === 0
            && ($privateDirectoryMode & self::WORLD_PERMISSION_MASK) === 0;
    }

    /** @param array<string, mixed> $disk */
    private function s3DiskHasPrivateAccessControlOptions(array $disk): bool
    {
        if (filled($disk['url'] ?? null)) {
            return false;
        }

        $options = $disk['options'] ?? [];
        if (! is_array($options)) {
            return false;
        }

        if (array_key_exists('ACL', $options) && $options['ACL'] !== 'private') {
            return false;
        }

        foreach (['GrantRead', 'GrantReadACP', 'GrantFullControl', 'GrantWrite', 'GrantWriteACP'] as $grantOption) {
            if (array_key_exists($grantOption, $options)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeFilesystemPath(string $path): string
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath;
    }

    private function isAbsoluteFilesystemPath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || preg_match('/^[a-z]:\//i', $path) === 1;
    }

    private function hasValidApplicationKey(): bool
    {
        $key = config('app.key');
        $cipher = config('app.cipher');
        if (! is_string($key) || $key === '' || ! is_string($cipher) || $cipher === '') {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
            if ($key === false) {
                return false;
            }
        }

        return Encrypter::supported($key, $cipher);
    }

    private function canConnectToDatabase(DatabaseTlsConfiguration $databaseTls, ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration): bool
    {
        $connectionName = (string) config('database.default');
        try {
            $configuration = $resolvedDatabaseConfiguration->for($connectionName);
            if (app()->environment('production') && $databaseTls->issues($configuration) !== []) {
                $this->databaseConnectionIssue = 'remote_database_requires_verified_tls';

                return false;
            }

            DB::connection()->getPdo();

            return true;
        } catch (Throwable $exception) {
            $this->databaseConnectionIssue = $this->classifyDatabaseConnectionIssue($exception);

            return false;
        }
    }

    private function classifyDatabaseConnectionIssue(Throwable $exception): string
    {
        do {
            $exceptionMessage = strtolower($exception->getMessage());
            if (str_contains($exceptionMessage, 'could not find driver')
                || str_contains($exceptionMessage, 'driver not found')) {
                return 'pdo_driver_unavailable';
            }

            if ($exception instanceof \PDOException) {
                $driverCode = is_array($exception->errorInfo ?? null)
                    ? (int) ($exception->errorInfo[1] ?? 0)
                    : 0;
                $sqlState = is_array($exception->errorInfo ?? null)
                    ? (string) ($exception->errorInfo[0] ?? '')
                    : (string) $exception->getCode();

                return match (true) {
                    in_array($driverCode, [2002, 2003, 2005], true), str_starts_with($sqlState, '08') => 'endpoint_unreachable',
                    $driverCode === 1044 => 'database_access_denied',
                    $driverCode === 1045, $sqlState === '28000' => 'credentials_rejected',
                    $driverCode === 1049, $sqlState === '3D000' => 'database_not_found',
                    default => 'unclassified',
                };
            }

            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return 'unclassified';
    }

    private function hasDatabaseBackupTool(DatabaseDumpBinaryLocator $dumpBinaryLocator, DatabaseClientVersionDetector $clientVersionDetector, ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration): bool
    {
        $connectionName = (string) config('database.default');
        try {
            $driver = (string) ($resolvedDatabaseConfiguration->for($connectionName)['driver'] ?? '');
        } catch (Throwable) {
            return false;
        }
        $dumpBinary = $driver === 'sqlite' ? null : $dumpBinaryLocator->find($driver);
        $databaseToolIsReady = $driver === 'sqlite' || $dumpBinary !== null;
        if ($dumpBinary !== null) {
            try {
                $clientVersionDetector->detect($dumpBinary);
            } catch (Throwable) {
                $databaseToolIsReady = false;
            }
        }

        return $databaseToolIsReady && $this->backupEncryptionIssues === [];
    }

    private function hasRequiredReleaseScope(): bool
    {
        if (config('opsdeck.release_features.storage_addon') !== false) {
            return false;
        }

        foreach (['google_sheets', 'impact_report', 'questionnaire_ai_draft', 'referrals', 'work_time_tracking'] as $feature) {
            if (config('opsdeck.release_features.'.$feature) === true) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function backupEncryptionConfigurationIssues(): array
    {
        $issues = [];
        if (config('opsdeck.backup.encryption_enabled') !== true) {
            return $issues;
        }

        if (! extension_loaded('sodium')) {
            $issues[] = 'sodium_extension_missing';
        }

        $encodedEncryptionKey = config('opsdeck.backup.encryption_key');
        if (! is_string($encodedEncryptionKey) || trim($encodedEncryptionKey) === '') {
            $issues[] = 'encryption_key_missing';

            return $issues;
        }
        $encryptionKey = base64_decode($encodedEncryptionKey, true);
        if (! is_string($encryptionKey)) {
            $issues[] = 'encryption_key_invalid';

            return $issues;
        }
        if (extension_loaded('sodium') && strlen($encryptionKey) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            $issues[] = 'encryption_key_invalid';
        }

        return $issues;
    }

    private function hasDatabaseRestoreTool(DatabaseDumpBinaryLocator $dumpBinaryLocator, DatabaseClientVersionDetector $clientVersionDetector, ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration): bool
    {
        $connectionName = (string) config('database.default');
        try {
            $driver = (string) ($resolvedDatabaseConfiguration->for($connectionName)['driver'] ?? '');
        } catch (Throwable) {
            return false;
        }
        $restoreClient = $driver === 'sqlite' ? null : $dumpBinaryLocator->findRestoreClient($driver);
        if ($restoreClient === null) {
            return $driver === 'sqlite';
        }

        try {
            $clientVersionDetector->detect($restoreClient);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function usesDurableQueue(): bool
    {
        if ($this->durableQueueReadiness !== null) {
            return $this->durableQueueReadiness;
        }

        $connection = config('queue.connections.'.config('queue.default'));
        if (! is_array($connection)) {
            $this->queueReadinessIssues = ['queue_connection_missing_or_invalid'];

            return $this->durableQueueReadiness = false;
        }

        $queueIsDurable = match ($connection['driver'] ?? null) {
            'database' => $this->hasDatabaseQueueConfiguration($connection),
            'redis' => filled($connection['queue'] ?? null)
                && filled($connection['connection'] ?? null)
                && is_array(config('database.redis.'.$connection['connection']))
                && $this->isPositiveInteger($connection['retry_after'] ?? null),
            'sqs' => $this->hasSqsQueueConfiguration($connection),
            'beanstalkd' => class_exists(Pheanstalk::class)
                && $this->isValidHostnameOrIp($connection['host'] ?? null)
                && filled($connection['queue'] ?? null)
                && $this->isPositiveInteger($connection['retry_after'] ?? null),
            default => false,
        };
        if (! $queueIsDurable) {
            $this->queueReadinessIssues[] = 'queue_connection_not_durable_or_misconfigured';

            return $this->durableQueueReadiness = false;
        }
        if (! $this->hasDatabaseFailedJobsStorage()) {
            $this->queueReadinessIssues[] = 'failed_jobs_storage_not_ready';
        }

        $queueHealth = app(QueueHealthService::class);
        try {
            $importTimeoutStatus = $queueHealth->importTimeoutStatus();
        } catch (Throwable) {
            $importTimeoutStatus = 'unknown';
        }
        if ($importTimeoutStatus !== 'ok') {
            $this->queueReadinessIssues[] = match ($importTimeoutStatus) {
                'invalid', 'not_applicable' => 'queue_job_timeout_configuration_not_ready',
                default => 'queue_job_timeout_status_unverified',
            };
        }

        try {
            $workerStatus = $queueHealth->workerStatus();
        } catch (Throwable) {
            $workerStatus = 'unknown';
        }
        if ($workerStatus !== 'ok') {
            $this->queueReadinessIssues[] = match ($workerStatus) {
                'stale' => 'queue_worker_heartbeat_missing_or_stale',
                'invalid', 'not_applicable' => 'durable_queue_worker_required',
                default => 'queue_worker_status_unverified',
            };
        }

        return $this->durableQueueReadiness = $this->queueReadinessIssues === [];
    }

    private function hasDatabaseFailedJobsStorage(): bool
    {
        $failedJobs = config('queue.failed');
        if (! is_array($failedJobs)) {
            return false;
        }

        $driver = $failedJobs['driver'] ?? null;
        $table = $failedJobs['table'] ?? null;
        $connectionName = $failedJobs['database'] ?? null;
        $defaultConnectionName = config('database.default');

        return $driver === 'database-uuids'
            && $table === 'failed_jobs'
            && is_string($connectionName)
            && $connectionName === $defaultConnectionName
            && $this->databaseTableExists($connectionName, $table);
    }

    /** @param array<string, mixed> $connection */
    private function hasDatabaseQueueConfiguration(array $connection): bool
    {
        $table = $connection['table'] ?? null;
        $queue = $connection['queue'] ?? null;
        $connectionName = $connection['connection'] ?? config('database.default');

        return is_string($table)
            && trim($table) !== ''
            && is_string($queue)
            && trim($queue) !== ''
            && is_string($connectionName)
            && trim($connectionName) !== ''
            && $this->isPositiveInteger($connection['retry_after'] ?? null)
            && $this->databaseTableExists($connectionName, $table);
    }

    private function databaseTableExists(string $connectionName, string $table): bool
    {
        try {
            return $this->databaseSchemaBuilder($connectionName)->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function databaseSchemaBuilder(string $connectionName): Builder
    {
        return $this->databaseSchemaBuilders[$connectionName] ??= DB::connection($connectionName)->getSchemaBuilder();
    }

    private function isPositiveInteger(mixed $value): bool
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer !== false && $integer >= self::MIN_QUEUE_RETRY_SECONDS;
    }

    /** @param array<string, mixed> $connection */
    private function hasSqsQueueConfiguration(array $connection): bool
    {
        $prefix = $connection['prefix'] ?? null;
        $queue = $connection['queue'] ?? null;
        $region = $connection['region'] ?? null;
        $url = is_string($prefix) ? parse_url($prefix) : false;

        return class_exists(SqsClient::class)
            && is_array($url)
            && strtolower((string) ($url['scheme'] ?? '')) === 'https'
            && $this->isValidHostnameOrIp($url['host'] ?? null)
            && ! str_contains((string) ($url['path'] ?? ''), 'your-account-id')
            && is_string($queue)
            && trim($queue) !== ''
            && is_string($region)
            && trim($region) !== '';
    }

    private function isValidHostnameOrIp(mixed $host): bool
    {
        return is_string($host)
            && trim($host) !== ''
            && (filter_var($host, FILTER_VALIDATE_IP) !== false
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
    }

    private function usesSharedCache(): bool
    {
        if ($this->sharedCacheReadiness !== null) {
            return $this->sharedCacheReadiness;
        }

        $store = config('cache.stores.'.config('cache.default'));
        if (! is_array($store)) {
            return $this->sharedCacheReadiness = false;
        }

        $storeIsConfigured = match ($store['driver'] ?? null) {
            'database' => $this->hasDatabaseCacheTables($store),
            'redis' => $this->hasRedisCacheConfiguration($store),
            'memcached' => $this->hasMemcachedConfiguration($store),
            'dynamodb' => class_exists(DynamoDbClient::class)
                && filled($store['table'] ?? null)
                && filled($store['region'] ?? null),
            default => false,
        };

        return $this->sharedCacheReadiness = $storeIsConfigured && $this->canAcquireAndReleaseCacheLock();
    }

    private function canAcquireAndReleaseCacheLock(): bool
    {
        /** @var Lock|null $lock */
        $lock = null;
        $lockAcquired = false;

        try {
            $lock = Cache::store((string) config('cache.default'))
                ->lock('opsdeck:deployment-readiness:'.Str::uuid(), self::CACHE_LOCK_TTL_SECONDS);
            $lockAcquired = $lock->get();
            if (! $lockAcquired) {
                return false;
            }

            $lockReleased = $lock->release();
            if ($lockReleased) {
                $lockAcquired = false;
            }

            return $lockReleased;
        } catch (Throwable) {
            return false;
        } finally {
            if ($lockAcquired && $lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The lock has a short TTL if the backing store cannot release it.
                }
            }
        }
    }

    /** @param array<string, mixed> $store */
    private function hasDatabaseCacheTables(array $store): bool
    {
        $cacheTable = $store['table'] ?? null;
        $lockTable = $store['lock_table'] ?? 'cache_locks';
        $connectionName = $store['connection'] ?? config('database.default');
        $lockConnectionName = $store['lock_connection'] ?? $connectionName;

        if (! is_string($cacheTable) || trim($cacheTable) === ''
            || ! is_string($lockTable) || trim($lockTable) === ''
            || ! is_string($connectionName) || trim($connectionName) === ''
            || ! is_string($lockConnectionName) || trim($lockConnectionName) === '') {
            return false;
        }

        try {
            $cacheSchema = $this->databaseSchemaBuilder($connectionName);
            if (! $cacheSchema->hasTable($cacheTable)) {
                return false;
            }

            $lockSchema = $lockConnectionName === $connectionName
                ? $cacheSchema
                : $this->databaseSchemaBuilder($lockConnectionName);

            return $lockSchema->hasTable($lockTable);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $store */
    private function hasRedisCacheConfiguration(array $store): bool
    {
        $cacheConnection = $store['connection'] ?? 'default';
        $lockConnection = $store['lock_connection'] ?? $cacheConnection;
        $client = config('database.redis.client');

        if (! is_string($client)
            || ! in_array($client, ['phpredis', 'predis'], true)
            || ($client === 'phpredis' && ! class_exists(\Redis::class))
            || ($client === 'predis' && ! class_exists(Client::class))) {
            return false;
        }

        return $this->isConfiguredRedisConnection($cacheConnection)
            && $this->isConfiguredRedisConnection($lockConnection);
    }

    private function isConfiguredRedisConnection(mixed $connectionName): bool
    {
        if (! is_string($connectionName) || trim($connectionName) === '') {
            return false;
        }

        $connection = config('database.redis.'.$connectionName);
        if (! is_array($connection)) {
            return false;
        }

        $url = $connection['url'] ?? null;
        if (is_string($url) && trim($url) !== '') {
            $parts = parse_url($url);

            return is_array($parts)
                && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['redis', 'rediss'], true)
                && $this->isValidHostnameOrIp($parts['host'] ?? null);
        }

        return $this->isValidHostnameOrIp($connection['host'] ?? null)
            && $this->isValidNetworkPort($connection['port'] ?? null);
    }

    /** @param array<string, mixed> $store */
    private function hasMemcachedConfiguration(array $store): bool
    {
        $servers = $store['servers'] ?? null;
        if (! class_exists(\Memcached::class) || ! is_array($servers) || $servers === []) {
            return false;
        }

        foreach ($servers as $server) {
            if (! is_array($server)
                || ! $this->isValidHostnameOrIp($server['host'] ?? null)
                || ! $this->isValidNetworkPort($server['port'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function isValidNetworkPort(mixed $port): bool
    {
        $integer = filter_var($port, FILTER_VALIDATE_INT);

        return $integer !== false
            && $integer >= self::MIN_QUEUE_RETRY_SECONDS
            && $integer <= self::MAX_NETWORK_PORT;
    }

    private function hasProductionMailTransport(): bool
    {
        $this->mailReadinessIssue = $this->productionMailReadinessIssue();

        return $this->mailReadinessIssue === null;
    }

    private function productionMailReadinessIssue(): ?string
    {
        $fromAddress = config('mail.from.address');
        $adminFromAddress = config('opsdeck.admin_mail_from_address');
        $supportAddress = config('opsdeck.support_email');

        if (! is_string($fromAddress)
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($adminFromAddress)
            || filter_var($adminFromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return 'sender_address_invalid';
        }

        if (! is_string($supportAddress) || filter_var($supportAddress, FILTER_VALIDATE_EMAIL) === false) {
            return 'support_address_invalid';
        }

        if (strcasecmp($supportAddress, $fromAddress) === 0 || strcasecmp($supportAddress, $adminFromAddress) === 0) {
            return 'support_address_must_differ_from_sender';
        }

        if (! $this->hasConfiguredMailDeliveryBackoff()) {
            return 'mail_retry_backoff_invalid';
        }

        if (config('mail.default') === 'smtp') {
            if (! $this->hasConfiguredResendSmtpTransport()) {
                return 'smtp_configuration_incomplete';
            }
        } elseif (config('mail.default') !== 'resend_idempotent') {
            return 'mailer_must_be_idempotent_resend';
        }

        if (! $this->hasConfiguredMailTransport('resend_idempotent')
            || ! $this->hasConfiguredIdempotentResendTransport()) {
            return 'resend_idempotency_configuration_incomplete';
        }

        if (! $this->hasVerifiedResendSendingDomain()) {
            return 'resend_sending_domain_unverified_or_unavailable';
        }

        return null;
    }

    private function hasVerifiedResendSendingDomain(): bool
    {
        if ($this->resendSendingDomainReadiness !== null) {
            return $this->resendSendingDomainReadiness;
        }

        $fromAddresses = [config('mail.from.address'), config('opsdeck.admin_mail_from_address')];
        $apiKey = config('mail.resend_domain_check_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return $this->resendSendingDomainReadiness = false;
        }

        $senderDomains = [];
        foreach ($fromAddresses as $fromAddress) {
            if (! is_string($fromAddress) || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
                return $this->resendSendingDomainReadiness = false;
            }

            $senderDomain = strtolower(substr(strrchr($fromAddress, '@') ?: '', 1));
            if ($senderDomain === '') {
                return $this->resendSendingDomainReadiness = false;
            }

            $senderDomains[$senderDomain] = true;
        }

        foreach (array_keys($senderDomains) as $senderDomain) {
            if (! $this->resendDomainIsVerified($senderDomain, $apiKey)) {
                return $this->resendSendingDomainReadiness = false;
            }
        }

        return $this->resendSendingDomainReadiness = true;
    }

    private function resendDomainIsVerified(string $senderDomain, string $apiKey): bool
    {
        $after = null;
        do {
            $parameters = ['limit' => self::RESEND_DOMAIN_PAGE_SIZE];
            if ($after !== null) {
                $parameters['after'] = $after;
            }

            try {
                $response = Http::withToken($apiKey)
                    ->connectTimeout(self::RESEND_DOMAIN_CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::RESEND_DOMAIN_TIMEOUT_SECONDS)
                    ->get('https://api.resend.com/domains', $parameters);
            } catch (Throwable) {
                return false;
            }

            $domains = $response->json('data');
            if (! $response->successful() || ! is_array($domains)) {
                return false;
            }

            foreach ($domains as $domain) {
                if (! is_array($domain) || ! is_string($domain['name'] ?? null)
                    || strtolower($domain['name']) !== $senderDomain) {
                    continue;
                }

                return ($domain['status'] ?? null) === 'verified'
                    && is_array($domain['capabilities'] ?? null)
                    && ($domain['capabilities']['sending'] ?? null) === 'enabled';
            }

            if ($response->json('has_more') !== true) {
                return false;
            }

            $lastDomain = end($domains);
            $lastDomainId = is_array($lastDomain) ? ($lastDomain['id'] ?? null) : null;
            if (! is_string($lastDomainId) || $lastDomainId === '' || $lastDomainId === $after) {
                return false;
            }

            $after = $lastDomainId;
        } while (true);
    }

    private function hasConfiguredMailDeliveryBackoff(): bool
    {
        $backoff = config('mail.delivery_job_backoff_seconds');

        if (! is_array($backoff) || $backoff === [] || ! array_is_list($backoff)) {
            return false;
        }

        foreach ($backoff as $seconds) {
            if (! $this->isPositiveInteger($seconds)) {
                return false;
            }
        }

        return true;
    }

    private function hasConfiguredIdempotentResendTransport(): bool
    {
        $mailer = config('mail.mailers.resend_idempotent');

        return (bool) config('mail.mailers.resend_idempotent.enabled')
            && is_array($mailer)
            && ($mailer['transport'] ?? null) === 'resend_idempotent'
            && is_string($mailer['key'] ?? null)
            && trim($mailer['key']) !== ''
            && $this->isPositiveInteger($mailer['timeout'] ?? null);
    }

    private function hasConfiguredResendSmtpTransport(): bool
    {
        $mailer = config('mail.mailers.smtp');

        return is_array($mailer)
            && ($mailer['transport'] ?? null) === 'smtp'
            && ($mailer['host'] ?? null) === self::RESEND_SMTP_HOST
            && (int) ($mailer['port'] ?? 0) === self::RESEND_SMTP_PORT
            && ($mailer['scheme'] ?? null) === 'smtp'
            && ($mailer['username'] ?? null) === 'resend'
            && is_string($mailer['password'] ?? null)
            && $mailer['password'] !== ''
            && $this->hasValidSmtpConfiguration($mailer);
    }

    /** @param array<string, true> $visitedMailers */
    private function hasConfiguredMailTransport(string $mailerName, array $visitedMailers = []): bool
    {
        if ($mailerName === '' || isset($visitedMailers[$mailerName])) {
            return false;
        }

        $visitedMailers[$mailerName] = true;
        $mailer = config('mail.mailers.'.$mailerName);
        if (! is_array($mailer)) {
            return false;
        }

        return match ($mailer['transport'] ?? null) {
            'smtp' => $this->hasValidSmtpConfiguration($mailer),
            'sendmail' => is_string($mailer['path'] ?? null) && trim($mailer['path']) !== '',
            'mailgun' => filled(config('services.mailgun.domain')) && filled(config('services.mailgun.secret')),
            'ses' => $this->hasSesMailConfiguration(SesClient::class),
            'ses-v2' => $this->hasSesMailConfiguration(SesV2Client::class),
            'postmark' => filled(config('services.postmark.key')),
            'resend' => filled(config('services.resend.key')),
            'resend_idempotent' => $this->hasConfiguredIdempotentResendTransport(),
            'failover', 'roundrobin' => $this->hasConfiguredChildMailers($mailer, $visitedMailers),
            default => false,
        };
    }

    /** @param array<string, mixed> $mailer */
    private function hasValidSmtpConfiguration(array $mailer): bool
    {
        $host = $mailer['host'] ?? null;
        $port = $mailer['port'] ?? null;
        $username = $mailer['username'] ?? null;
        $password = $mailer['password'] ?? null;
        $isValidHost = is_string($host)
            && trim($host) !== ''
            && (filter_var($host, FILTER_VALIDATE_IP) !== false
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
        $hasUsername = is_string($username) && trim($username) !== '';
        $hasPassword = is_string($password) && $password !== '';
        $hasValidCredentials = ($username === null || is_string($username))
            && ($password === null || is_string($password))
            && $hasUsername === $hasPassword;

        return $isValidHost
            && (is_int($port) || (is_string($port) && ctype_digit($port)))
            && (int) $port >= 1
            && (int) $port <= 65535
            && $hasValidCredentials;
    }

    private function hasSesMailConfiguration(string $clientClass): bool
    {
        $region = config('services.ses.region');
        $accessKey = config('services.ses.key');
        $secretKey = config('services.ses.secret');
        $hasCompleteStaticCredentials = filled($accessKey) && filled($secretKey);
        $hasNoPartialStaticCredentials = ! filled($accessKey) && ! filled($secretKey);

        return class_exists($clientClass)
            && is_string($region)
            && trim($region) !== ''
            && ($hasCompleteStaticCredentials || $hasNoPartialStaticCredentials);
    }

    /** @param array<string, mixed> $mailer
     * @param  array<string, true>  $visitedMailers
     */
    private function hasConfiguredChildMailers(array $mailer, array $visitedMailers): bool
    {
        $mailers = $mailer['mailers'] ?? null;
        if (! is_array($mailers) || $mailers === []) {
            return false;
        }

        foreach ($mailers as $childMailerName) {
            if (! is_string($childMailerName) || ! $this->hasConfiguredMailTransport($childMailerName, $visitedMailers)) {
                return false;
            }
        }

        return true;
    }

    private function hasProductionStripeCredentials(): bool
    {
        $this->stripeReadinessIssue = $this->productionStripeReadinessIssue();

        return $this->stripeReadinessIssue === null;
    }

    private function productionStripeReadinessIssue(): ?string
    {
        $secret = (string) config('services.stripe.secret');
        $price = (string) config('services.stripe.prices.premium');
        $storageAddonEnabled = (bool) config('opsdeck.release_features.storage_addon');
        $storageAddonProductTaxCode = null;
        $webhookSecret = (string) config('services.stripe.webhook_secret');
        if (! str_starts_with($secret, 'sk_live_')
            || ! str_starts_with($price, 'price_')
            || ! str_starts_with($webhookSecret, 'whsec_')) {
            return 'live_secret_price_or_webhook_key_invalid';
        }

        try {
            $stripePrice = Http::withBasicAuth($secret, '')
                ->connectTimeout(self::STRIPE_PRICE_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::STRIPE_PRICE_TIMEOUT_SECONDS)
                ->get('https://api.stripe.com/v1/prices/'.rawurlencode($price), ['expand[]' => 'product']);
        } catch (Throwable) {
            return 'stripe_price_api_unavailable';
        }

        if (! $stripePrice->successful()) {
            return 'stripe_price_api_rejected_request';
        }

        $premiumPriceTaxBehavior = $stripePrice->json('tax_behavior');
        $priceIsReady = $stripePrice->json('id') === $price
            && $stripePrice->json('active') === true
            && $stripePrice->json('type') === 'recurring'
            && $stripePrice->json('billing_scheme') === 'per_unit'
            && $stripePrice->json('currency') === 'jpy'
            && $stripePrice->json('unit_amount') === (int) config('opsdeck.plans.premium.monthly_price_jpy')
            && in_array($premiumPriceTaxBehavior, ['exclusive', 'unspecified', null], true)
            && $stripePrice->json('recurring.interval') === 'month'
            && (int) $stripePrice->json('recurring.interval_count') === 1;

        if (! $priceIsReady) {
            return 'premium_price_configuration_mismatch';
        }

        try {
            $portalConfigurations = Http::withBasicAuth($secret, '')
                ->connectTimeout(self::STRIPE_PRICE_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::STRIPE_PRICE_TIMEOUT_SECONDS)
                ->get('https://api.stripe.com/v1/billing_portal/configurations', [
                    'active' => 'true',
                    'is_default' => 'true',
                    'limit' => self::STRIPE_PORTAL_CONFIGURATION_LIMIT,
                ]);
        } catch (Throwable) {
            return 'stripe_customer_portal_configuration_unavailable';
        }

        if (! $portalConfigurations->successful()) {
            return 'stripe_customer_portal_configuration_api_rejected_request';
        }

        $defaultPortalConfiguration = $portalConfigurations->json('data.0');
        $cancellationConfiguration = data_get($defaultPortalConfiguration, 'features.subscription_cancel');
        if (! is_array($defaultPortalConfiguration)
            || $portalConfigurations->json('has_more') === true
            || ($defaultPortalConfiguration['active'] ?? null) !== true
            || ($defaultPortalConfiguration['is_default'] ?? null) !== true
            || ($defaultPortalConfiguration['livemode'] ?? null) !== true
            || ! is_array($cancellationConfiguration)
            || ($cancellationConfiguration['enabled'] ?? null) !== true
            || ($cancellationConfiguration['mode'] ?? null) !== self::STRIPE_PORTAL_CANCELLATION_MODE
            || ($cancellationConfiguration['proration_behavior'] ?? null) !== self::STRIPE_PORTAL_CANCELLATION_PRORATION_BEHAVIOR) {
            return 'stripe_customer_portal_cancellation_configuration_mismatch';
        }

        if ($storageAddonEnabled) {
            $storageAddonMaximum = config('opsdeck.storage_quotas.storage_addon_max_gib');
            if (! is_int($storageAddonMaximum) || $storageAddonMaximum < 1) {
                return 'storage_addon_capacity_limit_incomplete';
            }

            $storageAddonPriceId = (string) config('services.stripe.prices.storage_addon_gib');
            if (! str_starts_with($storageAddonPriceId, 'price_')) {
                return 'storage_addon_price_configuration_incomplete';
            }

            try {
                $storageAddonPrice = Http::withBasicAuth($secret, '')
                    ->connectTimeout(self::STRIPE_PRICE_CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::STRIPE_PRICE_TIMEOUT_SECONDS)
                    ->get('https://api.stripe.com/v1/prices/'.rawurlencode($storageAddonPriceId), ['expand[]' => 'product']);
            } catch (Throwable) {
                return 'storage_addon_price_api_unavailable';
            }

            if (! $storageAddonPrice->successful()) {
                return 'storage_addon_price_api_rejected_request';
            }

            $storageAddonPriceIsReady = $storageAddonPrice->json('id') === $storageAddonPriceId
                && $storageAddonPrice->json('active') === true
                && $storageAddonPrice->json('type') === 'recurring'
                && $storageAddonPrice->json('billing_scheme') === 'per_unit'
                && $storageAddonPrice->json('currency') === 'jpy'
                && $storageAddonPrice->json('unit_amount') === (int) config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy')
                && $storageAddonPrice->json('tax_behavior') === 'exclusive'
                && $storageAddonPrice->json('recurring.interval') === 'month'
                && (int) $storageAddonPrice->json('recurring.interval_count') === 1;

            if (! $storageAddonPriceIsReady) {
                return 'storage_addon_price_configuration_mismatch';
            }

            $storageAddonProductTaxCode = $storageAddonPrice->json('product.tax_code');
        }

        try {
            $taxSettings = Http::withBasicAuth($secret, '')
                ->connectTimeout(self::STRIPE_PRICE_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::STRIPE_PRICE_TIMEOUT_SECONDS)
                ->get('https://api.stripe.com/v1/tax/settings');
        } catch (Throwable) {
            return 'stripe_tax_settings_unavailable';
        }

        $productTaxCode = $stripePrice->json('product.tax_code');
        $defaultTaxCode = $taxSettings->json('defaults.tax_code');
        $hasDefaultTaxCode = is_string($defaultTaxCode) && trim($defaultTaxCode) !== '';
        $hasPremiumTaxCode = (is_string($productTaxCode) && trim($productTaxCode) !== '') || $hasDefaultTaxCode;
        if (! $taxSettings->successful() || $taxSettings->json('status') !== 'active' || ! $hasPremiumTaxCode) {
            return 'stripe_tax_settings_inactive_or_tax_code_missing';
        }

        $defaultTaxBehavior = $taxSettings->json('defaults.tax_behavior');
        if (! $this->hasExclusiveEffectiveTaxBehavior($premiumPriceTaxBehavior, $defaultTaxBehavior)) {
            return 'stripe_tax_behavior_not_exclusive';
        }

        if ($storageAddonEnabled
            && ! (is_string($storageAddonProductTaxCode) && trim($storageAddonProductTaxCode) !== '')
            && ! $hasDefaultTaxCode) {
            return 'storage_addon_tax_code_missing';
        }

        return app(StripeTaxRegistrationReadiness::class)->hasActiveRegistration($secret, 'JP')
            ? null
            : 'japan_tax_registration_unavailable_or_inactive';
    }

    private function providerReadinessIssueMessage(string $issue): string
    {
        return match ($issue) {
            'sender_address_invalid' => 'MAIL_FROM_ADDRESSまたはOPSDECK_ADMIN_MAIL_FROM_ADDRESSが有効なメールアドレスではありません。',
            'support_address_invalid' => 'CONTACT_TO_ADDRESSが有効な問い合わせ先メールアドレスではありません。',
            'support_address_must_differ_from_sender' => 'CONTACT_TO_ADDRESSは送信元アドレスと別のアドレスにしてください。',
            'mail_retry_backoff_invalid' => 'MAIL_DELIVERY_JOB_BACKOFF_SECONDSに正の整数をカンマ区切りで設定してください。',
            'smtp_configuration_incomplete' => 'Resend SMTPを使う場合はMAIL_HOST=smtp.resend.com、MAIL_PORT=587、MAIL_SCHEME=smtp、MAIL_USERNAME=resend、MAIL_PASSWORDを確認してください。認証情報の値は表示しません。',
            'mailer_must_be_idempotent_resend' => 'MAIL_MAILERはsmtpまたはresend_idempotentを設定してください。',
            'resend_idempotency_configuration_incomplete' => 'RESEND_IDEMPOTENT_MAIL_ENABLED=trueとMAIL_PASSWORD（Resend APIキー）、RESEND_API_TIMEOUTを確認してください。MAIL_*の名前は変更不要です。',
            'resend_sending_domain_unverified_or_unavailable' => 'ドメイン確認用APIキーと、送信元ドメインのverified・sending enabled状態を確認してください。',
            'live_secret_price_or_webhook_key_invalid' => 'OPSDECK_STRIPE_ENVIRONMENT=liveと、OPSDECK_STRIPE_LIVE_SECRET_KEY / OPSDECK_STRIPE_LIVE_PRICE_PREMIUM / OPSDECK_STRIPE_LIVE_WEBHOOK_SECRETの組み合わせを確認してください。追加容量を公開する場合はOPSDECK_STRIPE_LIVE_PRICE_STORAGE_ADDON_GIBも確認してください。値は表示しません。',
            'stripe_price_api_unavailable' => 'Stripe Price APIへ接続できませんでした。ネットワークとAPI利用可否を確認してください。',
            'stripe_price_api_rejected_request' => 'Stripe Price APIが要求を受け付けませんでした。SecretとPrice IDの組み合わせを確認してください。',
            'premium_price_configuration_mismatch' => sprintf(
                'Premium Priceが有効な月額JPY %s円（税別）になっているか確認してください。',
                number_format((int) config('opsdeck.plans.premium.monthly_price_jpy')),
            ),
            'stripe_customer_portal_configuration_unavailable' => 'Stripe Customer Portal設定を読み取れませんでした。ネットワークを確認してください。',
            'stripe_customer_portal_configuration_api_rejected_request' => 'Stripe Customer Portal設定の読取りが拒否されました。Stripe APIキーの権限とモードを確認してください。',
            'stripe_customer_portal_cancellation_configuration_mismatch' => 'Stripe DashboardのCustomer Portal既定設定で、解約を有効化し「期間末に解約」「按分なし」にしてください。',
            'stripe_tax_behavior_not_exclusive' => 'Premium Priceの税区分、またはStripe Taxの既定税区分を外税（exclusive）にしてください。Priceがunspecifiedの場合はStripe Taxの既定値が使われます。',
            'storage_addon_capacity_limit_incomplete' => 'OPSDECK_STORAGE_ADDON_MAX_GIBに1社あたりの追加容量上限（最大48GiB）を設定してください。全社合計はOPSDECK_PLATFORM_STORAGE_LIMIT_MIBの枠内で制御されます。',
            'storage_addon_price_configuration_incomplete' => 'OPSDECK_STORAGE_ADDON_MAX_GIBを設定する場合は、現在のStripeモードに対応するOPSDECK_STRIPE_TEST_PRICE_STORAGE_ADDON_GIBまたはOPSDECK_STRIPE_LIVE_PRICE_STORAGE_ADDON_GIBを設定してください。値は表示しません。',
            'storage_addon_price_api_unavailable' => '追加容量用PriceをStripe APIで確認できませんでした。ネットワークとAPI利用可否を確認してください。',
            'storage_addon_price_api_rejected_request' => '追加容量用PriceのStripe API要求が拒否されました。SecretとPrice IDの組み合わせを確認してください。',
            'storage_addon_price_configuration_mismatch' => sprintf(
                '追加容量用Priceが有効な月額JPY %s円（税別）の従量価格になっているか確認してください。',
                number_format((int) config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy')),
            ),
            'stripe_tax_settings_unavailable' => 'Stripe Tax設定を読み取れませんでした。API権限とネットワークを確認してください。',
            'stripe_tax_settings_inactive_or_tax_code_missing' => 'Stripe Taxを有効化し、Premium商品または既定の税コードを設定してください。',
            'storage_addon_tax_code_missing' => '追加容量商品に税コードを設定するか、Stripe Taxに既定の税コードを設定してください。',
            'japan_tax_registration_unavailable_or_inactive' => 'Stripeで日本の有効な税務登録を確認できませんでした。Tax設定とAPI権限を確認してください。',
            default => '設定内容を確認してください。秘密値やAPI応答本文は表示しません。',
        };
    }

    private function hasExclusiveEffectiveTaxBehavior(mixed $priceTaxBehavior, mixed $defaultTaxBehavior): bool
    {
        if ($priceTaxBehavior === 'exclusive') {
            return true;
        }

        return in_array($priceTaxBehavior, ['unspecified', null], true)
            && $defaultTaxBehavior === 'exclusive';
    }
}
