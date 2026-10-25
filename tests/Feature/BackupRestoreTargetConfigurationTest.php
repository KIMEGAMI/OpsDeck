<?php

namespace Tests\Feature;

use App\Services\BackupRestoreTargetConfiguration;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Pdo\Mysql;
use RuntimeException;
use Tests\TestCase;

class BackupRestoreTargetConfigurationTest extends TestCase
{
    private const MYSQL_ACCESS_DENIED_ERROR = 1044;

    private const MYSQL_UNKNOWN_DATABASE_ERROR = 1049;

    private const MYSQL_USER_AUTHENTICATION_ERROR = 1045;

    public function test_ready_target_is_reachable_and_empty(): void
    {
        $this->configureSafeTarget();
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getTableListing')->once()->with('opsdeck_restore_test_ready', false)->andReturn([]);
        $schema->shouldReceive('getViews')->once()->with('opsdeck_restore_test_ready')->andReturn([]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_ready');
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.ROUTINES')), ['opsdeck_restore_test_ready'])
            ->andReturnNull();
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.EVENTS')), ['opsdeck_restore_test_ready'])
            ->andReturnNull();
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertSame('ready', app(BackupRestoreTargetConfiguration::class)->readinessStatus());
    }

    public function test_target_with_existing_tables_reports_that_it_is_not_empty(): void
    {
        $this->configureSafeTarget();
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getTableListing')->once()->andReturn(['existing_table']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_ready');
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertSame('target_not_empty', app(BackupRestoreTargetConfiguration::class)->readinessStatus());
    }

    public function test_unreachable_target_reports_connection_unavailable_without_exception_details(): void
    {
        $this->configureSafeTarget();
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once()->andThrow(new RuntimeException('DB_PASSWORD_SENTINEL'));
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertSame('connection_unavailable', app(BackupRestoreTargetConfiguration::class)->readinessStatus());
    }

    public function test_rejected_restore_credentials_have_a_secret_free_diagnostic(): void
    {
        $this->assertPdoFailureStatus(self::MYSQL_USER_AUTHENTICATION_ERROR, 'credentials_rejected');
    }

    public function test_restore_database_permission_failure_has_a_specific_diagnostic(): void
    {
        $this->assertPdoFailureStatus(self::MYSQL_ACCESS_DENIED_ERROR, 'database_access_denied');
    }

    public function test_missing_restore_database_has_a_specific_diagnostic(): void
    {
        $this->assertPdoFailureStatus(self::MYSQL_UNKNOWN_DATABASE_ERROR, 'database_not_found');
    }

    public function test_unsafe_target_reports_unsafe_configuration_without_opening_a_database_connection(): void
    {
        $this->configureSafeTarget();
        config(['database.connections.backup_restore.database' => 'opsdeck']);
        DB::shouldReceive('connection')->never();

        $this->assertSame('unsafe_configuration', app(BackupRestoreTargetConfiguration::class)->readinessStatus());
        $this->assertSame([
            'restore_database_name_must_use_reserved_prefix',
            'restore_database_must_differ_from_application_database',
        ], app(BackupRestoreTargetConfiguration::class)->configurationIssues());
    }

    public function test_ready_target_is_reachable_and_empty_legacy_boolean_remains_true(): void
    {
        $this->configureSafeTarget();
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getTableListing')->once()->with('opsdeck_restore_test_ready', false)->andReturn([]);
        $schema->shouldReceive('getViews')->once()->with('opsdeck_restore_test_ready')->andReturn([]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_ready');
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.ROUTINES')), ['opsdeck_restore_test_ready'])
            ->andReturnNull();
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.EVENTS')), ['opsdeck_restore_test_ready'])
            ->andReturnNull();
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertTrue(app(BackupRestoreTargetConfiguration::class)->isReadyForRestoreTest());
    }

    public function test_target_with_existing_tables_is_not_ready(): void
    {
        $this->configureSafeTarget();
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getTableListing')->once()->with('opsdeck_restore_test_ready', false)->andReturn(['existing_table']);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_ready');
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertFalse(app(BackupRestoreTargetConfiguration::class)->isReadyForRestoreTest());
    }

