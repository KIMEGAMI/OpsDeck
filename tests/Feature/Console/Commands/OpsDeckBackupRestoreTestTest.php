<?php

namespace Tests\Feature\Console\Commands;

use App\Services\DatabaseDumpBinaryLocator;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    public function test_restore_test_without_a_path_uses_the_newest_backup_manifest(): void
    {
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
            ->expectsOutput('No backup is available for the restore test.')
            ->assertExitCode(1);
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
