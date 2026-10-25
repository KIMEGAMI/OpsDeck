<?php

namespace Tests\Feature\Console\Commands;

use App\Services\DatabaseDumpBinaryLocator;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class OpsDeckBackupRestoreTestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_weekly_mysql_restore_test_is_scheduled_after_the_daily_backup(): void
    {
        $restoreTestEvent = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'opsdeck:backup:restore-test'));

        $this->assertNotNull($restoreTestEvent);
        $this->assertSame('0 3 * * 1', $restoreTestEvent->expression);
        $this->assertSame(['production'], $restoreTestEvent->environments);
        $this->assertTrue($restoreTestEvent->onOneServer);
        $this->assertTrue($restoreTestEvent->withoutOverlapping);
    }

    public function test_daily_backup_runs_once_across_servers_without_overlapping(): void
    {
        $backupEvent = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_ends_with(trim((string) $event->command), 'opsdeck:backup'));

        $this->assertNotNull($backupEvent);
        $this->assertSame('0 2 * * *', $backupEvent->expression);
        $this->assertTrue($backupEvent->onOneServer);
        $this->assertTrue($backupEvent->withoutOverlapping);
    }

    public function test_production_restore_test_uses_the_newest_local_backup_manifest(): void
    {
        $this->app['env'] = 'production';
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $olderBackup = $backupRoot.DIRECTORY_SEPARATOR.'older';
        $latestBackup = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($olderBackup);
        File::ensureDirectoryExists($latestBackup);
        File::put($olderBackup.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'created_at' => '2026-10-01T03:00:00+09:00',
            'database' => ['driver' => 'mysql', 'file' => 'database.sql'],
            'evidence_files' => [],
            'evidence_sha256' => [],
        ], JSON_THROW_ON_ERROR));
        File::put($latestBackup.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'created_at' => '2026-10-08T03:00:00+09:00',
            'database' => ['driver' => 'mysql', 'file' => '../outside.sql'],
            'evidence_files' => [],
            'evidence_sha256' => [],
        ], JSON_THROW_ON_ERROR));
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => false,
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.database' => 'opsdeck',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck_restore_test_primary',
                'username' => 'restore_user',
                'host' => '127.0.0.1',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
        ]);
        $defaultConnection = $this->mock(Connection::class);
        $defaultConnection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck');
        DB::shouldReceive('connection')->once()->with('mysql')->andReturn($defaultConnection);
        try {
            $this->artisan('opsdeck:backup:restore-test')
                ->expectsOutput('DBバックアップのパスが不正です。')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_restore_test_without_a_path_fails_when_no_backup_is_available(): void
    {
        config(['opsdeck.backup.path' => storage_path('framework/testing-backups/'.Str::uuid())]);

        $this->artisan('opsdeck:backup:restore-test')
            ->expectsOutput('No complete backup is available for the restore test.')
            ->assertExitCode(1);
    }

    public function test_production_restore_test_reports_missing_local_backup_when_remote_storage_is_disabled(): void
    {
        $this->app['env'] = 'production';
        config([
            'opsdeck.backup.path' => storage_path('framework/testing-backups/'.Str::uuid()),
            'opsdeck.backup.remote_enabled' => false,
        ]);

        $this->artisan('opsdeck:backup:restore-test')
            ->expectsOutput('No complete backup is available for the restore test.')
            ->assertExitCode(1);
    }

    public function test_production_restore_test_refuses_remote_backups(): void
    {
        $this->app['env'] = 'production';
        config([
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
        ]);
        Storage::fake('opsdeck_backups');

        $this->artisan('opsdeck:backup:restore-test')
            ->expectsOutput('Backups are configured for local-only storage. Set OPSDECK_BACKUP_REMOTE_ENABLED=false.')
            ->assertExitCode(1);

        $this->assertSame([], Storage::disk('opsdeck_backups')->allFiles());
    }

    public function test_restore_test_without_a_path_reads_remote_backup_instead_of_verifying_an_empty_local_path(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'opsdeck',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck_restore_test_primary',
                'username' => 'restore_user',
                'host' => '127.0.0.1',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.allow_remote_storage_in_tests' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
            'opsdeck.backup.remote_retention_days' => 30,
            'filesystems.disks.opsdeck_backups' => [
                'driver' => 's3',
                'key' => 'fake-access-key',
                'secret' => 'fake-secret-key',
                'region' => 'auto',
                'bucket' => 'fake-private-backups',
                'endpoint' => 'https://s3.example.test',
            ],
        ]);
        Storage::fake('opsdeck_backups');
        $defaultConnection = $this->mock(Connection::class);
        $defaultConnection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck');
        DB::shouldReceive('connection')->once()->with('mysql')->andReturn($defaultConnection);
        $restoreCheckQuery = $this->mock(Builder::class);
        $restoreCheckQuery->shouldReceive('insert')->once()->andReturnTrue();
        DB::shouldReceive('table')->once()->with('backup_restore_checks')->andReturn($restoreCheckQuery);

        $this->artisan('opsdeck:backup:restore-test')
            ->expectsOutput('No complete remote backup is available for the restore test.')
            ->assertExitCode(1);
    }

    public function test_restore_test_refuses_remote_backup_in_testing_without_fake_storage_opt_in(): void
    {
        config([
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.allow_remote_storage_in_tests' => false,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
        ]);
        Storage::fake('opsdeck_backups');

        $this->artisan('opsdeck:backup:restore-test')
            ->expectsOutput('Backups are configured for local-only storage. Set OPSDECK_BACKUP_REMOTE_ENABLED=false.')
            ->assertExitCode(1);

        $this->assertSame([], Storage::disk('opsdeck_backups')->allFiles());
    }

    public function test_restore_test_fails_closed_when_dedicated_database_is_not_configured(): void
    {
        config([
            'opsdeck.backup.restore_connection' => 'backup_restore',
            'database.connections.backup_restore.database' => null,
        ]);

        $this->artisan('opsdeck:backup:restore-test', ['path' => 'missing-backup'])
            ->expectsOutput('A dedicated restore-test database is not safely configured.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('backup_restore_checks', ['status' => 'failed']);
    }

    public function test_restore_test_refuses_the_application_database_connection_alias(): void
    {
        config([
            'database.default' => 'mysql',
            'opsdeck.backup.restore_connection' => 'mysql',
        ]);

        $this->artisan('opsdeck:backup:restore-test', ['path' => 'missing-backup'])
            ->expectsOutput('A dedicated restore-test database is not safely configured.')
            ->assertExitCode(1);
    }

    public function test_restore_test_requires_a_prefixed_database_and_separate_user(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck',
                'username' => 'restore_user',
                'host' => '127.0.0.1',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
        ]);

        $this->artisan('opsdeck:backup:restore-test', ['path' => 'missing-backup'])
            ->expectsOutput('A dedicated restore-test database is not safely configured.')
            ->assertExitCode(1);
    }

    public function test_restore_test_requires_a_separate_user_and_verified_tls_for_remote_targets(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck_restore_test_primary',
                'username' => 'restore_user',
                'host' => 'db.example.test',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
        ]);

        $this->artisan('opsdeck:backup:restore-test', ['path' => 'missing-backup'])
            ->expectsOutput('A dedicated restore-test database is not safely configured.')
            ->assertExitCode(1);
    }

    public function test_restore_test_compares_the_actual_database_name_before_connecting_to_the_target(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'opsdeck',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck_restore_test_primary',
                'username' => 'restore_user',
                'host' => '127.0.0.1',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
        ]);
        $defaultConnection = $this->mock(Connection::class);
        $defaultConnection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck_restore_test_primary');
        DB::shouldReceive('connection')->once()->with('mysql')->andReturn($defaultConnection);

        $this->artisan('opsdeck:backup:restore-test', ['path' => 'missing-backup'])
            ->expectsOutput('A dedicated restore-test database is not safely configured.')
            ->assertExitCode(1);
    }

    public function test_restore_test_accepts_a_separate_local_scratch_target_before_verifying_the_backup(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'opsdeck',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck_restore_test_primary',
                'username' => 'restore_user',
                'host' => '127.0.0.1',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
        ]);
        $defaultConnection = $this->mock(Connection::class);
        $defaultConnection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck');
        DB::shouldReceive('connection')->once()->with('mysql')->andReturn($defaultConnection);

        $this->artisan('opsdeck:backup:restore-test', ['path' => 'missing-backup'])
            ->expectsOutput('manifest.jsonがありません。')
            ->assertExitCode(1);
    }

    public function test_restore_test_rejects_a_legacy_plaintext_mysql_dump(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupRoot);
        File::put($backupRoot.DIRECTORY_SEPARATOR.'database.sql', 'CREATE TABLE unexpected (id INT);');
        File::put($backupRoot.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'database' => ['driver' => 'mysql', 'file' => 'database.sql'],
            'evidence_files' => [],
        ], JSON_THROW_ON_ERROR));
        config([
            'opsdeck.backup.encryption_key' => base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.database' => 'opsdeck',
            'database.connections.mysql.username' => 'app_user',
            'database.connections.backup_restore' => [
                'driver' => 'mysql',
                'database' => 'opsdeck_restore_test_primary',
                'username' => 'restore_user',
                'host' => '127.0.0.1',
                'port' => '3306',
                'unix_socket' => '',
                'options' => [],
            ],
            'opsdeck.backup.restore_connection' => 'backup_restore',
        ]);
        $defaultConnection = $this->mock(Connection::class);
        $defaultConnection->shouldReceive('getDatabaseName')->once()->andReturn('opsdeck');
        DB::shouldReceive('connection')->once()->with('mysql')->andReturn($defaultConnection);

        try {
            $this->artisan('opsdeck:backup:restore-test', ['path' => $backupRoot])
                ->expectsOutput('The backup manifest is not authenticated for a MySQL restore test. Create a new verified backup and retry.')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_restore_client_locator_uses_the_configured_client_directory(): void
    {
        $clientDirectory = 'C:\\Program Files\\MySQL\\bin';
        config(['opsdeck.backup.database_dump_directory' => $clientDirectory]);
        $executableFinder = $this->mock(ExecutableFinder::class);
        $executableFinder->shouldReceive('find')
            ->once()
            ->with('mysql', null, [$clientDirectory])
            ->andReturn($clientDirectory.'\\mysql.exe');
        $this->app->instance(ExecutableFinder::class, $executableFinder);

        $this->assertSame($clientDirectory.'\\mysql.exe', app(DatabaseDumpBinaryLocator::class)->findRestoreClient('mysql'));
    }
}
