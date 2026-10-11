<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    public function test_backup_verification_rejects_evidence_files_that_were_changed_after_backup(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        Storage::fake('backup-evidence-test');
        config(['opsdeck.backup.path' => $backupRoot, 'opsdeck.evidence.disk' => 'backup-evidence-test']);
        Storage::disk('backup-evidence-test')->put('contracts/proof.pdf', 'evidence-content');

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $directories = File::directories($backupRoot);
            $backupDirectory = $directories[0];
            $manifest = json_decode(File::get($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(hash('sha256', File::get($backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite')), $manifest['database_sha256']);
            $this->assertCount(1, $manifest['evidence_sha256']);
            $this->assertSame(64, strlen($manifest['evidence_sha256'][0]));
            File::put($backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'proof.pdf', 'changed-evidence-content');

            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verification_rejects_a_changed_mysql_dump(): void
    {
        $backupDirectory = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupDirectory);
        $dump = 'CREATE TABLE example (id INT);';
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', $dump);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'database' => ['driver' => 'mysql', 'file' => 'database.sql'],
            'database_sha256' => hash('sha256', $dump),
            'evidence_files' => [],
            'evidence_sha256' => [],
        ], JSON_THROW_ON_ERROR));
        File::append($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', '-- changed after backup');

        try {
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])
                ->expectsOutput('DBバックアップの整合性検証に失敗しました。')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupDirectory);
        }
    }

    public function test_backup_verification_rejects_a_malformed_database_checksum(): void
    {
        $backupDirectory = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'CREATE TABLE example (id INT);');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'database' => ['driver' => 'mysql', 'file' => 'database.sql'],
            'database_sha256' => 'not-a-sha256-checksum',
            'evidence_files' => [],
            'evidence_sha256' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])
                ->expectsOutput('manifest.jsonの形式が不正です。')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupDirectory);
        }
    }

    public function test_sqlite_backup_includes_committed_changes_in_the_write_ahead_log(): void
    {
        $databasePath = storage_path('framework/testing-databases/'.Str::uuid().'.sqlite');
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists(dirname($databasePath));
        File::put($databasePath, '');
        config(['database.connections.sqlite.database' => $databasePath, 'opsdeck.backup.path' => $backupRoot]);
        DB::purge('sqlite');

        try {
            DB::connection('sqlite')->statement('PRAGMA journal_mode=WAL');
            DB::statement('CREATE TABLE backup_probe (value TEXT NOT NULL)');
            DB::table('backup_probe')->insert(['value' => 'committed-wal-value']);

            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $directories = File::directories($backupRoot);
            $backupDatabase = $directories[0].DIRECTORY_SEPARATOR.'database.sqlite';
            $restoredDatabase = new PDO('sqlite:'.$backupDatabase);
            $restoredValue = $restoredDatabase->query('SELECT value FROM backup_probe')->fetchColumn();

            $this->assertSame('committed-wal-value', $restoredValue);
        } finally {
            DB::purge('sqlite');
            File::deleteDirectory($backupRoot);
            File::delete($databasePath, $databasePath.'-wal', $databasePath.'-shm');
        }
    }

    public function test_backup_command_creates_database_and_manifest_without_deleting_source_data(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        $this->artisan('opsdeck:backup')->assertExitCode(0);
        $directories = File::directories($backupRoot);
        $this->assertCount(1, $directories);
        $this->assertFileExists($directories[0].DIRECTORY_SEPARATOR.'manifest.json');
        $this->assertFileExists($directories[0].DIRECTORY_SEPARATOR.'database.sqlite');
        $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(0);

        File::deleteDirectory($backupRoot);
    }

    public function test_backup_failure_removes_partial_data_without_exposing_internal_errors(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'opsdeck.backup.path' => $backupRoot,
            'database.connections.sqlite.driver' => 'unsupported',
        ]);

        try {
            $this->artisan('opsdeck:backup')
                ->expectsOutput('Backup failed. Incomplete backup data was removed.')
                ->assertExitCode(1);

            $this->assertSame([], File::directories($backupRoot));
            $this->assertSame([], File::allFiles($backupRoot));
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verify_rejects_manifest_path_traversal(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        $this->artisan('opsdeck:backup')->assertExitCode(0);
        $directories = File::directories($backupRoot);
        $manifestPath = $directories[0].DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['evidence_files'][] = '../manifest.json';
        $manifest['evidence_sha256'][] = hash('sha256', 'unexpected');
        File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(1);

        File::deleteDirectory($backupRoot);
    }

    public function test_backup_verify_accepts_legacy_manifest_without_checksums(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        $this->artisan('opsdeck:backup')->assertExitCode(0);
        $directories = File::directories($backupRoot);
        $manifestPath = $directories[0].DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['database_sha256'], $manifest['evidence_sha256']);
        File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(0);

        File::deleteDirectory($backupRoot);
    }

    public function test_backup_verify_rejects_malformed_manifest_without_throwing(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupRoot);
        File::put($backupRoot.DIRECTORY_SEPARATOR.'manifest.json', '{"database":null}');

        $this->artisan('opsdeck:backup:verify', ['path' => $backupRoot])->assertExitCode(1);

        File::deleteDirectory($backupRoot);
    }

    public function test_backup_verify_rejects_unknown_database_driver(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        $this->artisan('opsdeck:backup')->assertExitCode(0);
        $directories = File::directories($backupRoot);
        $manifestPath = $directories[0].DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['database']['driver'] = 'unknown';
        File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(1);

        File::deleteDirectory($backupRoot);
    }
}
