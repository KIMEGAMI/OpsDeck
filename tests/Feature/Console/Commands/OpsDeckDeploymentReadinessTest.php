<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\OpsDeckDeploymentReadiness;
use App\Services\BackupHealthService;
use App\Services\BackupRestoreHealthService;
use App\Services\BackupRestoreTargetConfiguration;
use App\Services\BackupStorageIsolation;
use App\Services\DatabaseClientVersionDetector;
use App\Services\DatabaseDumpBinaryLocator;
use App\Services\DatabaseMigrationHealthService;
use App\Services\PhpExtensionReadiness;
use App\Services\QueueHealthService;
use App\Services\R2UploadCommandOptions;
use App\Services\RuntimeDirectoryReadiness;
use App\Services\SchedulerHealthService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\NullHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class OpsDeckDeploymentReadinessTest extends TestCase
{
    private Builder $schemaBuilder;

    private string $originalPublicPath;

    private string $frontendTestPublicPath;

    private string|false $previousResendDomainCheckApiKey;

    private bool $previousResendDomainCheckApiKeyWasInEnvironment;

    private mixed $previousResendDomainCheckApiKeyEnvironmentValue;

    private bool $previousResendDomainCheckApiKeyWasInServer;

    private mixed $previousResendDomainCheckApiKeyServerValue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPublicPath = $this->app->publicPath();
        $this->frontendTestPublicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-deployment-assets-'.bin2hex(random_bytes(8));
        $frontendAssetsPath = $this->frontendTestPublicPath.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets';
        if (! mkdir($frontendAssetsPath, recursive: true)) {
            throw new RuntimeException('Unable to create a temporary frontend asset directory.');
        }
        $this->app->usePublicPath($this->frontendTestPublicPath);
        if (file_put_contents($frontendAssetsPath.DIRECTORY_SEPARATOR.'app.css', 'body{}') === false
            || file_put_contents($frontendAssetsPath.DIRECTORY_SEPARATOR.'app.js', 'void 0;') === false
            || file_put_contents(public_path('build/manifest.json'), json_encode([
                'resources/css/app.css' => ['file' => 'assets/app.css', 'isEntry' => true],
                'resources/js/app.js' => ['file' => 'assets/app.js', 'isEntry' => true],
            ], JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Unable to create a temporary frontend manifest.');
        }
        $this->previousResendDomainCheckApiKey = getenv('RESEND_DOMAIN_CHECK_API_KEY');
        $this->previousResendDomainCheckApiKeyWasInEnvironment = array_key_exists('RESEND_DOMAIN_CHECK_API_KEY', $_ENV);
        $this->previousResendDomainCheckApiKeyEnvironmentValue = $_ENV['RESEND_DOMAIN_CHECK_API_KEY'] ?? null;
        $this->previousResendDomainCheckApiKeyWasInServer = array_key_exists('RESEND_DOMAIN_CHECK_API_KEY', $_SERVER);
        $this->previousResendDomainCheckApiKeyServerValue = $_SERVER['RESEND_DOMAIN_CHECK_API_KEY'] ?? null;
        $this->setResendDomainCheckApiKey('test-domain-check-key');
    }

    protected function tearDown(): void
    {
        $this->app->usePublicPath($this->originalPublicPath);
        foreach ([
            $this->frontendTestPublicPath.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json',
            $this->frontendTestPublicPath.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'app.css',
            $this->frontendTestPublicPath.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'app.js',
            $this->frontendTestPublicPath.DIRECTORY_SEPARATOR.'hot',
        ] as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
        @rmdir($this->frontendTestPublicPath.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets');
        @rmdir($this->frontendTestPublicPath.DIRECTORY_SEPARATOR.'build');
        @rmdir($this->frontendTestPublicPath);

        if ($this->previousResendDomainCheckApiKey === false) {
            putenv('RESEND_DOMAIN_CHECK_API_KEY');
        } else {
            putenv('RESEND_DOMAIN_CHECK_API_KEY='.$this->previousResendDomainCheckApiKey);
        }

        if ($this->previousResendDomainCheckApiKeyWasInEnvironment) {
            $_ENV['RESEND_DOMAIN_CHECK_API_KEY'] = $this->previousResendDomainCheckApiKeyEnvironmentValue;
        } else {
            unset($_ENV['RESEND_DOMAIN_CHECK_API_KEY']);
        }

        if ($this->previousResendDomainCheckApiKeyWasInServer) {
            $_SERVER['RESEND_DOMAIN_CHECK_API_KEY'] = $this->previousResendDomainCheckApiKeyServerValue;
        } else {
            unset($_SERVER['RESEND_DOMAIN_CHECK_API_KEY']);
        }

        parent::tearDown();
    }

    private function setResendDomainCheckApiKey(?string $apiKey): void
    {
        if ($apiKey === null) {
            putenv('RESEND_DOMAIN_CHECK_API_KEY');
            unset($_ENV['RESEND_DOMAIN_CHECK_API_KEY'], $_SERVER['RESEND_DOMAIN_CHECK_API_KEY']);

            return;
        }

        putenv('RESEND_DOMAIN_CHECK_API_KEY='.$apiKey);
        $_ENV['RESEND_DOMAIN_CHECK_API_KEY'] = $apiKey;
        $_SERVER['RESEND_DOMAIN_CHECK_API_KEY'] = $apiKey;
    }

    public function test_database_dump_binary_locator_checks_an_explicit_directory(): void
    {
        $dumpDirectory = 'C:\\Program Files\\MySQL\\bin';
        config(['opsdeck.backup.database_dump_directory' => $dumpDirectory]);
        $executableFinder = $this->mock(ExecutableFinder::class);
        $executableFinder->shouldReceive('find')
            ->once()
            ->with('mysqldump', null, [$dumpDirectory])
            ->andReturn($dumpDirectory.'\\mysqldump.exe');
        $this->app->instance(ExecutableFinder::class, $executableFinder);

        $this->assertSame($dumpDirectory.'\\mysqldump.exe', app(DatabaseDumpBinaryLocator::class)->find('mysql'));
    }

    public function test_database_dump_binary_locator_falls_back_to_mysqldump_for_mariadb(): void
    {
        config(['opsdeck.backup.database_dump_directory' => null]);
        $executableFinder = $this->mock(ExecutableFinder::class);
        $executableFinder->shouldReceive('find')->once()->with('mariadb-dump', null, [])->andReturn(null);
        $executableFinder->shouldReceive('find')->once()->with('mysqldump', null, [])->andReturn('C:\\mysql\\bin\\mysqldump.exe');
        $this->app->instance(ExecutableFinder::class, $executableFinder);

        $this->assertSame('C:\\mysql\\bin\\mysqldump.exe', app(DatabaseDumpBinaryLocator::class)->find('mariadb'));
    }

    public function test_php_extension_readiness_reports_missing_runtime_requirements(): void
    {
        $readiness = new PhpExtensionReadiness;

        $this->assertSame(['mbstring', 'openssl', 'pdo_mysql', 'sodium', 'xmlreader', 'zip'], $readiness->missingExtensions([]));
        $this->assertSame([], $readiness->missingExtensions(['mbstring', 'openssl', 'pdo_mysql', 'sodium', 'xmlreader', 'zip']));
    }

    public function test_database_connection_diagnostic_identifies_a_missing_pdo_driver_without_exposing_exception_text(): void
    {
        $command = app(OpsDeckDeploymentReadiness::class);
        $classifier = new ReflectionMethod($command, 'classifyDatabaseConnectionIssue');

        $this->assertSame('pdo_driver_unavailable', $classifier->invoke($command, new RuntimeException('could not find driver: DB_PASSWORD_SENTINEL')));
    }

    public function test_opsdeck_object_storage_requires_credentials_and_an_https_endpoint(): void
    {
        $command = app(OpsDeckDeploymentReadiness::class);
        $configurationCheck = new ReflectionMethod($command, 'opsDeckObjectsDiskConfigurationIsComplete');
        $completeConfiguration = [
            'key' => 'test-access-key',
            'secret' => 'test-secret',
            'region' => 'auto',
            'bucket' => 'opsdeck-test-private',
            'endpoint' => 'https://objects.example.test',
            'options' => [
                'before_upload' => [R2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                'mup_threshold' => PHP_INT_MAX,
            ],
        ];

        $this->assertTrue($configurationCheck->invoke($command, $completeConfiguration));
        $this->assertFalse($configurationCheck->invoke($command, [...$completeConfiguration, 'secret' => ' ']));
        $this->assertFalse($configurationCheck->invoke($command, [...$completeConfiguration, 'endpoint' => 'http://objects.example.test']));
        $this->assertFalse($configurationCheck->invoke($command, [...$completeConfiguration, 'endpoint' => 'https:///missing-host']));
        $this->assertFalse($configurationCheck->invoke($command, [...$completeConfiguration, 'url' => 'https://public.example.test']));
        $this->assertFalse($configurationCheck->invoke($command, [...$completeConfiguration, 'options' => []]));
        $this->assertFalse($configurationCheck->invoke($command, [...$completeConfiguration, 'options' => [...$completeConfiguration['options'], 'ACL' => 'private']]));
    }

    public function test_non_production_opsdeck_object_storage_disk_uses_private_local_storage(): void
    {
        $disk = config('filesystems.disks.opsdeck_objects');

        $this->assertIsArray($disk);
        $this->assertSame('local', $disk['driver'] ?? null);
        $this->assertSame('private', $disk['visibility'] ?? null);
        $this->assertArrayNotHasKey('key', $disk);
        $this->assertArrayNotHasKey('secret', $disk);
        $this->assertArrayNotHasKey('bucket', $disk);
        $this->assertArrayNotHasKey('endpoint', $disk);
        $this->assertTrue($disk['throw'] ?? false);

        $command = app(OpsDeckDeploymentReadiness::class);
        $configurationCheck = new ReflectionMethod($command, 'opsDeckObjectsDiskConfigurationIsComplete');

        $this->assertFalse($configurationCheck->invoke($command, $disk));
    }

    public function test_opsdeck_object_storage_rejects_a_non_s3_driver_even_if_the_disk_is_private(): void
    {
        config([
            'opsdeck.backup.path' => storage_path('app/backups'),
            'opsdeck.import.disk' => 'opsdeck_objects',
            'opsdeck.evidence.disk' => 'opsdeck_objects',
            'filesystems.disks.opsdeck_objects' => [
                'driver' => 'local',
                'root' => storage_path('app/private'),
                'visibility' => 'private',
                'directory_visibility' => 'private',
            ],
        ]);
        $command = app(OpsDeckDeploymentReadiness::class);
        $storageCheck = new ReflectionMethod($command, 'hasPrivateSensitiveFileStorage');

        $this->assertFalse($storageCheck->invoke($command));
    }

    public function test_production_storage_readiness_rejects_the_development_bucket(): void
    {
        $this->app['env'] = 'production';
        config([
            'opsdeck.backup.path' => storage_path('app/backups'),
            'opsdeck.storage.expected_production_bucket' => 'opsdeck-prod-files',
            'opsdeck.storage.expected_development_bucket' => 'opsdeck-dev-files',
            'opsdeck.import.disk' => 'opsdeck_objects',
            'opsdeck.evidence.disk' => 'opsdeck_objects',
            'filesystems.disks.opsdeck_objects' => [
                'driver' => 's3',
                'key' => 'readiness-test-access-key',
                'secret' => 'readiness-test-secret-key',
                'region' => 'auto',
                'bucket' => 'opsdeck-dev-files',
                'endpoint' => 'https://account.example.test',
                'url' => null,
                'use_path_style_endpoint' => true,
                'visibility' => 'private',
                'options' => [
                    'before_upload' => [R2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                    'mup_threshold' => PHP_INT_MAX,
                ],
            ],
        ]);

        $command = app(OpsDeckDeploymentReadiness::class);
        $storageCheck = new ReflectionMethod($command, 'hasPrivateSensitiveFileStorage');

        $this->assertFalse($storageCheck->invoke($command));
    }

    public function test_production_storage_readiness_rejects_the_development_bucket_even_if_expected(): void
    {
        $this->app['env'] = 'production';
        config([
            'opsdeck.backup.path' => storage_path('app/backups'),
            'opsdeck.storage.expected_production_bucket' => 'opsdeck-dev-files',
            'opsdeck.storage.expected_development_bucket' => 'opsdeck-dev-files',
            'opsdeck.import.disk' => 'opsdeck_objects',
            'opsdeck.evidence.disk' => 'opsdeck_objects',
            'filesystems.disks.opsdeck_objects' => [
                'driver' => 's3',
                'key' => 'readiness-test-access-key',
                'secret' => 'readiness-test-secret-key',
                'region' => 'auto',
                'bucket' => 'opsdeck-dev-files',
                'endpoint' => 'https://account.example.test',
                'url' => null,
                'use_path_style_endpoint' => true,
                'visibility' => 'private',
                'options' => [
                    'before_upload' => [R2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                    'mup_threshold' => PHP_INT_MAX,
                ],
            ],
        ]);

        $command = app(OpsDeckDeploymentReadiness::class);
        $storageCheck = new ReflectionMethod($command, 'hasPrivateSensitiveFileStorage');

        $this->assertFalse($storageCheck->invoke($command));
    }

    public function test_it_returns_ready_when_production_configuration_is_complete(): void
    {
        $this->configureReadyDeployment();
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $actualOutput = trim($this->app[Kernel::class]->output());

        $this->assertSame('{"status":"ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}', $actualOutput);
        $this->assertSame(0, $exitCode, $actualOutput);
    }

    public function test_production_readiness_accepts_smtp_with_idempotent_resend_delivery_enabled(): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => 'smtp',
                'host' => 'smtp.resend.com',
                'port' => 587,
                'username' => 'resend',
                'password' => 'smtp-test-secret',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pass', $output['checks']['mail']);
        $this->assertSame(0, $exitCode);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.resend.com/domains'));
    }

    public function test_production_readiness_rejects_smtp_when_idempotent_delivery_is_disabled(): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => 'smtp',
                'host' => 'smtp.resend.com',
                'port' => 587,
                'username' => 'resend',
                'password' => 'smtp-test-secret',
            ],
            'mail.mailers.resend_idempotent.enabled' => false,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['mail']);
        $this->assertSame('resend_idempotency_configuration_incomplete', $output['diagnostics']['mail']);
        $this->assertSame(1, $exitCode);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.resend.com/domains'));
    }

    public function test_production_readiness_rejects_smtp_without_a_password(): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => 'smtp',
                'host' => 'smtp.resend.com',
                'port' => 587,
                'username' => 'resend',
                'password' => null,
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['mail']);
        $this->assertSame('smtp_configuration_incomplete', $output['diagnostics']['mail']);
        $this->assertSame(1, $exitCode);
    }

    public function test_production_readiness_fails_when_a_feature_outside_the_1_0_scope_is_enabled(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.release_features.work_time_tracking' => true]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $output['checks']['release_scope']);
    }

    public function test_production_readiness_fails_when_storage_addon_is_enabled_outside_the_1_0_scope(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.release_features.storage_addon' => true]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $output['checks']['release_scope']);
    }

    public function test_production_readiness_allows_local_only_backups_without_remote_bucket_configuration(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.backup.remote_enabled' => false,
            'opsdeck.backup.remote_retention_days' => null,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pass', $output['checks']['database_backup_tool']);
        $this->assertSame(0, $exitCode);
    }

    public function test_production_readiness_requires_a_recent_verified_local_backup(): void
    {
        $this->configureReadyDeployment(latestBackupStatus: 'stale');
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['database_backup_tool']);
        $this->assertSame(1, $exitCode);
    }

    public function test_production_readiness_requires_a_recent_successful_backup_restore_test(): void
    {
        $this->configureReadyDeployment(backupRestoreTestStatus: 'missing');
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['database_restore_tool']);
        $this->assertSame(1, $exitCode);
    }

    public function test_production_readiness_fails_when_remote_backup_is_enabled_for_the_local_only_release(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.backup.remote_enabled' => true]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['database_backup_tool']);
        $this->assertSame(1, $exitCode);
    }

    public function test_offsite_backup_readiness_rejects_the_application_bucket_or_credentials(): void
    {
        config([
            'opsdeck.backup.remote_enabled' => false,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
            'opsdeck.backup.remote_retention_days' => 30,
            'filesystems.disks.opsdeck_backups' => [
                'driver' => 's3',
                'key' => 'backup-test-key',
                'secret' => 'backup-test-secret',
                'bucket' => 'opsdeck-backups-test',
                'endpoint' => 'https://account.example.test',
            ],
            'filesystems.disks.opsdeck_objects' => [
                'driver' => 's3',
                'key' => 'app-test-key',
                'secret' => 'app-test-secret',
                'bucket' => 'opsdeck-prod-files',
            ],
            'filesystems.disks.opsdeck_objects_test' => [
                'driver' => 's3',
                'key' => 'development-test-key',
                'secret' => 'development-test-secret',
                'bucket' => 'opsdeck-dev-files',
            ],
            'opsdeck.storage.expected_production_bucket' => 'opsdeck-prod-files',
            'opsdeck.storage.expected_development_bucket' => 'opsdeck-dev-files',
        ]);
        $storageIsolation = app(BackupStorageIsolation::class);

        config(['filesystems.disks.opsdeck_backups.bucket' => 'opsdeck-prod-files']);
        $this->assertFalse($storageIsolation->isDedicated(config('filesystems.disks.opsdeck_backups')));

        config([
            'filesystems.disks.opsdeck_backups.bucket' => 'opsdeck-backups-test',
            'filesystems.disks.opsdeck_backups.key' => 'app-test-key',
        ]);
        $this->assertFalse($storageIsolation->isDedicated(config('filesystems.disks.opsdeck_backups')));

        config([
            'filesystems.disks.opsdeck_backups.bucket' => 'opsdeck-dev-files',
            'filesystems.disks.opsdeck_backups.key' => 'backup-test-key',
        ]);
        $this->assertFalse($storageIsolation->isDedicated(config('filesystems.disks.opsdeck_backups')));

        config([
            'filesystems.disks.opsdeck_backups.bucket' => 'dedicated-backup-bucket',
            'filesystems.disks.opsdeck_backups.key' => 'development-test-key',
        ]);
        $this->assertFalse($storageIsolation->isDedicated(config('filesystems.disks.opsdeck_backups')));

        config([
            'filesystems.disks.opsdeck_backups.bucket' => 'dedicated-backup-bucket',
            'filesystems.disks.opsdeck_backups.key' => 'backup-test-key',
        ]);
        $this->assertTrue(app(BackupStorageIsolation::class)->isDedicated(config('filesystems.disks.opsdeck_backups')));
        $this->assertTrue($storageIsolation->isDedicated(config('filesystems.disks.opsdeck_backups')));
    }

    public function test_production_readiness_fails_when_a_runtime_directory_is_unavailable(): void
    {
        $this->configureReadyDeployment();
        $runtimeDirectoryReadiness = $this->mock(RuntimeDirectoryReadiness::class);
        $runtimeDirectoryReadiness->shouldReceive('unavailableDirectories')
            ->once()
            ->andReturn(['storage/logs']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true])
            ->expectsOutputToContain('"runtime_directories":"fail"')
            ->assertExitCode(1);
    }

    public function test_external_logging_does_not_require_a_local_log_directory(): void
    {
        config([
            'logging.default' => 'opsdeck_external_test',
            'logging.channels.opsdeck_external_test' => ['driver' => 'syslog', 'level' => 'warning'],
        ]);
        $command = app(OpsDeckDeploymentReadiness::class);
        $runtimeDirectoriesMethod = new ReflectionMethod($command, 'runtimeDirectories');

        $runtimeDirectories = $runtimeDirectoriesMethod->invoke($command);
        $logDirectories = array_filter(
            array_keys($runtimeDirectories),
            static fn (string $directoryName): bool => str_starts_with($directoryName, 'log_directory_'),
        );

        $this->assertSame([], array_values($logDirectories));
    }

    public function test_file_logging_checks_the_directory_of_its_configured_log_file(): void
    {
        config([
            'logging.default' => 'opsdeck_file_test',
            'logging.channels.opsdeck_file_test' => [
                'driver' => 'daily',
                'path' => base_path('var/log/opsdeck.log'),
                'level' => 'warning',
                'max_files' => 30,
            ],
        ]);
        $command = app(OpsDeckDeploymentReadiness::class);
        $runtimeDirectoriesMethod = new ReflectionMethod($command, 'runtimeDirectories');

        $runtimeDirectories = $runtimeDirectoriesMethod->invoke($command);

        $this->assertSame(base_path('var/log'), $runtimeDirectories['log_directory_opsdeck_file_test']);
    }

    public function test_production_readiness_fails_when_a_built_frontend_asset_is_missing(): void
    {
        $this->configureReadyDeployment();
        unlink(public_path('build/assets/app.css'));
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true])
            ->expectsOutputToContain('"frontend_assets":"fail"')
            ->assertExitCode(1);
    }

    public function test_built_frontend_readiness_rejects_a_vite_hot_file(): void
    {
        $this->assertNotFalse(file_put_contents(public_path('hot'), 'http://localhost:5173'));
        $command = app(OpsDeckDeploymentReadiness::class);
        $assetReadiness = new ReflectionMethod($command, 'hasBuiltFrontendAssets');

        $this->assertFalse($assetReadiness->invoke($command));
    }

    public function test_built_frontend_readiness_rejects_asset_paths_outside_the_build_directory(): void
    {
        file_put_contents(public_path('build/manifest.json'), json_encode([
            'resources/css/app.css' => ['file' => '../outside.css', 'isEntry' => true],
            'resources/js/app.js' => ['file' => 'assets/app.js', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));
        $command = app(OpsDeckDeploymentReadiness::class);
        $assetReadiness = new ReflectionMethod($command, 'hasBuiltFrontendAssets');

        $this->assertFalse($assetReadiness->invoke($command));
    }

    public function test_skipping_provider_verification_does_not_make_http_requests_or_mark_deployment_ready(): void
    {
        $this->configureReadyDeployment();
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('not_ready', $output['status']);
        $this->assertSame('unverified', $output['checks']['mail']);
        $this->assertSame('unverified', $output['checks']['failure_alert']);
        $this->assertSame('unverified', $output['checks']['stripe']);
        $this->assertSame(['status' => 'skipped', 'checks' => ['mail', 'failure_alert', 'stripe']], $output['diagnostics']['provider_verification']);
        $this->assertSame(1, $exitCode);
        Http::assertNothingSent();
    }

    public function test_skipping_stripe_verification_still_checks_resend_without_sending_email(): void
    {
        $this->configureReadyDeployment();
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-stripe-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('not_ready', $output['status']);
        $this->assertSame('pass', $output['checks']['mail']);
        $this->assertSame('pass', $output['checks']['failure_alert']);
        $this->assertSame('unverified', $output['checks']['stripe']);
        $this->assertSame(['status' => 'skipped', 'checks' => ['stripe']], $output['diagnostics']['provider_verification']);
        $this->assertSame(1, $exitCode);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.resend.com/domains'));
    }

    public function test_free_only_mode_does_not_require_stripe_or_seller_information(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.billing.enabled' => false]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ready', $output['status']);
        $this->assertSame('not_applicable', $output['checks']['stripe']);
        $this->assertSame('not_applicable', $output['checks']['commercial_information']);
        $this->assertSame(0, $exitCode);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.stripe.com/'));
    }

    public function test_production_readiness_requires_a_platform_storage_limit(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.storage_quotas.platform_limit_megabytes' => null]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['platform_storage_capacity']);
        $this->assertSame('not_ready', $output['status']);
        $this->assertSame(1, $exitCode);
        Http::assertNothingSent();
    }

    public function test_production_readiness_rejects_a_remote_database_without_verified_tls(): void
    {
        $this->configureReadyDeployment(hasDatabaseCacheTables: false, databaseConnectionAvailable: false);
        config([
            'database.connections.mysql.host' => 'mysql.production.example.test',
            'database.connections.mysql.options' => [],
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['database_connection']);
        $this->assertSame('unverified', $output['checks']['database_migrations']);
        $this->assertSame('remote_database_requires_verified_tls', $output['diagnostics']['database_connection']);
        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_lists_missing_php_extensions_without_exposing_configuration_values(): void
    {
        $this->configureReadyDeployment(missingPhpExtensions: ['openssl', 'pdo_mysql']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['openssl', 'pdo_mysql'], $output['diagnostics']['php_extensions']);
        $this->assertSame(1, $exitCode);
        Http::assertNothingSent();
    }

    public function test_json_output_identifies_a_missing_queue_worker_heartbeat(): void
    {
        $this->configureReadyDeployment(hasFreshQueueWorker: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['queue_worker_heartbeat_missing_or_stale'], $output['diagnostics']['queue']);
        $this->assertSame(1, $exitCode);
        Http::assertNothingSent();
    }

    public function test_human_output_explains_skipped_provider_verification(): void
    {
        $this->configureReadyDeployment();
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--skip-provider-verification' => true])
            ->expectsOutputToContain('ResendとStripeの外部検証は省略されました。リリース前に通常の導入チェックを実行してください。')
            ->expectsOutputToContain('- メール送信設定（mail）: 外部検証を省略したため未確認です。リリース前に通常の導入チェックを実行してください')
            ->expectsOutputToContain('- エラー通知設定（failure_alert）: 外部検証を省略したため未確認です。リリース前に通常の導入チェックを実行してください')
            ->expectsOutputToContain('- Stripe決済設定（stripe）: 外部検証を省略したため未確認です。リリース前に通常の導入チェックを実行してください')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_ai_is_enabled_without_complete_cost_configuration(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.usd_to_jpy_rate' => null,
            'opsdeck.ai.model_prices_per_million_tokens' => [],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"ai_cost":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_rejects_ai_cost_configuration_without_cached_and_cache_write_prices(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.monthly_budget_jpy.premium' => 500,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'output' => 10.00],
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"ai_cost":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_passes_ai_cost_readiness_when_all_active_model_prices_are_configured(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'readiness-test-ai-key',
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = trim($this->app[Kernel::class]->output());

        $this->assertStringContainsString('"ai_cost":"pass"', $output);
        $this->assertStringContainsString('"ai_mapping":"pass"', $output, $output);
        $this->assertSame(0, $exitCode, $output);
    }

    public function test_it_fails_ai_mapping_readiness_without_provider_credentials_without_calling_ai(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => null,
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"ai_mapping":"fail"')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses');
    }

    public function test_it_fails_ai_mapping_readiness_for_placeholder_credentials_without_calling_ai(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => '<OpenAI API key>',
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"ai_mapping":"fail"')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses');
    }

    public function test_it_fails_production_readiness_when_required_ai_mapping_is_disabled(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.ai.enabled' => false]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"ai_mapping":"fail"')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses');
    }

    public function test_it_rejects_untrusted_ai_mapping_endpoints_without_calling_them(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.ai.endpoint' => 'https://attacker.example/v1/responses']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"ai_mapping":"fail"')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'attacker.example'));
    }

    public function test_it_fails_readiness_when_idempotent_resend_is_enabled_without_an_api_key(): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.mailers.resend_idempotent.enabled' => true,
            'mail.mailers.resend_idempotent.key' => '',
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    public function test_provider_failures_report_safe_actionable_diagnostics_without_secret_values(): void
    {
        $this->configureReadyDeployment(stripePriceAmount: 1);
        config([
            'mail.default' => 'array',
            'services.stripe.secret' => 'sk_live_SECRET_SENTINEL',
            'services.stripe.webhook_secret' => 'whsec_SECRET_SENTINEL',
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $rawOutput = trim($this->app[Kernel::class]->output());
        $output = json_decode($rawOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('mailer_must_be_idempotent_resend', $output['diagnostics']['mail']);
        $this->assertSame('premium_price_configuration_mismatch', $output['diagnostics']['stripe']);
        $this->assertStringNotContainsString('SECRET_SENTINEL', $rawOutput);
        $this->assertSame(1, $exitCode);
    }

    public function test_production_readiness_validates_the_configured_storage_addon_price(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 4,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pass', $output['checks']['stripe']);
        $this->assertSame('fail', $output['checks']['release_scope']);
        $this->assertSame(1, $exitCode);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/prices/price_storage_addon_unit_test'));
    }

    public function test_production_readiness_requires_a_storage_addon_capacity_limit_for_the_1_0_scope(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.storage_addon_max_gib' => null,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $rawOutput = trim($this->app[Kernel::class]->output());
        $output = json_decode($rawOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode, $rawOutput);
        $this->assertSame('fail', $output['checks']['stripe'] ?? null, $rawOutput);
        $this->assertSame('storage_addon_capacity_limit_incomplete', $output['diagnostics']['stripe'] ?? null, $rawOutput);

        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/v1/prices/price_storage_addon_unit_test'));
    }

    public function test_production_readiness_rejects_a_storage_addon_price_with_the_wrong_amount(): void
    {
        $this->configureReadyDeployment(storageAddonPriceAmount: 1);
        config([
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 4,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('storage_addon_price_configuration_mismatch', $output['diagnostics']['stripe']);
        $this->assertSame('not_ready', $output['status']);
        $this->assertSame(1, $exitCode);
    }

    public function test_production_readiness_requires_a_tax_code_for_an_enabled_storage_addon(): void
    {
        $this->configureReadyDeployment(stripeDefaultTaxCode: null, storageAddonProductTaxCode: null);
        config([
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 4,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('storage_addon_tax_code_missing', $output['diagnostics']['stripe']);
        $this->assertSame('not_ready', $output['status']);
        $this->assertSame(1, $exitCode);
    }

    public function test_production_readiness_accepts_the_default_tax_code_for_an_enabled_storage_addon(): void
    {
        $this->configureReadyDeployment(storageAddonProductTaxCode: null);
        config([
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 4,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pass', $output['checks']['stripe']);
        $this->assertSame('fail', $output['checks']['release_scope']);
        $this->assertSame(1, $exitCode);
    }

    public function test_it_accepts_enabled_idempotent_resend_with_a_configured_api_key(): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.mailers.resend_idempotent.enabled' => true,
            'mail.mailers.resend_idempotent.key' => 'test-resend-key',
            'mail.mailers.resend_idempotent.timeout' => 10,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"status":"ready"')
            ->assertExitCode(0);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidMailDeliveryBackoffs(): array
    {
        return [
            'missing' => [[]],
            'zero delay' => [[0, 30]],
            'negative delay' => [[-1, 30]],
            'non-numeric delay' => [[30, 'invalid']],
            'associative configuration' => [['first' => 30]],
        ];
    }

    #[DataProvider('invalidMailDeliveryBackoffs')]
    public function test_it_fails_readiness_when_mail_retry_backoff_is_invalid(mixed $backoff): void
    {
        $this->configureReadyDeployment();
        config(['mail.delivery_job_backoff_seconds' => $backoff]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidResendApiTimeouts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'fractional' => [1.5],
            'non-numeric' => ['not-a-timeout'],
            'missing' => [null],
        ];
    }

    #[DataProvider('invalidResendApiTimeouts')]
    public function test_it_fails_readiness_when_enabled_idempotent_resend_has_an_invalid_timeout(mixed $timeout): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.mailers.resend_idempotent.enabled' => true,
            'mail.mailers.resend_idempotent.key' => 'test-resend-key',
            'mail.mailers.resend_idempotent.timeout' => $timeout,
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    #[DataProvider('invalidProductionApplicationUrls')]
    public function test_it_fails_readiness_for_an_invalid_production_application_url(string $applicationUrl): void
    {
        $this->configureReadyDeployment();
        config(['app.url' => $applicationUrl]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"https_url":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_production_hsts_is_disabled(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.security.hsts_max_age_seconds' => 0]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutputToContain('公開URL（https_url）: 合格')
            ->expectsOutputToContain('HSTS設定（hsts）: 要対応')
            ->expectsOutputToContain('- HSTS設定（hsts）: OPSDECK_SECURITY_HSTS_MAX_AGE_SECONDS に正の秒数を設定する')
            ->assertExitCode(1);
    }

    public static function invalidProductionApplicationUrls(): array
    {
        return [
            'localhost hostname' => ['https://localhost'],
            'localhost subdomain' => ['https://admin.localhost'],
            'IPv4 loopback' => ['https://127.0.0.1'],
            'IPv6 loopback' => ['https://[::1]'],
            'private IPv4 address' => ['https://192.168.1.20'],
            'single-label host' => ['https://opsdeck'],
            'invalid hostname character' => ['https://ops deck.example.test'],
            'underscore in hostname' => ['https://ops_deck.example.test'],
            'invalid hostname label' => ['https://-opsdeck.example.test'],
            'URL credentials' => ['https://admin:secret@opsdeck.example.test'],
            'query string' => ['https://opsdeck.example.test/?next=%2Fdashboard'],
            'fragment' => ['https://opsdeck.example.test/#dashboard'],
        ];
    }

    public function test_it_fails_readiness_when_production_mail_uses_a_failover_mailer(): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'backup_smtp'],
            ],
            'mail.mailers.backup_smtp' => [
                'transport' => 'smtp',
                'host' => 'backup-smtp.example.test',
                'port' => 587,
                'username' => null,
                'password' => null,
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_idempotent_resend_is_disabled(): void
    {
        $this->configureReadyDeployment();
        config(['mail.mailers.resend_idempotent.enabled' => false]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_the_resend_sender_domain_is_not_verified(): void
    {
        $this->configureReadyDeployment(resendDomainStatus: 'partially_verified');
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_admin_mail_uses_an_unverified_resend_domain(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.admin_mail_from_address' => 'opsdeck@unverified.example.test']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_without_a_separate_resend_domain_check_key(): void
    {
        $this->configureReadyDeployment();
        $this->setResendDomainCheckApiKey(null);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);

        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'api.resend.com/domains'));
    }

    public function test_it_uses_a_temporary_process_key_for_the_resend_domain_check(): void
    {
        $this->setResendDomainCheckApiKey('test-ephemeral-domain-check-key');

        try {
            $this->configureReadyDeployment();
            $connection = $this->mock(Connection::class);
            $connection->shouldReceive('getPdo')->once();
            $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
            DB::shouldReceive('connection')->twice()->andReturn($connection);

            $this->artisan('opsdeck:deployment-check', ['--json' => true])
                ->expectsOutputToContain('"mail":"pass"')
                ->assertExitCode(0);

            Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'api.resend.com/domains')
                && $request->hasHeader('Authorization', 'Bearer test-ephemeral-domain-check-key'));
        } finally {
            $this->setResendDomainCheckApiKey('test-domain-check-key');
        }
    }

    public function test_it_fails_readiness_when_resend_sending_capability_is_disabled(): void
    {
        $this->configureReadyDeployment(resendSendingCapability: 'disabled');
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_finds_the_resend_sender_domain_after_a_page_boundary(): void
    {
        $this->configureReadyDeployment(resendDomainOnSecondPage: true);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"pass"')
            ->assertExitCode(0);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.resend.com/domains?')
            && str_contains($request->url(), 'after=domain_other')
            && $request->hasHeader('Authorization', 'Bearer test-domain-check-key'));
    }

    public function test_it_fails_readiness_when_a_failover_mailer_references_an_unconfigured_mailer(): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => [
                'transport' => 'failover',
                'mailers' => ['missing_mailer'],
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_the_queue_worker_has_no_fresh_heartbeat(): void
    {
        $this->configureReadyDeployment(hasFreshQueueWorker: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutputToContain('バックグラウンド処理（Queue）（queue）: 要対応')
            ->expectsOutputToContain('待機件数の確認（ジョブは実行しません）: php artisan queue:monitor database:default --json')
            ->expectsOutputToContain('Queue workerは待機中の処理を実行します（メール送信を含みます）。')
            ->expectsOutputToContain('起動前に送信予定と送信枠を確認し、承認後に本番サーバーだけで起動してください。')
            ->expectsOutputToContain('開発PCでは起動しないでください。')
            ->expectsOutputToContain('本番導入の前に、要対応の項目があります。')
            ->assertExitCode(1);
    }

    public function test_queue_monitor_guidance_omits_untrusted_queue_names(): void
    {
        $this->configureReadyDeployment(hasFreshQueueWorker: false);
        config(['queue.connections.database.queue' => 'default; malicious-command']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--skip-provider-verification' => true])
            ->expectsOutputToContain('バックグラウンド処理（Queue）（queue）: 要対応')
            ->doesntExpectOutput('queue:monitor')
            ->doesntExpectOutput('malicious-command')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_a_webhook_job_can_be_retried_before_its_timeout(): void
    {
        $this->configureReadyDeployment(hasSafeQueueJobTimeout: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutputToContain('バックグラウンド処理（Queue）（queue）: 要対応')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_the_live_stripe_price_does_not_match_the_configured_monthly_price(): void
    {
        $this->configureReadyDeployment(stripePriceAmount: 980000);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"stripe":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_the_live_stripe_price_is_not_tax_exclusive(): void
    {
        $this->configureReadyDeployment(stripePriceTaxBehavior: 'inclusive');
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"stripe":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_the_stripe_product_has_no_tax_code(): void
    {
        $this->configureReadyDeployment(stripeProductTaxCode: null, stripeDefaultTaxCode: null);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"stripe":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_accepts_the_account_default_tax_code_when_the_product_has_no_override(): void
    {
        $this->configureReadyDeployment(stripeProductTaxCode: null);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"stripe":"pass"')
            ->assertExitCode(0);
    }

    public function test_it_fails_readiness_when_stripe_tax_settings_are_pending(): void
    {
        $this->configureReadyDeployment(stripeTaxSettingsStatus: 'pending');
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"stripe":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_japan_tax_registration_is_not_active(): void
    {
        $this->configureReadyDeployment(hasJapanTaxRegistration: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"stripe":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_finds_active_japan_tax_registration_after_a_stripe_page_boundary(): void
    {
        $this->configureReadyDeployment(taxRegistrationOnSecondPage: true);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"stripe":"pass"')
            ->assertExitCode(0);
    }

    public function test_it_fails_readiness_without_exposing_credentials_when_stripe_cannot_be_reached(): void
    {
        $this->configureReadyDeployment();
        Http::fake(['api.stripe.com/v1/prices/price_unit_test*' => Http::failedConnection()]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"stripe":"fail"')
            ->doesntExpectOutput('sk_live_unit_test')
            ->assertExitCode(1);
    }

    #[DataProvider('insecureSessionCookieSettings')]
    public function test_it_fails_readiness_when_production_session_cookie_settings_are_insecure(string $setting, mixed $value): void
    {
        $this->configureReadyDeployment();
        config([$setting => $value]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"session_cookie_security":"fail"')
            ->assertExitCode(1);
    }

    public static function insecureSessionCookieSettings(): array
    {
        return [
            'secure cookie disabled' => ['session.secure', false],
            'http only disabled' => ['session.http_only', false],
            'same site not lax' => ['session.same_site', 'strict'],
        ];
    }

    public function test_it_fails_readiness_when_production_logs_include_debug_messages(): void
    {
        $this->configureReadyDeployment();
        config(['logging.channels.daily.level' => 'debug']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"production_logging":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_monolog_discards_production_logs(): void
    {
        $this->configureReadyDeployment();
        config([
            'logging.channels.stack.channels' => ['discarded'],
            'logging.channels.discarded' => [
                'driver' => 'monolog',
                'handler' => NullHandler::class,
                'level' => 'warning',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"production_logging":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_monolog_writes_to_an_unrotated_file(): void
    {
        $this->configureReadyDeployment();
        config([
            'logging.channels.stack.channels' => ['unrotated'],
            'logging.channels.unrotated' => [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'handler_with' => ['stream' => storage_path('logs/opsdeck.log')],
                'level' => 'warning',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"production_logging":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_accepts_monolog_stderr_as_a_monitorable_log_output(): void
    {
        $this->configureReadyDeployment();
        config([
            'logging.channels.stack.channels' => ['verified_stderr'],
            'logging.channels.verified_stderr' => [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'handler_with' => ['stream' => 'php://stderr'],
                'level' => 'warning',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"production_logging":"pass"')
            ->assertExitCode(0);
    }

    public function test_it_rejects_udp_syslog_as_a_production_monolog_output(): void
    {
        $this->configureReadyDeployment();
        config([
            'logging.channels.stack.channels' => ['remote_syslog'],
            'logging.channels.remote_syslog' => [
                'driver' => 'monolog',
                'handler' => SyslogUdpHandler::class,
                'handler_with' => ['host' => 'logs.example.test', 'port' => 6514],
                'level' => 'warning',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"production_logging":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_accepts_tls_socket_logging_only_with_a_valid_endpoint_and_timeouts(): void
    {
        $this->configureReadyDeployment();
        config([
            'logging.channels.stack.channels' => ['tls_remote'],
            'logging.channels.tls_remote' => [
                'driver' => 'monolog',
                'handler' => SocketHandler::class,
                'handler_with' => [
                    'connectionString' => 'tls://logs.example.test:6514',
                    'connectionTimeout' => 3,
                    'writingTimeout' => 3,
                ],
                'level' => 'warning',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"production_logging":"pass"')
            ->assertExitCode(0);
    }

    public function test_it_rejects_plaintext_socket_logging_in_production(): void
    {
        $this->configureReadyDeployment();
        config([
            'logging.channels.stack.channels' => ['plaintext_remote'],
            'logging.channels.plaintext_remote' => [
                'driver' => 'monolog',
                'handler' => SocketHandler::class,
                'handler_with' => [
                    'connectionString' => 'tcp://logs.example.test:6514',
                    'connectionTimeout' => 3,
                    'writingTimeout' => 3,
                ],
                'level' => 'warning',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"production_logging":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_no_valid_administrator_email_is_configured_for_failure_alerts(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.platform_admin_emails' => ['invalid-address']]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"failure_alert":"fail"')
            ->assertExitCode(1);
    }

    #[DataProvider('nonPrivateFileStorageConfigurations')]
    public function test_it_fails_readiness_when_sensitive_file_storage_is_not_private(array $configuration): void
    {
        $this->configureReadyDeployment();
        config($configuration);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"private_file_storage":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_production_selects_the_non_production_storage_disk(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.evidence.disk' => 'opsdeck_objects_test',
            'filesystems.disks.opsdeck_objects_test' => [
                'driver' => 's3',
                'visibility' => 'private',
                'options' => [
                    'before_upload' => [R2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                    'mup_threshold' => PHP_INT_MAX,
                ],
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"private_file_storage":"fail"')
            ->assertExitCode(1);
    }

    #[DataProvider('localSensitiveFileStorageDisks')]
    public function test_it_fails_readiness_when_production_uses_local_sensitive_file_storage(string $diskConfigKey): void
    {
        $this->configureReadyDeployment();
        config([$diskConfigKey => 'local']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"private_file_storage":"fail"')
            ->assertExitCode(1);
    }

    /** @return array<string, array{string}> */
    public static function localSensitiveFileStorageDisks(): array
    {
        return [
            'local import storage' => ['opsdeck.import.disk'],
            'local evidence storage' => ['opsdeck.evidence.disk'],
        ];
    }

    public function test_it_fails_readiness_when_local_storage_root_is_inside_the_public_directory(): void
    {
        $this->configureReadyDeployment();
        config([
            'opsdeck.import.disk' => 'unsafe_local',
            'filesystems.disks.unsafe_local' => [
                'driver' => 'local',
                'root' => public_path('uploads'),
                'visibility' => 'private',
                'directory_visibility' => 'private',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"private_file_storage":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_local_storage_root_is_symlinked_into_the_public_directory(): void
    {
        $storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-private-disk-'.bin2hex(random_bytes(8));
        if (! @symlink(public_path(), $storageRoot)) {
            $this->markTestSkipped('Symbolic links are unavailable in this environment.');
        }

        try {
            $this->configureReadyDeployment();
            config([
                'opsdeck.import.disk' => 'unsafe_local',
                'filesystems.disks.unsafe_local' => [
                    'driver' => 'local',
                    'root' => $storageRoot,
                    'visibility' => 'private',
                    'directory_visibility' => 'private',
                ],
            ]);
            $connection = $this->mock(Connection::class);
            $connection->shouldReceive('getPdo')->once();
            $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
            DB::shouldReceive('connection')->twice()->andReturn($connection);

            $this->artisan('opsdeck:deployment-check', ['--json' => true])
                ->expectsOutputToContain('"private_file_storage":"fail"')
                ->assertExitCode(1);
        } finally {
            @unlink($storageRoot);
        }
    }

    public function test_it_fails_readiness_when_backup_path_is_inside_the_public_directory(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.backup.path' => public_path('uploads/backups')]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"private_file_storage":"fail"')
            ->assertExitCode(1);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function nonPrivateFileStorageConfigurations(): array
    {
        return [
            'public import disk' => [['opsdeck.import.disk' => 'public']],
            'public evidence disk' => [['opsdeck.evidence.disk' => 'public']],
            's3 disk without explicit private visibility' => [[
                'opsdeck.import.disk' => 'unsafe_s3',
                'filesystems.disks.unsafe_s3' => ['driver' => 's3'],
            ]],
            'unsupported ftp driver' => [[
                'opsdeck.import.disk' => 'unsupported_ftp',
                'filesystems.disks.unsupported_ftp' => [
                    'driver' => 'ftp',
                    'visibility' => 'private',
                ],
            ]],
            'sftp private permissions expose files to everyone' => [[
                'opsdeck.import.disk' => 'unsafe_sftp',
                'filesystems.disks.unsafe_sftp' => [
                    'driver' => 'sftp',
                    'permissions' => ['file' => ['private' => 0644]],
                ],
            ]],
        ];
    }

    public function test_it_fails_readiness_when_database_migrations_are_pending(): void
    {
        $this->configureReadyDeployment(hasPendingMigrations: true);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"fail","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_the_database_migration_repository_is_missing(): void
    {
        $this->configureReadyDeployment(hasMigrationRepository: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"fail","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_production_seller_details_are_missing(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.commercial_information.seller_phone' => null]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"fail","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_production_seller_details_are_placeholder_values(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.commercial_information.seller_name' => '未設定']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"fail","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_mysql_backup_tool_is_missing(): void
    {
        $this->configureReadyDeployment(false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"fail","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_backup_encryption_key_is_missing(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.backup.encryption_enabled' => true, 'opsdeck.backup.encryption_key' => null]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"fail","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"},"diagnostics":{"backup_encryption":["encryption_key_missing"]}}')
            ->assertExitCode(1);
    }

    public function test_it_identifies_an_invalid_backup_encryption_key_without_printing_it(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.backup.encryption_enabled' => true, 'opsdeck.backup.encryption_key' => 'INVALID_BACKUP_KEY_SENTINEL']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"fail","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"},"diagnostics":{"backup_encryption":["encryption_key_invalid"]}}')
            ->doesntExpectOutput('INVALID_BACKUP_KEY_SENTINEL')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_a_required_php_extension_is_missing(): void
    {
        $this->configureReadyDeployment(true, ['openssl']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"fail","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"},"diagnostics":{"php_extensions":["openssl"]}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_application_key_has_an_invalid_length(): void
    {
        $this->configureReadyDeployment();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(8))]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"fail","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_base64_application_key_is_invalid(): void
    {
        $this->configureReadyDeployment();
        config(['app.key' => 'base64:invalid-key']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"fail","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_production_readiness_rejects_smtp_when_the_resend_host_is_not_configured(): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => 'smtp',
                'host' => 'smtp.example.test',
                'port' => 587,
                'username' => 'resend',
                'password' => 'smtp-test-secret',
            ],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['mail']);
        $this->assertSame('smtp_configuration_incomplete', $output['diagnostics']['mail']);
        $this->assertSame(1, $exitCode);
    }

    public static function invalidSupportContacts(): array
    {
        return [
            'invalid address' => ['invalid-support-address'],
            'same as sender address' => ['info@kimegami.jp'],
            'same as admin sender address' => ['opsdeck@kimegami.jp'],
        ];
    }

    #[DataProvider('invalidSupportContacts')]
    public function test_it_fails_readiness_when_support_contact_is_invalid_or_matches_sender(string $supportEmail): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.support_email' => $supportEmail]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"mail":"fail"')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_mail_from_address_is_invalid(): void
    {
        $this->configureReadyDeployment();
        config(['mail.from.address' => 'not-an-email-address']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"fail","failure_alert":"fail","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"},"diagnostics":{"mail":"sender_address_invalid"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_admin_notification_from_address_is_invalid(): void
    {
        $this->configureReadyDeployment();
        config(['opsdeck.admin_mail_from_address' => 'not-an-email-address']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"fail","failure_alert":"fail","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"},"diagnostics":{"mail":"sender_address_invalid"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_database_queue_retry_time_is_invalid(): void
    {
        $this->configureReadyDeployment();
        config(['queue.connections.database.retry_after' => 0]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode($this->app[Kernel::class]->output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('not_ready', $output['status']);
        $this->assertSame('fail', $output['checks']['queue']);
        $this->assertContains('queue_connection_not_durable_or_misconfigured', $output['diagnostics']['queue']);
    }

    public function test_it_fails_readiness_when_database_queue_table_is_missing(): void
    {
        $this->configureReadyDeployment(hasDatabaseQueueTable: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true]);
        $output = json_decode($this->app[Kernel::class]->output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('not_ready', $output['status']);
        $this->assertSame('fail', $output['checks']['queue']);
        $this->assertContains('queue_connection_not_durable_or_misconfigured', $output['diagnostics']['queue']);
    }

    public function test_it_fails_readiness_when_failed_jobs_table_is_missing(): void
    {
        $this->configureReadyDeployment(hasFailedJobsTable: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"fail","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"},"diagnostics":{"queue":["failed_jobs_storage_not_ready"]}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_failed_jobs_are_not_stored_in_the_admin_monitored_database_table(): void
    {
        $this->configureReadyDeployment();
        config(['queue.failed.driver' => 'file']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"fail","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"},"diagnostics":{"queue":["failed_jobs_storage_not_ready"]}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_database_cache_tables_are_missing(): void
    {
        $this->configureReadyDeployment(true, [], true, false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"fail","mail":"pass","failure_alert":"fail","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_database_cache_lock_cannot_be_acquired(): void
    {
        $this->configureReadyDeployment(cacheLockAcquisitionSucceeds: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"fail","mail":"pass","failure_alert":"fail","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_database_cache_lock_cannot_be_released(): void
    {
        $this->configureReadyDeployment(cacheLockReleaseSucceeds: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"fail","mail":"pass","failure_alert":"fail","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_safely_when_configuration_is_not_production_ready(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.key' => '',
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'database.default' => 'sqlite',
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'mail.default' => 'log',
            'services.stripe.secret' => 'SECRET_SENTINEL',
            'services.stripe.prices.premium' => null,
            'services.stripe.webhook_secret' => null,
        ]);
        $exception = new PDOException('DB_PASSWORD_SENTINEL');
        $exception->errorInfo = ['HY000', 2002, 'DB_HOST_SENTINEL'];
        DB::shouldReceive('connection')->once()->andThrow($exception);
        $phpExtensionReadiness = $this->mock(PhpExtensionReadiness::class);
        $phpExtensionReadiness->shouldReceive('missingExtensions')->once()->andReturn([]);
        $this->app->instance(PhpExtensionReadiness::class, $phpExtensionReadiness);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutputToContain('"status":"not_ready"')
            ->doesntExpectOutput('DB_PASSWORD_SENTINEL')
            ->doesntExpectOutput('DB_HOST_SENTINEL')
            ->assertExitCode(1);
    }

    public function test_human_output_suggests_safe_remediation_without_printing_secrets(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.key' => '',
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'database.default' => 'sqlite',
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'mail.default' => 'log',
            'services.stripe.secret' => 'STRIPE_SECRET_SENTINEL',
            'services.stripe.prices.premium' => null,
            'services.stripe.webhook_secret' => null,
        ]);
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('DB_PASSWORD_SENTINEL'));
        $phpExtensionReadiness = $this->mock(PhpExtensionReadiness::class);
        $phpExtensionReadiness->shouldReceive('missingExtensions')->once()->andReturn(['openssl']);
        $this->app->instance(PhpExtensionReadiness::class, $phpExtensionReadiness);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutput('PHP拡張機能（php_extensions）: 要対応')
            ->expectsOutput('確認・修正する項目:')
            ->expectsOutput('このチェックは本番以外（local）で実行されています。本番設定は本番サーバーに設定し、開発PCの.envは変更しないでください。')
            ->expectsOutput('- 本番ログ（production_logging）: 本番ログをwarning以上にし、daily/monthlyの保存期間を設定するか、stderr/syslog等の監視可能な出力先へ送る')
            ->expectsOutput('- PHP拡張機能（php_extensions）: PHP拡張を有効化: openssl')
            ->expectsOutput('- データベース接続（database_connection）: DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME を確認し、DB_PASSWORDは本番Secret ManagerまたはVPS上の権限制限した.envで設定する。DBサーバーの稼働・ネットワーク/ポート到達性も確認する')
            ->expectsOutput('- 接続確認の詳細（database_connection_detail）: unclassified')
            ->expectsOutput('- データベース更新状況（database_migrations）: DB接続失敗のため未確認です。先に接続先と対象DBが正しいことを確認し、再チェックしてください。未適用と確認できるまではマイグレーションを実行しないでください')
            ->expectsOutput('秘密値はこのチェックでは表示しません。')
            ->doesntExpectOutput('php artisan migrate --force')
            ->doesntExpectOutput('STRIPE_SECRET_SENTINEL')
            ->doesntExpectOutput('DB_PASSWORD_SENTINEL')
            ->assertExitCode(1);
    }

    public function test_human_output_explains_mysql_authentication_failure_without_exposing_credentials(): void
    {
        $exception = new PDOException('DB_PASSWORD_SENTINEL');
        $exception->errorInfo = ['28000', 1045, 'DB_USERNAME_SENTINEL'];
        $this->configureMinimalDatabaseFailure($exception);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutputToContain('MySQLの確認: サーバーには接続できています。DB_USERNAME / DB_PASSWORDと、MySQLユーザーの接続元ホスト設定をDB管理者に確認してください。')
            ->doesntExpectOutput('DB_PASSWORD_SENTINEL')
            ->doesntExpectOutput('DB_USERNAME_SENTINEL')
            ->assertExitCode(1);
    }

    public function test_human_output_explains_missing_mysql_database_without_exposing_connection_details(): void
    {
        $exception = new PDOException('DB_HOST_SENTINEL');
        $exception->errorInfo = ['3D000', 1049, 'DB_DATABASE_SENTINEL'];
        $this->configureMinimalDatabaseFailure($exception);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutputToContain('MySQLの確認: DB_DATABASEで指定したデータベースが見つかりません。接続先とDB名を確認し、必要ならDB管理者に専用データベースの作成を依頼してください。')
            ->doesntExpectOutput('DB_HOST_SENTINEL')
            ->doesntExpectOutput('DB_DATABASE_SENTINEL')
            ->assertExitCode(1);
    }

    public function test_human_output_explains_missing_database_privilege(): void
    {
        $exception = new PDOException('DB_USERNAME_SENTINEL');
        $exception->errorInfo = ['42000', 1044, 'DB_DATABASE_SENTINEL'];
        $this->configureMinimalDatabaseFailure($exception);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutputToContain('MySQLの確認: 認証後に対象DBへのアクセスが拒否されています。DB_DATABASEと、アプリ専用ユーザーに対象DBの権限が付与されているかをDB管理者に確認してください。')
            ->doesntExpectOutput('DB_USERNAME_SENTINEL')
            ->doesntExpectOutput('DB_DATABASE_SENTINEL')
            ->assertExitCode(1);
    }

    private function configureMinimalDatabaseFailure(PDOException $exception): void
    {
        $this->app['env'] = 'local';
        config([
            'app.key' => '',
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'database.default' => 'mysql',
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'mail.default' => 'log',
            'services.stripe.secret' => null,
            'services.stripe.prices.premium' => null,
            'services.stripe.webhook_secret' => null,
        ]);
        DB::shouldReceive('connection')->atLeast()->once()->andThrow($exception);

        $phpExtensionReadiness = $this->mock(PhpExtensionReadiness::class);
        $phpExtensionReadiness->shouldReceive('missingExtensions')->once()->andReturn([]);
        $this->app->instance(PhpExtensionReadiness::class, $phpExtensionReadiness);
    }

    public function test_windows_human_output_shows_read_only_local_database_diagnostics_without_secrets(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('PowerShell remediation is Windows-specific.');
        }

        $this->configureReadyDeployment(hasDatabaseCacheTables: false, databaseConnectionAvailable: false);
        $this->app['env'] = 'local';
        config([
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 3306,
        ]);

        $exception = new PDOException('DB_PASSWORD_SENTINEL');
        $exception->errorInfo = ['HY000', 2002, 'DB_HOST_SENTINEL'];
        DB::shouldReceive('connection')->atLeast()->once()->andThrow($exception);

        $this->artisan('opsdeck:deployment-check', ['--skip-provider-verification' => true])
            ->expectsOutputToContain('Test-NetConnection -ComputerName "DB_HOST" -Port DB_PORT')
            ->expectsOutputToContain('Get-Service | Where-Object')
            ->expectsOutputToContain('この確認ではDBサービスを起動しません。')
            ->doesntExpectOutput('DB_PASSWORD_SENTINEL')
            ->doesntExpectOutput('DB_HOST_SENTINEL')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_mysql_restore_client_is_missing(): void
    {
        $this->configureReadyDeployment(true, [], false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"fail","backup_restore_target":"pass","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_database_clients_are_present_but_their_versions_cannot_be_identified(): void
    {
        $this->configureReadyDeployment(hasDatabaseCacheTables: false, databaseConnectionAvailable: false, databaseClientVersionSupported: false);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['database_backup_tool']);
        $this->assertSame('fail', $output['checks']['database_restore_tool']);
        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_identifies_a_missing_scheduler_heartbeat(): void
    {
        $this->configureReadyDeployment(hasFreshSchedulerHeartbeat: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--json' => true, '--skip-provider-verification' => true]);
        $output = json_decode(trim($this->app[Kernel::class]->output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $output['checks']['scheduler_heartbeat']);
        $this->assertSame('stale', $output['diagnostics']['scheduler_heartbeat']);
        $this->assertSame(1, $exitCode);
        Http::assertNothingSent();
    }

    public function test_human_output_warns_that_scheduler_jobs_may_send_email_before_production_setup(): void
    {
        $this->configureReadyDeployment(hasFreshSchedulerHeartbeat: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--skip-provider-verification' => true])
            ->expectsOutputToContain('実行予定の確認（ジョブは実行しません。ロック状態の確認でCacheを一時利用します）: php artisan schedule:list')
            ->expectsOutputToContain('メール送信上限・許可時間と送信予定を確認し')
            ->expectsOutputToContain('開発PCでは起動しないでください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_readiness_when_a_dedicated_restore_database_is_not_configured(): void
    {
        $this->configureReadyDeployment(hasReadyRestoreTarget: false);
        config(['database.connections.backup_restore.database' => null]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","release_scope":"pass","app_key":"pass","debug":"pass","https_url":"pass","hsts":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","runtime_directories":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"fail","queue":"pass","scheduler_heartbeat":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass","frontend_assets":"pass","ai_mapping":"pass","ai_cost":"pass"},"diagnostics":{"backup_restore_target":"unsafe_configuration","backup_restore_target_issues":["restore_database_name_must_use_reserved_prefix"]}}')
            ->assertExitCode(1);
    }

    public function test_human_output_explains_restore_database_name_requirement(): void
    {
        $this->configureReadyDeployment(hasReadyRestoreTarget: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutput('- 復元テスト用DBの設定手順（backup_restore_target_action）: OPSDECK_BACKUP_RESTORE_DB_DATABASEをopsdeck_restore_test_で始まる専用DB名にする')
            ->assertExitCode(1);
    }

    public function test_human_output_gives_secret_safe_guidance_for_rejected_restore_credentials(): void
    {
        $this->configureReadyDeployment(backupRestoreTargetStatus: 'credentials_rejected');

        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--skip-provider-verification' => true]);
        $output = $this->app[Kernel::class]->output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('- 復元テスト用データベース（backup_restore_target）: 復元専用DBのOPSDECK_BACKUP_RESTORE_DB_USERNAMEとOPSDECK_BACKUP_RESTORE_DB_PASSWORD、MySQL側のユーザー名・接続元ホストを確認してください。パスワードはログやチャットに貼り付けないでください', $output);
    }

    public function test_human_output_explains_local_backup_configuration(): void
    {
        $this->configureReadyDeployment(hasDatabaseBackupTool: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('opsdeck:deployment-check', ['--skip-provider-verification' => true]);
        $output = $this->app[Kernel::class]->output();

        $this->assertStringContainsString('database_backup_tool', $output);
        $this->assertStringContainsString('OPSDECK_DATABASE_DUMP_DIRECTORY', $output);
        $this->assertStringContainsString('OPSDECK_BACKUP_PATH', $output);
        $this->assertStringContainsString('storage/app/backups', $output);
        $this->assertStringContainsString('暗号化は任意で、無効のままならsodiumと暗号鍵は不要', $output);
        $this->assertSame(1, $exitCode);

        Http::assertNothingSent();
    }

    private function configureReadyDeployment(bool $hasDatabaseBackupTool = true, array $missingPhpExtensions = [], bool $hasDatabaseRestoreTool = true, bool $hasDatabaseCacheTables = true, bool $hasReadyRestoreTarget = true, bool $hasPendingMigrations = false, bool $hasMigrationRepository = true, bool $cacheLockAcquisitionSucceeds = true, bool $cacheLockReleaseSucceeds = true, bool $hasDatabaseQueueTable = true, bool $hasFailedJobsTable = true, int $stripePriceAmount = 9800, bool $hasFreshQueueWorker = true, bool $hasSafeQueueJobTimeout = true, string $stripePriceTaxBehavior = 'exclusive', bool $hasJapanTaxRegistration = true, bool $taxRegistrationOnSecondPage = false, ?string $stripeProductTaxCode = 'txcd_test_service', ?string $stripeDefaultTaxCode = 'txcd_test_default', string $stripeTaxSettingsStatus = 'active', string $resendDomainStatus = 'verified', string $resendSendingCapability = 'enabled', bool $resendDomainOnSecondPage = false, bool $databaseConnectionAvailable = true, bool $databaseClientVersionSupported = true, bool $hasFreshSchedulerHeartbeat = true, int $storageAddonPriceAmount = 100, ?string $storageAddonProductTaxCode = 'txcd_test_service', string $latestBackupStatus = 'ok', string $backupRestoreTestStatus = 'ok', ?string $backupRestoreTargetStatus = null): void
    {
        $this->app['env'] = 'production';
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.debug' => false,
            'app.url' => 'https://opsdeck.example.test',
            'opsdeck.release_features.google_sheets' => false,
            'opsdeck.release_features.impact_report' => false,
            'opsdeck.release_features.questionnaire_ai_draft' => false,
            'opsdeck.release_features.referrals' => false,
            'opsdeck.release_features.storage_addon' => false,
            'opsdeck.release_features.work_time_tracking' => false,
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'readiness-test-ai-key',
            'opsdeck.ai.endpoint' => 'https://api.openai.com/v1/responses',
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.monthly_budget_jpy.premium' => 500,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
            ],
            'opsdeck.security.hsts_max_age_seconds' => 31536000,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'opsdeck.import.disk' => 'opsdeck_objects',
            'opsdeck.evidence.disk' => 'opsdeck_objects',
            'opsdeck.storage.expected_production_bucket' => 'opsdeck-prod-files',
            'opsdeck.storage.expected_development_bucket' => 'opsdeck-dev-files',
            'filesystems.disks.opsdeck_objects' => [
                'driver' => 's3',
                'key' => 'readiness-test-access-key',
                'secret' => 'readiness-test-secret-key',
                'region' => 'auto',
                'bucket' => 'opsdeck-prod-files',
                'endpoint' => 'https://account.example.test',
                'url' => null,
                'use_path_style_endpoint' => true,
                'visibility' => 'private',
                'options' => [
                    'before_upload' => [R2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                    'mup_threshold' => PHP_INT_MAX,
                ],
            ],
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.database' => 'opsdeck',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck_restore_test_readiness',
                'username' => 'restore_user',
                'host' => '127.0.0.1',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
            'opsdeck.backup.encryption_key' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
            'opsdeck.backup.remote_enabled' => false,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
            'opsdeck.backup.remote_retention_days' => 30,
            'filesystems.disks.opsdeck_backups' => [
                'driver' => 's3',
                'key' => 'backup-readiness-test-access-key',
                'secret' => 'backup-readiness-test-secret-key',
                'region' => 'auto',
                'bucket' => 'opsdeck-backups-test',
                'endpoint' => 'https://account.example.test',
                'use_path_style_endpoint' => true,
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ],
            'opsdeck.storage_quotas.platform_limit_megabytes' => 51200,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 48,
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
            'queue.connections.database.retry_after' => 360,
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => 'mysql',
            'queue.failed.table' => 'failed_jobs',
            'cache.default' => 'database',
            'cache.stores.database.driver' => 'database',
            'cache.stores.database.connection' => null,
            'cache.stores.database.table' => 'cache',
            'cache.stores.database.lock_connection' => null,
            'cache.stores.database.lock_table' => 'cache_locks',
            'mail.default' => 'resend_idempotent',
            'opsdeck.admin_mail_from_address' => 'opsdeck@kimegami.jp',
            'logging.default' => 'stack',
            'logging.channels.stack.driver' => 'stack',
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.driver' => 'daily',
            'logging.channels.daily.level' => 'warning',
            'logging.channels.daily.path' => storage_path('logs/laravel.log'),
            'logging.channels.daily.max_files' => 30,
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.mailers.resend_idempotent.enabled' => true,
            'mail.mailers.resend_idempotent.key' => 'test-resend-key',
            'mail.mailers.resend_idempotent.timeout' => 10,
            'mail.from.address' => 'info@kimegami.jp',
            'opsdeck.support_email' => 'support@kimegami.jp',
            'opsdeck.commercial_information.seller_name' => 'OpsDeck Test Seller',
            'opsdeck.commercial_information.seller_address' => '東京都千代田区テスト1-2-3',
            'opsdeck.commercial_information.seller_phone' => '03-1234-5678',
            'opsdeck.commercial_information.seller_representative' => 'テスト 太郎',
            'opsdeck.platform_admin_emails' => ['admin@example.test'],
            'opsdeck.error_alerts.cooldown_seconds' => 300,
            'services.stripe.secret' => 'sk_live_unit_test',
            'services.stripe.prices.premium' => 'price_unit_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_addon_unit_test',
            'services.stripe.webhook_secret' => 'whsec_unit_test',
            'opsdeck.backup.database_dump_directory' => null,
        ]);
        $taxRegistrationResponses = Http::sequence()->push($taxRegistrationOnSecondPage ? [
            'object' => 'list',
            'has_more' => true,
            'data' => [['id' => 'taxreg_other', 'country' => 'US', 'status' => 'active']],
        ] : [
            'object' => 'list',
            'has_more' => false,
            'data' => $hasJapanTaxRegistration ? [['id' => 'taxreg_japan', 'country' => 'JP', 'status' => 'active']] : [],
        ], 200);
        if ($taxRegistrationOnSecondPage) {
            $taxRegistrationResponses->push([
                'object' => 'list',
                'has_more' => false,
                'data' => $hasJapanTaxRegistration ? [['id' => 'taxreg_japan', 'country' => 'JP', 'status' => 'active']] : [],
            ], 200);
        }

        $resendDomains = Http::sequence()->push($resendDomainOnSecondPage ? [
            'object' => 'list',
            'has_more' => true,
            'data' => [['id' => 'domain_other', 'name' => 'other.example.test', 'status' => 'verified', 'capabilities' => ['sending' => 'enabled']]],
        ] : [
            'object' => 'list',
            'has_more' => false,
            'data' => [['id' => 'domain_kimegami', 'name' => 'kimegami.jp', 'status' => $resendDomainStatus, 'capabilities' => ['sending' => $resendSendingCapability]]],
        ], 200);
        if ($resendDomainOnSecondPage) {
            $resendDomains->push([
                'object' => 'list',
                'has_more' => false,
                'data' => [['id' => 'domain_kimegami', 'name' => 'kimegami.jp', 'status' => $resendDomainStatus, 'capabilities' => ['sending' => $resendSendingCapability]]],
            ], 200);
        }

        Http::fake([
            'api.stripe.com/v1/prices/price_unit_test*' => Http::response([
                'id' => 'price_unit_test',
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => $stripePriceAmount,
                'tax_behavior' => $stripePriceTaxBehavior,
                'product' => ['id' => 'prod_unit_test', 'tax_code' => $stripeProductTaxCode],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'api.stripe.com/v1/prices/price_storage_addon_unit_test*' => Http::response([
                'id' => 'price_storage_addon_unit_test',
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => $storageAddonPriceAmount,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_storage_addon_unit_test', 'tax_code' => $storageAddonProductTaxCode],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'api.stripe.com/v1/tax/settings' => Http::response([
                'status' => $stripeTaxSettingsStatus,
                'defaults' => ['tax_code' => $stripeDefaultTaxCode],
            ], 200),
            'api.stripe.com/v1/tax/registrations*' => $taxRegistrationResponses,
            'api.resend.com/domains*' => $resendDomains,
        ]);

        $executableFinder = $this->mock(ExecutableFinder::class);
        $executableFinder->shouldReceive('find')
            ->once()
            ->with('mysqldump', null, [])
            ->andReturn($hasDatabaseBackupTool ? 'C:\\mysql\\bin\\mysqldump.exe' : null);
        $executableFinder->shouldReceive('find')
            ->once()
            ->with('mysql', null, [])
            ->andReturn($hasDatabaseRestoreTool ? 'C:\\mysql\\bin\\mysql.exe' : null);
        $this->app->instance(ExecutableFinder::class, $executableFinder);

        $clientVersionDetector = $this->mock(DatabaseClientVersionDetector::class);
        if ($databaseClientVersionSupported) {
            $clientVersionDetector->shouldReceive('detect')->zeroOrMoreTimes()->andReturn('mysql');
        } else {
            $clientVersionDetector->shouldReceive('detect')->zeroOrMoreTimes()->andThrow(new RuntimeException('Unknown client.'));
        }
        $this->app->instance(DatabaseClientVersionDetector::class, $clientVersionDetector);

        $phpExtensionReadiness = $this->mock(PhpExtensionReadiness::class);
        $phpExtensionReadiness->shouldReceive('missingExtensions')->once()->andReturn($missingPhpExtensions);
        $this->app->instance(PhpExtensionReadiness::class, $phpExtensionReadiness);

        if ($hasDatabaseCacheTables) {
            $cacheLock = $this->mock(Lock::class);
            $cacheLock->shouldReceive('get')->once()->andReturn($cacheLockAcquisitionSucceeds);
            if ($cacheLockAcquisitionSucceeds) {
                $cacheLock->shouldReceive('release')
                    ->times($cacheLockReleaseSucceeds ? 1 : 2)
                    ->andReturn($cacheLockReleaseSucceeds);
            }
            $cacheLockProvider = $this->mock(LockProvider::class);
            $cacheLockProvider->shouldReceive('lock')->once()->andReturn($cacheLock);
            Cache::shouldReceive('store')->once()->with('database')->andReturn($cacheLockProvider);
        }

        $this->schemaBuilder = $this->mock(Builder::class);
        $this->schemaBuilder->shouldReceive('hasTable')->with('jobs')->andReturn($hasDatabaseQueueTable);
        $this->schemaBuilder->shouldReceive('hasTable')->with('failed_jobs')->andReturn($hasFailedJobsTable);
        $this->schemaBuilder->shouldReceive('hasTable')->with('cache')->andReturn($hasDatabaseCacheTables);
        $this->schemaBuilder->shouldReceive('hasTable')->with('cache_locks')->andReturn($hasDatabaseCacheTables);
        $restoreTargetConfiguration = $this->mock(BackupRestoreTargetConfiguration::class);
        $restoreTargetConfiguration->shouldReceive('readinessStatus')->once()->andReturn($backupRestoreTargetStatus ?? ($hasReadyRestoreTarget ? 'ready' : 'unsafe_configuration'));
        $restoreTargetConfiguration->shouldReceive('configurationIssues')
            ->zeroOrMoreTimes()
            ->andReturn(['restore_database_name_must_use_reserved_prefix']);
        $this->app->instance(BackupRestoreTargetConfiguration::class, $restoreTargetConfiguration);

        $backupHealth = $this->mock(BackupHealthService::class);
        $backupHealth->shouldReceive('report')->zeroOrMoreTimes()->andReturn(['status' => $latestBackupStatus]);
        $this->app->instance(BackupHealthService::class, $backupHealth);

        $backupRestoreHealth = $this->mock(BackupRestoreHealthService::class);
        $backupRestoreHealth->shouldReceive('report')->zeroOrMoreTimes()->andReturn(['status' => $backupRestoreTestStatus]);
        $this->app->instance(BackupRestoreHealthService::class, $backupRestoreHealth);

        $migrationHealth = $this->mock(DatabaseMigrationHealthService::class);
        if ($databaseConnectionAvailable) {
            $migrationHealth->shouldReceive('pendingCount')
                ->once()
                ->andReturn($hasMigrationRepository ? ($hasPendingMigrations ? 1 : 0) : null);
        } else {
            $migrationHealth->shouldReceive('pendingCount')->never();
        }
        $this->app->instance(DatabaseMigrationHealthService::class, $migrationHealth);

        $queueHealth = $this->mock(QueueHealthService::class);
        $queueHealth->shouldReceive('importTimeoutStatus')->zeroOrMoreTimes()->andReturn($hasSafeQueueJobTimeout ? 'ok' : 'invalid');
        $queueHealth->shouldReceive('workerStatus')->zeroOrMoreTimes()->andReturn($hasFreshQueueWorker ? 'ok' : 'stale');
        $this->app->instance(QueueHealthService::class, $queueHealth);

        $schedulerHealth = $this->mock(SchedulerHealthService::class);
        $schedulerHealth->shouldReceive('status')->zeroOrMoreTimes()->andReturn($hasFreshSchedulerHeartbeat ? 'ok' : 'stale');
        $this->app->instance(SchedulerHealthService::class, $schedulerHealth);
    }
}