    public function test_target_with_existing_views_is_not_ready(): void
    {
        $this->configureSafeTarget();
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getTableListing')->once()->with('opsdeck_restore_test_ready', false)->andReturn([]);
        $schema->shouldReceive('getViews')->once()->with('opsdeck_restore_test_ready')->andReturn([['name' => 'existing_view']]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_ready');
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertFalse(app(BackupRestoreTargetConfiguration::class)->isReadyForRestoreTest());
    }

    public function test_target_with_existing_stored_routines_is_not_ready(): void
    {
        $this->configureSafeTarget();
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getTableListing')->once()->with('opsdeck_restore_test_ready', false)->andReturn([]);
        $schema->shouldReceive('getViews')->once()->with('opsdeck_restore_test_ready')->andReturn([]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_ready');
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.ROUTINES')), ['opsdeck_restore_test_ready'])
            ->andReturn((object) ['exists' => 1]);
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertSame('target_not_empty', app(BackupRestoreTargetConfiguration::class)->readinessStatus());
    }

    public function test_target_with_existing_scheduled_events_is_not_ready(): void
    {
        $this->configureSafeTarget();
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getTableListing')->once()->with('opsdeck_restore_test_ready', false)->andReturn([]);
        $schema->shouldReceive('getViews')->once()->with('opsdeck_restore_test_ready')->andReturn([]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_ready');
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.ROUTINES')), ['opsdeck_restore_test_ready'])
            ->andReturnNull();
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.EVENTS')), ['opsdeck_restore_test_ready'])
            ->andReturn((object) ['exists' => 1]);
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertSame('target_not_empty', app(BackupRestoreTargetConfiguration::class)->readinessStatus());
    }

    public function test_target_metadata_permission_errors_fail_closed_without_leaking_details(): void
    {
        $this->configureSafeTarget();
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getTableListing')->once()->with('opsdeck_restore_test_ready', false)->andReturn([]);
        $schema->shouldReceive('getViews')->once()->with('opsdeck_restore_test_ready')->andReturn([]);
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once();
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_ready');
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.ROUTINES')), ['opsdeck_restore_test_ready'])
            ->andThrow(new RuntimeException('sensitive routine privilege detail'));
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertSame('connection_unavailable', app(BackupRestoreTargetConfiguration::class)->readinessStatus());
    }

    public function test_unreachable_target_is_not_ready(): void
    {
        $this->configureSafeTarget();
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')->once()->andThrow(new RuntimeException('DB_PASSWORD_SENTINEL'));
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $this->assertFalse(app(BackupRestoreTargetConfiguration::class)->isReadyForRestoreTest());
    }

    public function test_unsafe_target_fails_without_opening_a_database_connection(): void
    {
        $this->configureSafeTarget();
        config(['database.connections.backup_restore.database' => 'opsdeck']);
        DB::shouldReceive('connection')->never();

        $this->assertFalse(app(BackupRestoreTargetConfiguration::class)->isReadyForRestoreTest());
    }

    public function test_remote_target_with_disabled_server_certificate_verification_is_unsafe(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('PDO MySQL is required to verify remote restore TLS options.');
        }

        $this->configureSafeTarget();
        config([
            'database.connections.backup_restore.host' => 'restore.example.test',
            'database.connections.backup_restore.options' => [
                Mysql::ATTR_SSL_CA => 'C:\\certificates\\restore-ca.pem',
                Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
            ],
        ]);
        DB::shouldReceive('connection')->never();

        $this->assertFalse(app(BackupRestoreTargetConfiguration::class)->isSafelyConfigured());
        $this->assertContains(
            'remote_restore_database_requires_verified_tls',
            app(BackupRestoreTargetConfiguration::class)->configurationIssues(),
        );
    }

    public function test_remote_target_with_server_certificate_verification_enabled_is_safe(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('PDO MySQL is required to verify remote restore TLS options.');
        }

        $this->configureSafeTarget();
        config([
            'database.connections.backup_restore.host' => 'restore.example.test',
            'database.connections.backup_restore.options' => [
                Mysql::ATTR_SSL_CA => 'C:\\certificates\\restore-ca.pem',
                Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
            ],
        ]);
        DB::shouldReceive('connection')->never();

        $this->assertTrue(app(BackupRestoreTargetConfiguration::class)->isSafelyConfigured());
    }

    private function configureSafeTarget(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.database' => 'opsdeck',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck_restore_test_ready',
                'username' => 'restore_user',
                'host' => '127.0.0.1',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
        ]);
    }

    private function assertPdoFailureStatus(int $driverErrorCode, string $expectedStatus): void
    {
        $this->configureSafeTarget();
        $driverException = new \PDOException('DB_PASSWORD_SENTINEL', $driverErrorCode);
        $driverException->errorInfo = ['HY000', $driverErrorCode, 'DB_PASSWORD_SENTINEL'];
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getPdo')
            ->once()
            ->andThrow(new RuntimeException('DB_PASSWORD_SENTINEL', 0, $driverException));
        DB::shouldReceive('connection')->once()->with('backup_restore')->andReturn($connection);

        $status = app(BackupRestoreTargetConfiguration::class)->readinessStatus();

        $this->assertSame($expectedStatus, $status);
        $this->assertStringNotContainsString('DB_PASSWORD_SENTINEL', $status);
    }
}
