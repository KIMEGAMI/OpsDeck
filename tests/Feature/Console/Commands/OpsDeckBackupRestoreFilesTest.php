<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\BackupEncryptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpsDeckBackupRestoreFilesTest extends TestCase
{
    private ?string $backupRoot = null;

    private ?string $databasePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'opsdeck-restore-files-');
        if (! is_string($databasePath)) {
            $this->fail('Unable to create an isolated file-restore test database.');
        }
        $this->databasePath = $databasePath;
        config([
            'database.connections.backup_restore_files_test' => [
                ...config('database.connections.sqlite'),
                'database' => $databasePath,
            ],
            'database.default' => 'backup_restore_files_test',
        ]);
        DB::purge('backup_restore_files_test');
        $this->artisan('migrate:fresh', ['--database' => 'backup_restore_files_test', '--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        if (is_string($this->databasePath)) {
            DB::disconnect('backup_restore_files_test');
            DB::purge('backup_restore_files_test');
            File::delete($this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm');
        }

        if (is_string($this->backupRoot) && File::isDirectory($this->backupRoot)) {
            File::deleteDirectory($this->backupRoot);
        }

        parent::tearDown();
    }

    public function test_dry_run_does_not_write_and_confirmed_restore_recovers_missing_opsdeck_files(): void
    {
        $backup = $this->createBackup(false);
        Storage::disk('local')->delete([$backup['evidence_path'], $backup['import_path']]);

        $this->artisan('opsdeck:backup:restore-files', ['path' => $backup['generation']])
            ->assertExitCode(0)
            ->expectsOutput('Backup files verified: 2. Already present and identical: 0. Missing: 2.')
            ->expectsOutput('Dry-run only. No files were written. Add --apply to restore missing files.');
        Storage::disk('local')->assertMissing($backup['evidence_path']);
        Storage::disk('local')->assertMissing($backup['import_path']);

        $this->artisan('opsdeck:backup:restore-files', ['path' => $backup['generation'], '--apply' => true])
            ->expectsConfirmation('Restore the missing files to their recorded OpsDeck private storage locations? Existing files will not be overwritten.', 'yes')
            ->assertExitCode(0)
            ->expectsOutput('Restored 2 missing OpsDeck files. Existing files were not changed.');

        Storage::disk('local')->assertExists($backup['evidence_path']);
        Storage::disk('local')->assertExists($backup['import_path']);
        $this->assertSame($backup['evidence_content'], Storage::disk('local')->get($backup['evidence_path']));
        $this->assertSame($backup['import_content'], Storage::disk('local')->get($backup['import_path']));
    }

    public function test_encrypted_backup_payloads_are_decrypted_when_restoring(): void
    {
        $backup = $this->createBackup(true);
        Storage::disk('local')->delete([$backup['evidence_path'], $backup['import_path']]);

        $this->artisan('opsdeck:backup:restore-files', ['path' => $backup['generation'], '--apply' => true])
            ->expectsConfirmation('Restore the missing files to their recorded OpsDeck private storage locations? Existing files will not be overwritten.', 'yes')
            ->assertExitCode(0);

        $this->assertSame($backup['evidence_content'], Storage::disk('local')->get($backup['evidence_path']));
        $this->assertSame($backup['import_content'], Storage::disk('local')->get($backup['import_path']));
    }

    public function test_conflicting_existing_file_aborts_before_any_missing_file_is_written(): void
    {
        $backup = $this->createBackup(false);
        Storage::disk('local')->delete($backup['evidence_path']);
        Storage::disk('local')->put($backup['import_path'], 'different content');

        $this->artisan('opsdeck:backup:restore-files', ['path' => $backup['generation'], '--apply' => true])
            ->assertExitCode(1)
            ->expectsOutput('File restore preflight failed. Check the verified backup, restored database, and OpsDeck private storage configuration. No files were written.');

        Storage::disk('local')->assertMissing($backup['evidence_path']);
        $this->assertSame('different content', Storage::disk('local')->get($backup['import_path']));
    }

    public function test_file_restore_refuses_backup_when_database_references_do_not_match(): void
    {
        $backup = $this->createBackup(false);
        Storage::disk('local')->delete([$backup['evidence_path'], $backup['import_path']]);
        $backup['evidence']->update(['storage_path' => 'evidence/changed.pdf']);

        $this->artisan('opsdeck:backup:restore-files', ['path' => $backup['generation'], '--apply' => true])
            ->assertExitCode(1)
            ->expectsOutput('File restore preflight failed. Check the verified backup, restored database, and OpsDeck private storage configuration. No files were written.');

        Storage::disk('local')->assertMissing($backup['evidence_path']);
        Storage::disk('local')->assertMissing($backup['import_path']);
    }

    public function test_file_restore_refuses_storage_references_outside_opsdeck(): void
    {
        $backup = $this->createBackup(false);
        Storage::disk('local')->delete([$backup['evidence_path'], $backup['import_path']]);

        $indexPath = $backup['generation'].DIRECTORY_SEPARATOR.'evidence-index.json';
        $index = json_decode(File::get($indexPath), true, 512, JSON_THROW_ON_ERROR);
        $index[0]['disk'] = 'furimadeck_product_images';
        File::put($indexPath, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $manifestPath = $backup['generation'].DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['evidence_index_sha256'] = hash_file('sha256', $indexPath);
        $manifest['manifest_hmac_sha256'] = app(BackupEncryptionService::class)->manifestHmac($manifest);
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->artisan('opsdeck:backup:restore-files', ['path' => $backup['generation'], '--apply' => true])
            ->assertExitCode(1)
            ->expectsOutput('File restore preflight failed. Check the verified backup, restored database, and OpsDeck private storage configuration. No files were written.');

        Storage::disk('local')->assertMissing($backup['evidence_path']);
        Storage::disk('local')->assertMissing($backup['import_path']);
    }

    /** @return array{generation: string, evidence: Evidence, evidence_path: string, import_path: string, evidence_content: string, import_content: string} */
    private function createBackup(bool $encrypted): array
    {
        $this->backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'opsdeck.backup.path' => $this->backupRoot,
            'opsdeck.backup.encryption_enabled' => $encrypted,
            'opsdeck.backup.encryption_key' => base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
            'opsdeck.backup.remote_enabled' => false,
            'opsdeck.evidence.disk' => 'local',
            'opsdeck.import.disk' => 'local',
        ]);
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $evidencePath = 'evidence/'.Str::uuid().'.pdf';
        $importPath = 'imports/'.Str::uuid().'.csv';
        $evidenceContent = 'opsdeck evidence recovery fixture';
        $importContent = "資産番号,種類\nRESTORE-PC-001,pc\n";
        Storage::disk('local')->put($evidencePath, $evidenceContent);
        Storage::disk('local')->put($importPath, $importContent);

        $evidence = Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => '復元テスト証跡',
            'evidence_type' => 'policy',
            'storage_disk' => 'local',
            'storage_path' => $evidencePath,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($evidenceContent),
            'uploaded_by' => $user->id,
        ]);
        ImportBatch::factory()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '復元テスト台帳',
            'source_file_path' => $importPath,
            'source_storage_disk' => 'local',
            'source_file_size_bytes' => strlen($importContent),
        ]);

        $this->artisan('opsdeck:backup')->assertExitCode(0);
        $generations = File::directories($this->backupRoot);
        $this->assertCount(1, $generations);

        return [
            'generation' => $generations[0],
            'evidence' => $evidence,
            'evidence_path' => $evidencePath,
            'import_path' => $importPath,
            'evidence_content' => $evidenceContent,
            'import_content' => $importContent,
        ];
    }
}
