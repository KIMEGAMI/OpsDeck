<?php

namespace Tests\Feature\Console\Commands;

use App\Services\BackupRestoreTargetConfiguration;
use App\Services\DatabaseDumpBinaryLocator;
use App\Services\DatabaseMigrationHealthService;
use App\Services\PhpExtensionReadiness;
use App\Services\QueueHealthService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\NullHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class OpsDeckDeploymentReadinessTest extends TestCase
{
    private Builder $schemaBuilder;

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

        $this->assertSame(['mbstring', 'openssl', 'pdo_mysql', 'xmlreader', 'zip'], $readiness->missingExtensions([]));
        $this->assertSame([], $readiness->missingExtensions(['mbstring', 'openssl', 'pdo_mysql', 'xmlreader', 'zip']));
    }

    public function test_it_returns_ready_when_production_configuration_is_complete(): void
    {
        $this->configureReadyDeployment();
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
            ->assertExitCode(0);
    }

    public function test_it_returns_ready_when_production_mail_uses_a_configured_failover_mailer(): void
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
            ->expectsOutputToContain('"mail":"pass"')
            ->assertExitCode(0);
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
            ->expectsOutputToContain('queue: fail')
            ->expectsOutputToContain('Deployment configuration needs attention.')
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
            ->expectsOutputToContain('queue: fail')
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

    public function test_it_fails_readiness_without_exposing_credentials_when_stripe_cannot_be_reached(): void
    {
        $this->configureReadyDeployment();
        Http::fake(['api.stripe.com/v1/prices/price_unit_test' => Http::failedConnection()]);
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"fail","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"fail","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"fail"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"fail","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"fail","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"fail","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"fail","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_smtp_configuration_is_incomplete(): void
    {
        $this->configureReadyDeployment();
        config(['mail.mailers.smtp.host' => '']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"fail","failure_alert":"fail","stripe":"pass","commercial_information":"pass"}}')
            ->assertExitCode(1);
    }

    /** @return array<string, array{array{username: ?string, password: ?string}}> */
    public static function partialSmtpCredentials(): array
    {
        return [
            'username without password' => [['username' => 'smtp-user', 'password' => null]],
            'password without username' => [['username' => null, 'password' => 'smtp-password']],
        ];
    }

    /** @param array{username: ?string, password: ?string} $credentials */
    #[DataProvider('partialSmtpCredentials')]
    public function test_it_fails_readiness_when_only_one_smtp_credential_is_configured(array $credentials): void
    {
        $this->configureReadyDeployment();
        config([
            'mail.mailers.smtp.username' => $credentials['username'],
            'mail.mailers.smtp.password' => $credentials['password'],
        ]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"fail","failure_alert":"fail","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"fail","failure_alert":"fail","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"fail","failure_alert":"fail","stripe":"pass","commercial_information":"pass"}}')
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

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"fail","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_database_queue_table_is_missing(): void
    {
        $this->configureReadyDeployment(hasDatabaseQueueTable: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"fail","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
            ->assertExitCode(1);
    }

    public function test_it_fails_readiness_when_failed_jobs_table_is_missing(): void
    {
        $this->configureReadyDeployment(hasFailedJobsTable: false);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($this->schemaBuilder);
        DB::shouldReceive('connection')->twice()->andReturn($connection);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"fail","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"fail","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"fail","mail":"pass","failure_alert":"fail","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"fail","mail":"pass","failure_alert":"fail","stripe":"pass","commercial_information":"pass"}}')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"pass","queue":"pass","cache_lock":"fail","mail":"pass","failure_alert":"fail","stripe":"pass","commercial_information":"pass"}}')
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
        DB::shouldReceive('connection')->twice()->andThrow(new RuntimeException('DB_PASSWORD_SENTINEL'));
        $phpExtensionReadiness = $this->mock(PhpExtensionReadiness::class);
        $phpExtensionReadiness->shouldReceive('missingExtensions')->once()->andReturn([]);
        $this->app->instance(PhpExtensionReadiness::class, $phpExtensionReadiness);

        $this->artisan('opsdeck:deployment-check', ['--json' => true])
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"fail","app_key":"fail","debug":"fail","https_url":"fail","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"fail","php_extensions":"pass","database_driver":"fail","database_connection":"fail","database_migrations":"fail","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"fail","queue":"fail","cache_lock":"fail","mail":"fail","failure_alert":"fail","stripe":"fail","commercial_information":"not_applicable"}}')
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
        DB::shouldReceive('connection')->twice()->andThrow(new RuntimeException('DB_PASSWORD_SENTINEL'));
        $phpExtensionReadiness = $this->mock(PhpExtensionReadiness::class);
        $phpExtensionReadiness->shouldReceive('missingExtensions')->once()->andReturn(['openssl']);
        $this->app->instance(PhpExtensionReadiness::class, $phpExtensionReadiness);

        $this->artisan('opsdeck:deployment-check')
            ->expectsOutput('php_extensions: fail')
            ->expectsOutput('確認・修正する項目:')
            ->expectsOutput('- production_logging: 本番ログをwarning以上にし、daily/monthlyの保存期間を設定するか、stderr/syslog等の監視可能な出力先へ送る')
            ->expectsOutput('- php_extensions: PHP拡張を有効化: openssl')
            ->expectsOutput('- database_connection: DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME と Secret Manager上のDB_PASSWORDを確認し、DBサーバーの稼働・ネットワーク/ポート到達性も確認する')
            ->expectsOutput('秘密値はこのチェックでは表示しません。')
            ->doesntExpectOutput('STRIPE_SECRET_SENTINEL')
            ->doesntExpectOutput('DB_PASSWORD_SENTINEL')
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"fail","backup_restore_target":"pass","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
            ->assertExitCode(1);
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
            ->expectsOutput('{"status":"not_ready","checks":{"environment":"pass","app_key":"pass","debug":"pass","https_url":"pass","session_cookie_security":"pass","private_file_storage":"pass","production_logging":"pass","php_extensions":"pass","database_driver":"pass","database_connection":"pass","database_migrations":"pass","database_backup_tool":"pass","database_restore_tool":"pass","backup_restore_target":"fail","queue":"pass","cache_lock":"pass","mail":"pass","failure_alert":"pass","stripe":"pass","commercial_information":"pass"}}')
            ->assertExitCode(1);
    }

    private function configureReadyDeployment(bool $hasDatabaseBackupTool = true, array $missingPhpExtensions = [], bool $hasDatabaseRestoreTool = true, bool $hasDatabaseCacheTables = true, bool $hasReadyRestoreTarget = true, bool $hasPendingMigrations = false, bool $hasMigrationRepository = true, bool $cacheLockAcquisitionSucceeds = true, bool $cacheLockReleaseSucceeds = true, bool $hasDatabaseQueueTable = true, bool $hasFailedJobsTable = true, int $stripePriceAmount = 9800, bool $hasFreshQueueWorker = true, bool $hasSafeQueueJobTimeout = true): void
    {
        $this->app['env'] = 'production';
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.debug' => false,
            'app.url' => 'https://opsdeck.example.test',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
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
            'mail.default' => 'smtp',
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
            'mail.from.address' => 'info@kimegami.jp',
            'opsdeck.commercial_information.seller_name' => 'OpsDeck Test Seller',
            'opsdeck.commercial_information.seller_address' => '東京都千代田区テスト1-2-3',
            'opsdeck.commercial_information.seller_phone' => '03-1234-5678',
            'opsdeck.commercial_information.seller_representative' => 'テスト 太郎',
            'opsdeck.platform_admin_emails' => ['admin@example.test'],
            'opsdeck.error_alerts.cooldown_seconds' => 300,
            'services.stripe.secret' => 'sk_live_unit_test',
            'services.stripe.prices.premium' => 'price_unit_test',
            'services.stripe.webhook_secret' => 'whsec_unit_test',
            'opsdeck.backup.database_dump_directory' => null,
        ]);
        Http::fake([
            'api.stripe.com/v1/prices/price_unit_test' => Http::response([
                'id' => 'price_unit_test',
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => $stripePriceAmount,
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
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
        $restoreTargetConfiguration->shouldReceive('isReadyForRestoreTest')->once()->andReturn($hasReadyRestoreTarget);
        $this->app->instance(BackupRestoreTargetConfiguration::class, $restoreTargetConfiguration);

        $migrationHealth = $this->mock(DatabaseMigrationHealthService::class);
        $migrationHealth->shouldReceive('pendingCount')
            ->once()
            ->andReturn($hasMigrationRepository ? ($hasPendingMigrations ? 1 : 0) : null);
        $this->app->instance(DatabaseMigrationHealthService::class, $migrationHealth);

        $queueHealth = $this->mock(QueueHealthService::class);
        $queueHealth->shouldReceive('importTimeoutStatus')->zeroOrMoreTimes()->andReturn($hasSafeQueueJobTimeout ? 'ok' : 'invalid');
        $queueHealth->shouldReceive('workerStatus')->zeroOrMoreTimes()->andReturn($hasFreshQueueWorker ? 'ok' : 'stale');
        $this->app->instance(QueueHealthService::class, $queueHealth);
    }
}
