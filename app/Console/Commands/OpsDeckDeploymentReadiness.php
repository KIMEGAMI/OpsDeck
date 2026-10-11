<?php

namespace App\Console\Commands;

use App\Services\BackupRestoreTargetConfiguration;
use App\Services\DatabaseDumpBinaryLocator;
use App\Services\DatabaseMigrationHealthService;
use App\Services\PhpExtensionReadiness;
use App\Services\QueueHealthService;
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

#[Signature('opsdeck:deployment-check {--json : Output machine-readable JSON}')]
#[Description('Check production deployment configuration without revealing secret values.')]
class OpsDeckDeploymentReadiness extends Command
{
    private const MIN_QUEUE_RETRY_SECONDS = 1;

    private const MAX_NETWORK_PORT = 65535;

    private const STRIPE_PRICE_CONNECT_TIMEOUT_SECONDS = 3;

    private const STRIPE_PRICE_TIMEOUT_SECONDS = 10;

    private const CACHE_LOCK_TTL_SECONDS = 10;

    private const WORLD_PERMISSION_MASK = 0007;

    private const PRODUCTION_LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'warn', 'error', 'critical', 'alert', 'emergency'];

    private const PRODUCTION_LOG_OUTPUT_DRIVERS = ['daily', 'monthly', 'stderr', 'errorlog', 'syslog', 'slack', 'monolog'];

    /** @var array<string, string> */
    private array $checks = [];

    private ?bool $durableQueueReadiness = null;

    private ?bool $sharedCacheReadiness = null;

    /** @var array<string, Builder> */
    private array $databaseSchemaBuilders = [];

    public function handle(DatabaseDumpBinaryLocator $dumpBinaryLocator, BackupRestoreTargetConfiguration $restoreTargetConfiguration, PhpExtensionReadiness $phpExtensionReadiness, DatabaseMigrationHealthService $migrationHealth): int
    {
        $this->durableQueueReadiness = null;
        $this->sharedCacheReadiness = null;
        $missingPhpExtensions = $phpExtensionReadiness->missingExtensions();
        $this->checks = [
            'environment' => app()->environment('production') ? 'pass' : 'fail',
            'app_key' => $this->hasValidApplicationKey() ? 'pass' : 'fail',
            'debug' => config('app.debug') === false ? 'pass' : 'fail',
            'https_url' => $this->hasHttpsApplicationUrl() ? 'pass' : 'fail',
            'session_cookie_security' => $this->hasSecureSessionCookies() ? 'pass' : 'fail',
            'private_file_storage' => $this->hasPrivateSensitiveFileStorage() ? 'pass' : 'fail',
            'production_logging' => $this->hasProductionLogging() ? 'pass' : 'fail',
            'php_extensions' => $missingPhpExtensions === [] ? 'pass' : 'fail',
            'database_driver' => in_array(config('database.default'), ['mysql', 'mariadb'], true) ? 'pass' : 'fail',
            'database_connection' => $this->canConnectToDatabase() ? 'pass' : 'fail',
            'database_migrations' => $migrationHealth->pendingCount() === 0 ? 'pass' : 'fail',
            'database_backup_tool' => $this->hasDatabaseBackupTool($dumpBinaryLocator) ? 'pass' : 'fail',
            'database_restore_tool' => $this->hasDatabaseRestoreTool($dumpBinaryLocator) ? 'pass' : 'fail',
            'backup_restore_target' => $restoreTargetConfiguration->isReadyForRestoreTest() ? 'pass' : 'fail',
            'queue' => $this->usesDurableQueue() ? 'pass' : 'fail',
            'cache_lock' => $this->usesSharedCache() ? 'pass' : 'fail',
            'mail' => $this->hasProductionMailTransport() ? 'pass' : 'fail',
            'failure_alert' => $this->hasProductionFailureAlert() ? 'pass' : 'fail',
            'stripe' => $this->hasProductionStripeCredentials() ? 'pass' : 'fail',
            'commercial_information' => app()->environment('production')
                ? ($this->hasCommercialInformation() ? 'pass' : 'fail')
                : 'not_applicable',
        ];

        $ready = ! in_array('fail', $this->checks, true);

        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => $ready ? 'ready' : 'not_ready',
                'checks' => $this->checks,
            ], JSON_THROW_ON_ERROR));
        } else {
            foreach ($this->checks as $check => $status) {
                $this->line(sprintf('%s: %s', $check, $status));
            }

            $this->newLine();
            $this->line($ready ? 'Deployment configuration is ready.' : 'Deployment configuration needs attention.');
            if (! $ready) {
                $this->writeRemediationGuidance($missingPhpExtensions);
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $missingPhpExtensions */
    private function writeRemediationGuidance(array $missingPhpExtensions): void
    {
        $this->newLine();
        $this->line('確認・修正する項目:');

        foreach ($this->checks as $check => $status) {
            if ($status !== 'fail') {
                continue;
            }

            if ($check === 'php_extensions') {
                $this->line(sprintf('- php_extensions: PHP拡張を有効化: %s', implode(', ', $missingPhpExtensions)));

                continue;
            }

            $guidance = match ($check) {
                'environment' => '環境変数 APP_ENV を production に設定する',
                'app_key' => 'Laravelの暗号方式に合う APP_KEY を安全に生成して設定する',
                'debug' => '環境変数 APP_DEBUG を false に設定する',
                'https_url' => '環境変数 APP_URL に https:// から始まる公開URLを設定する',
                'session_cookie_security' => 'SESSION_SECURE_COOKIE=true、SESSION_HTTP_ONLY=true、SESSION_SAME_SITE=lax を設定する',
                'private_file_storage' => 'Import/Evidenceの保存先を非公開ディスクにし、S3はvisibility=privateとバケットの公開拒否、SFTPは非公開権限を設定する',
                'production_logging' => '本番ログをwarning以上にし、daily/monthlyの保存期間を設定するか、stderr/syslog等の監視可能な出力先へ送る',
                'database_driver' => '環境変数 DB_CONNECTION を mysql または mariadb に設定する',
                'database_connection' => 'DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME と Secret Manager上のDB_PASSWORDを確認し、DBサーバーの稼働・ネットワーク/ポート到達性も確認する',
                'database_migrations' => '未適用マイグレーションを確認し、事前バックアップ後に php artisan migrate --force を実行する',
                'database_backup_tool' => 'mysqldump または mariadb-dump を導入し、必要なら OPSDECK_DATABASE_DUMP_DIRECTORY を設定する',
                'database_restore_tool' => 'mysql または mariadb クライアントと、接頭辞付きの専用復元DB設定を確認する',
                'backup_restore_target' => 'OPSDECK_BACKUP_RESTORE_DB_*の設定値と接続疎通を確認し、接頭辞 opsdeck_restore_test_ の復元専用DBが空であること、ユーザーと接続ドライバーがアプリDBと分離・一致していることを確認する',
                'queue' => '永続Queueの設定とjobsテーブルを確認し、QUEUE_FAILED_DRIVER=database-uuids / failed_jobsがメインDBにあること、Queue workerの稼働を確認する',
                'cache_lock' => '共有Cacheの接続、DB利用時はcache/cache_locksテーブルを確認し、一時ロックの取得・解放ができることを確認する',
                'mail' => 'MAIL_MAILER / MAIL_FROM_ADDRESS / OPSDECK_ADMIN_MAIL_FROM_ADDRESS とメール事業者の接続設定を確認する',
                'failure_alert' => 'OPSDECK_PLATFORM_ADMIN_EMAILSに有効な管理者メールを設定し、共有Cacheとメール送信設定を確認する',
                'stripe' => '本番用STRIPE_SECRET / STRIPE_PRICE_PREMIUM / STRIPE_WEBHOOK_SECRETを設定し、Stripe上のPremium Priceが有効なJPY月額価格であることを確認する',
                'commercial_information' => 'OPSDECK_SELLER_NAME / OPSDECK_SELLER_ADDRESS / OPSDECK_SELLER_PHONE / OPSDECK_SELLER_REPRESENTATIVE に正確な販売者情報を設定する',
                default => null,
            };

            if ($guidance !== null) {
                $this->line(sprintf('- %s: %s', $check, $guidance));
            }
        }

        $this->line('秘密値はこのチェックでは表示しません。');
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

        return true;
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

    private function hasProductionFailureAlert(): bool
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

        return $this->hasProductionMailTransport()
            && $this->usesSharedCache()
            && $this->isPositiveInteger(config('opsdeck.error_alerts.cooldown_seconds'));
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
        foreach (['opsdeck.import.disk', 'opsdeck.evidence.disk'] as $diskConfigKey) {
            $diskName = config($diskConfigKey);
            if (! is_string($diskName) || trim($diskName) === '') {
                return false;
            }

            $disk = config('filesystems.disks.'.$diskName);
            if (! is_array($disk) || ! is_string($disk['driver'] ?? null)) {
                return false;
            }

            if (($disk['visibility'] ?? null) === 'public') {
                return false;
            }

            $isPrivate = match ($disk['driver']) {
                'local' => $this->localDiskStoresOutsidePublicDirectory($disk),
                's3' => ($disk['visibility'] ?? null) === 'private'
                    && class_exists(AwsS3V3Adapter::class)
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
        $normalizedPublicPath = $this->normalizeFilesystemPath(public_path());

        return $this->isAbsoluteFilesystemPath($normalizedRoot)
            && $normalizedRoot !== $normalizedPublicPath
            && ! str_starts_with($normalizedRoot, $normalizedPublicPath.'/');
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

    private function canConnectToDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasDatabaseBackupTool(DatabaseDumpBinaryLocator $dumpBinaryLocator): bool
    {
        $connectionName = (string) config('database.default');
        $driver = (string) config("database.connections.$connectionName.driver");

        return $driver === 'sqlite' || $dumpBinaryLocator->find($driver) !== null;
    }

    private function hasDatabaseRestoreTool(DatabaseDumpBinaryLocator $dumpBinaryLocator): bool
    {
        $connectionName = (string) config('database.default');
        $driver = (string) config("database.connections.$connectionName.driver");

        return $driver === 'sqlite' || $dumpBinaryLocator->findRestoreClient($driver) !== null;
    }

    private function usesDurableQueue(): bool
    {
        if ($this->durableQueueReadiness !== null) {
            return $this->durableQueueReadiness;
        }

        $connection = config('queue.connections.'.config('queue.default'));
        if (! is_array($connection)) {
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

        return $this->durableQueueReadiness = $queueIsDurable
            && $this->hasDatabaseFailedJobsStorage()
            && app(QueueHealthService::class)->importTimeoutStatus() === 'ok'
            && app(QueueHealthService::class)->workerStatus() === 'ok';
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
        $fromAddress = config('mail.from.address');
        $adminFromAddress = config('opsdeck.admin_mail_from_address');

        return is_string($fromAddress)
            && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) !== false
            && is_string($adminFromAddress)
            && filter_var($adminFromAddress, FILTER_VALIDATE_EMAIL) !== false
            && $this->hasConfiguredMailTransport((string) config('mail.default'));
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
        $secret = (string) config('services.stripe.secret');
        $price = (string) config('services.stripe.prices.premium');
        $webhookSecret = (string) config('services.stripe.webhook_secret');
        if (! str_starts_with($secret, 'sk_live_')
            || ! str_starts_with($price, 'price_')
            || ! str_starts_with($webhookSecret, 'whsec_')) {
            return false;
        }

        try {
            $stripePrice = Http::withBasicAuth($secret, '')
                ->connectTimeout(self::STRIPE_PRICE_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::STRIPE_PRICE_TIMEOUT_SECONDS)
                ->get('https://api.stripe.com/v1/prices/'.rawurlencode($price));
        } catch (Throwable) {
            return false;
        }

        return $stripePrice->successful()
            && $stripePrice->json('id') === $price
            && $stripePrice->json('active') === true
            && $stripePrice->json('type') === 'recurring'
            && $stripePrice->json('billing_scheme') === 'per_unit'
            && $stripePrice->json('currency') === 'jpy'
            && $stripePrice->json('unit_amount') === (int) config('opsdeck.plans.premium.monthly_price_jpy')
            && $stripePrice->json('recurring.interval') === 'month'
            && (int) $stripePrice->json('recurring.interval_count') === 1;
    }

    private function hasCommercialInformation(): bool
    {
        foreach (['seller_name', 'seller_address', 'seller_phone', 'seller_representative'] as $field) {
            if (! is_string(config('opsdeck.commercial_information.'.$field))
                || trim((string) config('opsdeck.commercial_information.'.$field)) === '') {
                return false;
            }
        }

        return true;
    }
}
