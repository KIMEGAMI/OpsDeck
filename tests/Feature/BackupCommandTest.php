<?php

namespace Tests\Feature;

use App\Console\Commands\OpsDeckBackupRestoreTest;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\BackupEncryptionService;
use App\Services\BackupHealthService;
use App\Services\DatabaseSchemaFingerprint;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('sqlite-only')]
class BackupCommandTest extends TestCase
{
    private const TEST_BASE_UMASK = 0027;

    private const GROUP_AND_WORLD_PERMISSION_MASK = 0077;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Backup command tests must use the isolated SQLite test database.');
        config([
            'opsdeck.backup.encryption_enabled' => true,
            'opsdeck.backup.encryption_key' => base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
            'opsdeck.backup.allow_remote_storage_in_tests' => true,
        ]);

        if (Schema::hasTable('evidence')) {
            Evidence::query()->delete();
        }

        if (Schema::hasTable('import_batches') && Schema::hasColumn('import_batches', 'source_file_path')) {
            $sourceStorageDiskColumnExists = Schema::hasColumn('import_batches', 'source_storage_disk');
            $sourceFileReferenceQuery = DB::table('import_batches')->whereNotNull('source_file_path');

            if ($sourceStorageDiskColumnExists) {
                $sourceFileReferenceQuery->update(['source_file_path' => null, 'source_storage_disk' => null]);
            } else {
                $sourceFileReferenceQuery->update(['source_file_path' => null]);
            }
        }
    }

    public function test_backup_encryption_round_trips_an_empty_and_non_empty_stream(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupRoot);
        $encryption = app(BackupEncryptionService::class);

        try {
            $plaintextCases = ['', 'evidence content', str_repeat('x', BackupEncryptionService::CHUNK_BYTES + 1)];
            foreach ($plaintextCases as $index => $plaintext) {
                $encryptedPath = $backupRoot.DIRECTORY_SEPARATOR.$index.'.enc';
                $encryption->encryptString($plaintext, $encryptedPath);
                $decrypted = '';
                foreach ($encryption->decryptedChunks($encryptedPath) as $chunk) {
                    $decrypted .= $chunk;
                }
                $this->assertSame($plaintext, $decrypted);
            }

            $producerPlaintext = str_repeat('streamed dump', 8000);
            $producerPath = $backupRoot.DIRECTORY_SEPARATOR.'producer.enc';
            $encryption->encryptFromCallback($producerPath, function (Closure $writeChunk) use ($producerPlaintext): void {
                $writeChunk(substr($producerPlaintext, 0, BackupEncryptionService::CHUNK_BYTES));
                $writeChunk(substr($producerPlaintext, BackupEncryptionService::CHUNK_BYTES));
            });
            $decryptedProducerContent = '';
            foreach ($encryption->decryptedChunks($producerPath) as $chunk) {
                $decryptedProducerContent .= $chunk;
            }
            $this->assertSame($producerPlaintext, $decryptedProducerContent);

            $oversizedProducerPlaintext = str_repeat('variable producer chunk', 4000);
            $oversizedProducerPath = $backupRoot.DIRECTORY_SEPARATOR.'oversized-producer.enc';
            $encryption->encryptFromCallback($oversizedProducerPath, function (Closure $writeChunk) use ($oversizedProducerPlaintext): void {
                $writeChunk($oversizedProducerPlaintext);
            });
            $decryptedOversizedProducerContent = '';
            foreach ($encryption->decryptedChunks($oversizedProducerPath) as $chunk) {
                $decryptedOversizedProducerContent .= $chunk;
            }
            $this->assertSame($oversizedProducerPlaintext, $decryptedOversizedProducerContent);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_decrypted_backup_chunks_can_be_streamed_to_a_process_input(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupRoot);
        $plaintext = str_repeat('INSERT INTO backup_probe VALUES (1);', 3000);
        $encryptedPath = $backupRoot.DIRECTORY_SEPARATOR.'database.sql.enc';
        $encryption = app(BackupEncryptionService::class);

        try {
            $encryption->encryptString($plaintext, $encryptedPath);
            $process = new Process([
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, hash("sha256", stream_get_contents(STDIN)));',
            ]);
            $process->setInput($encryption->decryptedChunks($encryptedPath));
            $process->run();

            $this->assertTrue($process->isSuccessful());
            $this->assertSame(hash('sha256', $plaintext), trim($process->getOutput()));
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_verified_backup_snapshot_rejects_a_different_encrypted_file(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupRoot);
        $encryption = app(BackupEncryptionService::class);
        $expectedPath = $backupRoot.DIRECTORY_SEPARATOR.'expected.enc';
        $replacementPath = $backupRoot.DIRECTORY_SEPARATOR.'replacement.enc';
        $encryption->encryptString('expected database dump', $expectedPath);
        $encryption->encryptString('other valid database dump', $replacementPath);

        try {
            $expectedChecksum = hash_file('sha256', $expectedPath);

            $this->assertIsString($expectedChecksum);
            $this->assertNull($encryption->createVerifiedSnapshot($replacementPath, $expectedChecksum));

            $snapshotPath = $encryption->createVerifiedSnapshot($expectedPath, $expectedChecksum);
            $this->assertIsString($snapshotPath);
            $this->assertSame($expectedChecksum, hash_file('sha256', $snapshotPath));
            $this->assertSame('expected database dump', implode('', iterator_to_array($encryption->decryptedChunks($snapshotPath))));
        } finally {
            if (isset($snapshotPath) && is_string($snapshotPath)) {
                @unlink($snapshotPath);
            }
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verification_rejects_evidence_files_that_were_changed_after_backup(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $legacyDisk = 'backup-evidence-legacy-test';
        $currentDisk = 'backup-evidence-current-test';
        Storage::fake($legacyDisk);
        Storage::fake($currentDisk);
        config(['opsdeck.backup.path' => $backupRoot, 'opsdeck.evidence.disk' => $currentDisk]);
        Storage::disk($legacyDisk)->put('contracts/proof.pdf', 'evidence-content');
        Storage::disk($currentDisk)->put('contracts/copy.pdf', 'evidence-content');
        Storage::disk($currentDisk)->put('contracts/unreferenced.pdf', 'must-not-be-backed-up');
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Legacy disk evidence',
            'evidence_type' => 'contract',
            'storage_disk' => $legacyDisk,
            'storage_path' => 'contracts/proof.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen('evidence-content'),
            'uploaded_by' => $user->id,
        ]);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Current disk evidence',
            'evidence_type' => 'contract',
            'storage_disk' => $currentDisk,
            'storage_path' => 'contracts/copy.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen('evidence-content'),
            'uploaded_by' => $user->id,
        ]);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Metadata-only evidence',
            'evidence_type' => 'policy',
            'storage_disk' => $currentDisk,
            'storage_path' => 'metadata-only',
            'mime_type' => 'application/x-opsdeck-metadata',
            'file_size' => 0,
            'uploaded_by' => $user->id,
        ]);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $directories = File::directories($backupRoot);
            $backupDirectory = $directories[0];
            $manifest = json_decode(File::get($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(hash_file('sha256', $backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite.enc'), $manifest['database_sha256']);
            $this->assertCount(2, $manifest['evidence_sha256']);
            $this->assertSame(64, strlen($manifest['evidence_sha256'][0]));
            $evidenceIndexContents = '';
            foreach (app(BackupEncryptionService::class)->decryptedChunks($backupDirectory.DIRECTORY_SEPARATOR.BackupEncryptionService::EVIDENCE_INDEX_FILE) as $chunk) {
                $evidenceIndexContents .= $chunk;
            }
            $this->assertSame([
                ['disk' => $legacyDisk, 'path' => 'contracts/proof.pdf'],
                ['disk' => $currentDisk, 'path' => 'contracts/copy.pdf'],
            ], json_decode($evidenceIndexContents, true, 512, JSON_THROW_ON_ERROR));
            $this->assertSame(BackupEncryptionService::ALGORITHM, $manifest['encryption']['algorithm']);
            $expectedPayloadBytes = (int) File::size($backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite.enc')
                + (int) File::size($backupDirectory.DIRECTORY_SEPARATOR.BackupEncryptionService::EVIDENCE_INDEX_FILE)
                + (int) File::size($backupDirectory.DIRECTORY_SEPARATOR.'import_sources_index.enc');
            foreach ($manifest['evidence_files'] as $file) {
                $expectedPayloadBytes += (int) File::size($backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.$file.'.enc');
            }
            $this->assertSame($expectedPayloadBytes, $manifest['payload_size_bytes']);
            $this->assertTrue(app(BackupEncryptionService::class)->hasValidManifestHmac($manifest));
            $backupReport = app(BackupHealthService::class)->report();
            $this->assertSame('ok', $backupReport['status']);
            $this->assertSame(1, $backupReport['backup_count']);
            $this->assertSame($expectedPayloadBytes, $backupReport['backup_payload_bytes']);
            $this->assertSame(0, $backupReport['unmeasured_backup_count']);
            $this->assertFileDoesNotExist($backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite');
            $this->assertFileDoesNotExist($backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'proof.pdf');
            $this->assertPrivateBackupPath($backupDirectory, true);
            $this->assertPrivateBackupPath($backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite.enc', false);
            $this->assertPrivateBackupPath($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', false);
            $this->assertPrivateBackupPath($backupDirectory.DIRECTORY_SEPARATOR.'evidence', true);
            $evidencePath = $backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.$manifest['evidence_files'][0].'.enc';
            $this->assertPrivateBackupPath($evidencePath, false);
            File::put($evidencePath, 'changed-evidence-content');

            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(1);
        } finally {
            Evidence::query()->where('organization_id', $organization->id)->delete();
            $organization->delete();
            $user->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_fails_when_a_database_referenced_evidence_object_is_missing(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        Storage::fake('missing-evidence-test');
        config(['opsdeck.backup.path' => $backupRoot, 'opsdeck.evidence.disk' => 'missing-evidence-test']);
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Missing evidence object',
            'evidence_type' => 'contract',
            'storage_disk' => 'missing-evidence-test',
            'storage_path' => 'contracts/missing.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
            'uploaded_by' => $user->id,
        ]);

        try {
            $this->artisan('opsdeck:backup')
                ->expectsOutput('Backup failed. Incomplete backup data was removed.')
                ->assertExitCode(1);

            $this->assertSame([], File::directories($backupRoot));
            $this->assertSame([], File::allFiles($backupRoot));
        } finally {
            Evidence::query()->where('organization_id', $organization->id)->delete();
            $organization->delete();
            $user->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_can_create_a_plaintext_snapshot_with_an_authenticated_manifest(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.encryption_enabled' => false,
            'opsdeck.backup.encryption_key' => null,
        ]);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $backupDirectory = File::directories($backupRoot)[0];
            $manifest = json_decode(File::get($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);

            $this->assertArrayNotHasKey('encryption', $manifest);
            $this->assertSame('application_key_v1', $manifest['manifest_hmac_key_source']);
            $this->assertSame('database.sqlite', $manifest['database']['file']);
            $this->assertFileExists($backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite');
            $this->assertSame('evidence-index.json', $manifest['evidence_index_file']);
            $this->assertSame([], json_decode(File::get($backupDirectory.DIRECTORY_SEPARATOR.'evidence-index.json'), true, 512, JSON_THROW_ON_ERROR));
            $this->assertSame('import_sources_index.json', $manifest['import_sources_index_file']);
            $this->assertSame([], json_decode(File::get($backupDirectory.DIRECTORY_SEPARATOR.'import_sources_index.json'), true, 512, JSON_THROW_ON_ERROR));
            $this->assertTrue(app(BackupEncryptionService::class)->hasValidManifestHmac($manifest));
            $this->assertSame(hash_file('sha256', $backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite'), $manifest['database_sha256']);
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(0);
            $this->assertSame('ok', app(BackupHealthService::class)->report()['status']);

            $manifest['database_sha256'] = str_repeat('0', 64);
            File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verifier_accepts_the_legacy_evidence_path_index_format(): void
    {
        $backupDirectory = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupDirectory.DIRECTORY_SEPARATOR.'evidence');
        $encryption = app(BackupEncryptionService::class);
        $databasePath = $backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        $database = new PDO('sqlite:'.$databasePath);
        $database->exec('CREATE TABLE legacy_backup_probe (id INTEGER PRIMARY KEY)');
        unset($database);

        $encryptedDatabasePath = $backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite.enc';
        $encryption->encryptFile($databasePath, $encryptedDatabasePath);
        File::delete($databasePath);
        $evidencePath = $backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.'00000001.enc';
        $encryption->encryptString('legacy evidence content', $evidencePath);
        $evidenceIndexPath = $backupDirectory.DIRECTORY_SEPARATOR.BackupEncryptionService::EVIDENCE_INDEX_FILE;
        $encryption->encryptString(json_encode(['contracts/legacy.pdf'], JSON_THROW_ON_ERROR), $evidenceIndexPath);
        $manifest = [
            'encryption' => [
                'algorithm' => BackupEncryptionService::ALGORITHM,
                'format_version' => BackupEncryptionService::FORMAT_VERSION,
            ],
            'database' => ['driver' => 'sqlite', 'file' => 'database.sqlite.enc'],
            'database_sha256' => hash_file('sha256', $encryptedDatabasePath),
            'evidence_files' => ['00000001'],
            'evidence_sha256' => [hash_file('sha256', $evidencePath)],
            'evidence_index_file' => BackupEncryptionService::EVIDENCE_INDEX_FILE,
            'evidence_index_sha256' => hash_file('sha256', $evidenceIndexPath),
        ];
        $manifest['manifest_hmac_sha256'] = $encryption->manifestHmac($manifest);

        try {
            File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(0);
        } finally {
            File::deleteDirectory($backupDirectory);
        }
    }

    public function test_encrypted_backup_verification_rejects_a_different_encryption_key(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $backupDirectory = File::directories($backupRoot)[0];
            config(['opsdeck.backup.encryption_key' => base64_encode(str_repeat('x', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES))]);

            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_encrypted_backup_verification_rejects_a_truncated_stream_even_when_its_checksum_is_updated(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $backupDirectory = File::directories($backupRoot)[0];
            $databasePath = $backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite.enc';
            $encryptedDatabase = File::get($databasePath);
            File::put($databasePath, substr($encryptedDatabase, 0, -1));

            $manifestPath = $backupDirectory.DIRECTORY_SEPARATOR.'manifest.json';
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['database_sha256'] = hash_file('sha256', $databasePath);
            $manifest['manifest_hmac_sha256'] = app(BackupEncryptionService::class)->manifestHmac($manifest);
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])
                ->expectsOutput('バックアップの暗号化認証に失敗しました。')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_fails_closed_when_encryption_key_is_missing(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot, 'opsdeck.backup.encryption_key' => null]);

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

    public function test_backup_verify_validates_an_optional_database_schema_fingerprint(): void
    {
        $backupDirectory = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupDirectory);
        $databasePath = $backupDirectory.DIRECTORY_SEPARATOR.'database.sql';
        $manifestPath = $backupDirectory.DIRECTORY_SEPARATOR.'manifest.json';
        $databaseDump = 'CREATE TABLE example (id INT);';
        $manifest = [
            'database' => ['driver' => 'mysql', 'file' => 'database.sql', 'schema_sha256' => hash('sha256', 'schema')],
            'database_sha256' => hash('sha256', $databaseDump),
            'evidence_files' => [],
        ];
        File::put($databasePath, $databaseDump);

        try {
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(0);

            $manifest['database']['schema_sha256'] = 'invalid';
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])
                ->expectsOutput('manifest.jsonの形式が不正です。')
                ->assertExitCode(1);

            $manifest['database']['schema_sha256'] = hash('sha256', 'schema');
            $manifest['database']['schema_fingerprint_version'] = DatabaseSchemaFingerprint::VERSION;
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(0);

            $manifest['database']['schema_fingerprint_version'] = DatabaseSchemaFingerprint::VERSION + 1;
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])
                ->expectsOutput('manifest.jsonの形式が不正です。')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupDirectory);
        }
    }

    public function test_encrypted_mysql_backup_requires_a_valid_database_schema_fingerprint(): void
    {
        $backupDirectory = storage_path('framework/testing-backups/'.Str::uuid());
        File::ensureDirectoryExists($backupDirectory);
        $databasePath = $backupDirectory.DIRECTORY_SEPARATOR.'database.sql.enc';
        $indexPath = $backupDirectory.DIRECTORY_SEPARATOR.BackupEncryptionService::EVIDENCE_INDEX_FILE;
        $manifestPath = $backupDirectory.DIRECTORY_SEPARATOR.'manifest.json';
        $encryption = app(BackupEncryptionService::class);
        $encryption->encryptString('CREATE TABLE example (id INT);', $databasePath);
        $encryption->encryptString('[]', $indexPath);
        $manifest = [
            'encryption' => [
                'algorithm' => BackupEncryptionService::ALGORITHM,
                'format_version' => BackupEncryptionService::FORMAT_VERSION,
            ],
            'database' => ['driver' => 'mysql', 'file' => 'database.sql.enc'],
            'database_sha256' => hash_file('sha256', $databasePath),
            'evidence_files' => [],
            'evidence_sha256' => [],
            'evidence_index_file' => BackupEncryptionService::EVIDENCE_INDEX_FILE,
            'evidence_index_sha256' => hash_file('sha256', $indexPath),
        ];

        try {
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])
                ->expectsOutput('manifest.jsonの形式が不正です。')
                ->assertExitCode(1);

            $manifest['database']['schema_sha256'] = hash('sha256', 'schema');
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(0);
        } finally {
            File::deleteDirectory($backupDirectory);
        }
    }

    public function test_sqlite_backup_includes_committed_changes_in_the_write_ahead_log(): void
    {
        $databasePath = storage_path('framework/testing-databases/'.Str::uuid().'.sqlite');
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $backupDatabase = null;
        $restoredDatabase = null;
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
            $backupDatabase = storage_path('framework/testing-databases/'.Str::uuid().'.decrypted.sqlite');
            app(BackupEncryptionService::class)->decryptFile($directories[0].DIRECTORY_SEPARATOR.'database.sqlite.enc', $backupDatabase);
            $restoredDatabase = new PDO('sqlite:'.$backupDatabase);
            $restoredValue = $restoredDatabase->query('SELECT value FROM backup_probe')->fetchColumn();

            $this->assertSame('committed-wal-value', $restoredValue);
            $restoredDatabase = null;
            File::delete($backupDatabase);
            $this->assertFileDoesNotExist($backupDatabase);
        } finally {
            $restoredDatabase = null;
            if (is_string($backupDatabase)) {
                File::delete($backupDatabase);
            }

            DB::purge('sqlite');
            File::deleteDirectory($backupRoot);
            File::delete($databasePath, $databasePath.'-wal', $databasePath.'-shm');
        }
    }

    public function test_backup_command_creates_database_and_manifest_without_deleting_source_data(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);
        $originalUmask = DIRECTORY_SEPARATOR === '\\' ? null : umask(self::TEST_BASE_UMASK);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            if (is_int($originalUmask)) {
                $this->assertSame(self::TEST_BASE_UMASK, umask());
            }
            $directories = File::directories($backupRoot);
            $this->assertCount(1, $directories);
            $this->assertFileExists($directories[0].DIRECTORY_SEPARATOR.'manifest.json');
            $this->assertFileExists($directories[0].DIRECTORY_SEPARATOR.'database.sqlite.enc');
            $manifest = json_decode(File::get($directories[0].DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('schema_sha256', $manifest['database']);
            $this->assertFileDoesNotExist($directories[0].DIRECTORY_SEPARATOR.'database.sqlite');
            $this->assertPrivateBackupPath($directories[0], true);
            $this->assertPrivateBackupPath($directories[0].DIRECTORY_SEPARATOR.'manifest.json', false);
            $this->assertPrivateBackupPath($directories[0].DIRECTORY_SEPARATOR.'database.sqlite.enc', false);
            $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(0);
        } finally {
            if (is_int($originalUmask)) {
                umask($originalUmask);
            }

            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_uploads_and_stages_verified_encrypted_files_from_the_private_fake_remote_disk(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
            'opsdeck.backup.remote_retention_days' => 30,
            'filesystems.disks.opsdeck_backups' => [
                'driver' => 's3',
                'key' => 'fake-access-key',
                'secret' => 'fake-secret-key',
                'bucket' => 'fake-private-backups',
                'endpoint' => 'https://s3.example.test',
            ],
        ]);
        Storage::fake('opsdeck_backups');
        config(['filesystems.disks.opsdeck_backups' => [
            'driver' => 's3',
            'key' => 'fake-access-key',
            'secret' => 'fake-secret-key',
            'region' => 'auto',
            'bucket' => 'fake-private-backups',
            'endpoint' => 'https://s3.example.test',
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ]]);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $backupDirectory = File::directories($backupRoot)[0];
            $backupName = basename($backupDirectory);
            $remotePrefix = 'opsdeck/backups/'.$backupName;
            $remoteDisk = Storage::disk('opsdeck_backups');
            $this->assertTrue($remoteDisk->exists($remotePrefix.'/manifest.json'));
            $this->assertTrue($remoteDisk->exists($remotePrefix.'/database.sqlite.enc'));
            $this->assertSame([], $remoteDisk->allFiles($remotePrefix.'/.staging'));
            $this->assertFileExists($backupDirectory.DIRECTORY_SEPARATOR.'database.sqlite.enc');
            $partialBackupName = now()->addDay()->format('Ymd_His').'_ffffffff';
            $remoteDisk->put('opsdeck/backups/'.$partialBackupName.'/database.sqlite.enc', 'incomplete-upload');

            $restoreCommand = app(OpsDeckBackupRestoreTest::class);
            $stagedBackupPath = (new ReflectionMethod($restoreCommand, 'stageLatestRemoteBackup'))->invoke($restoreCommand);
            $this->assertIsString($stagedBackupPath);
            $this->assertFileExists($stagedBackupPath.DIRECTORY_SEPARATOR.'manifest.json');
            $this->assertFileExists($stagedBackupPath.DIRECTORY_SEPARATOR.'database.sqlite.enc');
            $this->artisan('opsdeck:backup:verify', ['path' => $stagedBackupPath])->assertExitCode(0);
            File::deleteDirectory(dirname($stagedBackupPath));
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_removes_only_expired_new_local_generations_after_remote_checksum_verification(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $backupTime = Carbon::parse('2026-01-01 02:00:00');
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
            'opsdeck.backup.remote_retention_days' => 30,
            'opsdeck.backup.local_retention_days' => 30,
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
        Carbon::setTestNow($backupTime);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $oldBackupDirectory = File::directories($backupRoot)[0];
            $oldBackupName = basename($oldBackupDirectory);
            $oldManifest = json_decode(File::get($oldBackupDirectory.DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($oldManifest['local_retention_eligible']);

            Carbon::setTestNow($backupTime->copy()->addDays(31));
            $this->artisan('opsdeck:backup')->assertExitCode(0);

            $backupNames = array_map('basename', File::directories($backupRoot));
            $this->assertNotContains($oldBackupName, $backupNames);
            $this->assertCount(1, $backupNames);
            $this->assertFalse(Storage::disk('opsdeck_backups')->exists('opsdeck/backups/'.$oldBackupName.'/manifest.json'));
        } finally {
            Carbon::setTestNow();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_production_local_only_backup_succeeds_and_removes_only_expired_verified_generations(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $backupTime = Carbon::parse('2026-01-01 02:00:00');
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => false,
            'opsdeck.backup.local_retention_days' => 30,
        ]);
        $this->app['env'] = 'production';
        Carbon::setTestNow($backupTime);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $oldBackupDirectory = File::directories($backupRoot)[0];
            $oldBackupName = basename($oldBackupDirectory);
            $this->artisan('opsdeck:backup:verify', ['path' => $oldBackupDirectory])->assertExitCode(0);

            Carbon::setTestNow($backupTime->copy()->addDays(31));
            $this->artisan('opsdeck:backup')->assertExitCode(0);

            $backupNames = array_map('basename', File::directories($backupRoot));
            $this->assertNotContains($oldBackupName, $backupNames);
            $this->assertCount(1, $backupNames);
            $this->artisan('opsdeck:backup:verify', ['path' => File::directories($backupRoot)[0]])->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_non_test_backup_refuses_remote_copy_when_local_only_policy_is_active(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => true,
        ]);

        try {
            foreach (['local', 'production'] as $environment) {
                $this->app['env'] = $environment;
                $this->artisan('opsdeck:backup')
                    ->expectsOutput('Backups are configured for local-only storage. Set OPSDECK_BACKUP_REMOTE_ENABLED=false.')
                    ->assertExitCode(1);

                $this->assertDirectoryDoesNotExist($backupRoot);
            }
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_refuses_remote_copy_in_testing_without_fake_storage_opt_in(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.allow_remote_storage_in_tests' => false,
        ]);

        $this->artisan('opsdeck:backup')
            ->expectsOutput('Backups are configured for local-only storage. Set OPSDECK_BACKUP_REMOTE_ENABLED=false.')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($backupRoot);
    }

    public function test_expired_local_backup_is_preserved_when_its_remote_copy_does_not_match(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $backupTime = Carbon::parse('2026-01-01 02:00:00');
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
            'opsdeck.backup.remote_retention_days' => 30,
            'opsdeck.backup.local_retention_days' => 30,
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
        Carbon::setTestNow($backupTime);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $oldBackupDirectory = File::directories($backupRoot)[0];
            $oldBackupName = basename($oldBackupDirectory);
            Storage::disk('opsdeck_backups')->put('opsdeck/backups/'.$oldBackupName.'/database.sqlite.enc', 'mismatched-copy');

            Carbon::setTestNow($backupTime->copy()->addDays(31));
            $this->artisan('opsdeck:backup')->assertExitCode(0);

            $this->assertDirectoryExists($oldBackupDirectory);
            $this->assertFileExists($oldBackupDirectory.DIRECTORY_SEPARATOR.'manifest.json');
        } finally {
            Carbon::setTestNow();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_local_retention_never_removes_a_generation_created_before_the_policy(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $backupTime = Carbon::parse('2026-01-01 02:00:00');
        config(['opsdeck.backup.path' => $backupRoot]);
        Carbon::setTestNow($backupTime);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $oldBackupDirectory = File::directories($backupRoot)[0];
            $oldBackupName = basename($oldBackupDirectory);
            $manifestPath = $oldBackupDirectory.DIRECTORY_SEPARATOR.'manifest.json';
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            unset($manifest['local_retention_eligible'], $manifest['local_retention_policy_version'], $manifest['manifest_hmac_sha256']);
            $manifest['manifest_hmac_sha256'] = app(BackupEncryptionService::class)->manifestHmac($manifest);
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->artisan('opsdeck:backup:verify', ['path' => $oldBackupDirectory])->assertExitCode(0);

            config([
                'opsdeck.backup.remote_enabled' => true,
                'opsdeck.backup.remote_disk' => 'opsdeck_backups',
                'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
                'opsdeck.backup.remote_retention_days' => 30,
                'opsdeck.backup.local_retention_days' => 30,
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
            foreach (File::allFiles($oldBackupDirectory) as $file) {
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                Storage::disk('opsdeck_backups')->put(
                    'opsdeck/backups/'.$oldBackupName.'/'.$relativePath,
                    File::get($file->getPathname()),
                );
            }

            Carbon::setTestNow($backupTime->copy()->addDays(31));
            $this->artisan('opsdeck:backup')->assertExitCode(0);

            $this->assertDirectoryExists($oldBackupDirectory);
            $this->assertFileExists($manifestPath);
        } finally {
            Carbon::setTestNow();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_stages_verified_plaintext_files_from_the_private_fake_remote_disk(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $sourceDisk = 'plaintext-backup-source';
        Storage::fake($sourceDisk);
        Storage::disk($sourceDisk)->put('contracts/evidence.pdf', 'evidence-content');
        Storage::disk($sourceDisk)->put('imports/assets.csv', "name,serial_number\nLaptop,ABC-123\n");
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $evidence = Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Backup evidence',
            'evidence_type' => 'contract',
            'storage_disk' => $sourceDisk,
            'storage_path' => 'contracts/evidence.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen('evidence-content'),
            'uploaded_by' => $user->id,
        ]);
        $importBatch = ImportBatch::factory()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'assets.csv',
            'source_storage_disk' => $sourceDisk,
            'source_file_path' => 'imports/assets.csv',
            'status' => 'completed',
        ]);
        $stagedBackupPath = null;
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.encryption_enabled' => false,
            'opsdeck.backup.encryption_key' => null,
            'opsdeck.backup.remote_enabled' => true,
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

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $backupDirectory = File::directories($backupRoot)[0];
            $backupName = basename($backupDirectory);
            $remotePrefix = 'opsdeck/backups/'.$backupName;
            $remoteDisk = Storage::disk('opsdeck_backups');
            $this->assertTrue($remoteDisk->exists($remotePrefix.'/database.sqlite'));
            $this->assertTrue($remoteDisk->exists($remotePrefix.'/evidence-index.json'));
            $this->assertTrue($remoteDisk->exists($remotePrefix.'/import_sources_index.json'));
            $this->assertTrue($remoteDisk->exists($remotePrefix.'/evidence/00000001'));
            $this->assertTrue($remoteDisk->exists($remotePrefix.'/imports/00000001'));

            $restoreCommand = app(OpsDeckBackupRestoreTest::class);
            $stagedBackupPath = (new ReflectionMethod($restoreCommand, 'stageLatestRemoteBackup'))->invoke($restoreCommand);
            $this->assertIsString($stagedBackupPath);
            $this->assertFileExists($stagedBackupPath.DIRECTORY_SEPARATOR.'database.sqlite');
            $this->assertFileExists($stagedBackupPath.DIRECTORY_SEPARATOR.'evidence-index.json');
            $this->assertFileExists($stagedBackupPath.DIRECTORY_SEPARATOR.'import_sources_index.json');
            $this->artisan('opsdeck:backup:verify', ['path' => $stagedBackupPath])->assertExitCode(0);
        } finally {
            if (is_string($stagedBackupPath)) {
                File::deleteDirectory(dirname($stagedBackupPath));
            }
            File::deleteDirectory($backupRoot);
            $evidence->delete();
            $importBatch->delete();
            $organization->delete();
            $user->delete();
        }
    }

    public function test_backup_preserves_local_snapshot_and_refuses_the_development_storage_bucket(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
            'opsdeck.backup.remote_retention_days' => 30,
            'opsdeck.storage.expected_production_bucket' => 'opsdeck-prod-files',
            'opsdeck.storage.expected_development_bucket' => 'opsdeck-dev-files',
            'filesystems.disks.opsdeck_objects_test' => [
                'driver' => 's3',
                'key' => 'development-access-key',
                'secret' => 'development-secret',
                'bucket' => 'opsdeck-dev-files',
            ],
            'filesystems.disks.opsdeck_backups' => [
                'driver' => 's3',
                'key' => 'backup-access-key',
                'secret' => 'backup-secret',
                'region' => 'auto',
                'bucket' => 'opsdeck-dev-files',
                'endpoint' => 'https://s3.example.test',
            ],
        ]);
        Storage::fake('opsdeck_backups');

        try {
            $this->artisan('opsdeck:backup')
                ->expectsOutput('Backup failed after local verification. The verified local backup was preserved.')
                ->assertExitCode(1);

            $directories = File::directories($backupRoot);
            $this->assertCount(1, $directories);
            $this->assertFileExists($directories[0].DIRECTORY_SEPARATOR.'manifest.json');
            $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(0);
            $this->assertSame([], Storage::disk('opsdeck_backups')->allFiles());
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_remote_restore_refuses_the_development_storage_bucket_before_reading_it(): void
    {
        config([
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => 'opsdeck/backups',
            'opsdeck.backup.remote_retention_days' => 30,
            'opsdeck.storage.expected_production_bucket' => 'opsdeck-prod-files',
            'opsdeck.storage.expected_development_bucket' => 'opsdeck-dev-files',
            'filesystems.disks.opsdeck_objects' => [
                'driver' => 's3',
                'key' => 'production-access-key',
                'secret' => 'production-secret',
                'bucket' => 'opsdeck-prod-files',
            ],
            'filesystems.disks.opsdeck_objects_test' => [
                'driver' => 's3',
                'key' => 'development-access-key',
                'secret' => 'development-secret',
                'bucket' => 'opsdeck-dev-files',
            ],
            'filesystems.disks.opsdeck_backups' => [
                'driver' => 's3',
                'key' => 'backup-access-key',
                'secret' => 'backup-secret',
                'region' => 'auto',
                'bucket' => 'opsdeck-dev-files',
                'endpoint' => 'https://s3.example.test',
            ],
        ]);
        Storage::fake('opsdeck_backups');
        $remoteDisk = Storage::disk('opsdeck_backups');
        $remoteDisk->put('opsdeck/backups/20261024_000000_01234567/manifest.json', '{}');

        try {
            (new ReflectionMethod(app(OpsDeckBackupRestoreTest::class), 'stageLatestRemoteBackup'))->invoke(app(OpsDeckBackupRestoreTest::class));
            $this->fail('A development bucket must not be used for database backup restoration.');
        } catch (RuntimeException $exception) {
            $this->assertSame('外部バックアップ設定を確認できません。', $exception->getMessage());
        }

        $this->assertSame(['opsdeck/backups/20261024_000000_01234567/manifest.json'], $remoteDisk->allFiles());
    }

    public function test_failed_remote_backup_keeps_the_verified_local_backup(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_retention_days' => null,
        ]);

        try {
            $this->artisan('opsdeck:backup')
                ->expectsOutput('Backup failed after local verification. The verified local backup was preserved.')
                ->assertExitCode(1);

            $directories = File::directories($backupRoot);
            $this->assertCount(1, $directories);
            $this->assertFileExists($directories[0].DIRECTORY_SEPARATOR.'manifest.json');
            $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(0);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_remote_retention_only_removes_expired_opsdeck_backup_directories_under_the_configured_prefix(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $prefix = 'opsdeck/backups';
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.remote_enabled' => true,
            'opsdeck.backup.remote_disk' => 'opsdeck_backups',
            'opsdeck.backup.remote_prefix' => $prefix,
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
        config(['filesystems.disks.opsdeck_backups' => [
            'driver' => 's3',
            'key' => 'fake-access-key',
            'secret' => 'fake-secret-key',
            'region' => 'auto',
            'bucket' => 'fake-private-backups',
            'endpoint' => 'https://s3.example.test',
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ]]);
        $remoteDisk = Storage::disk('opsdeck_backups');
        $expiredDirectory = now()->subDays(31)->format('Ymd_His').'_01234567';
        $recentDirectory = now()->subDays(10)->format('Ymd_His').'_89abcdef';
        $remoteDisk->put($prefix.'/'.$expiredDirectory.'/manifest.json', '{}');
        $remoteDisk->put($prefix.'/'.$recentDirectory.'/manifest.json', '{}');
        $remoteDisk->put($prefix.'/unrecognized/keep.txt', 'keep');
        $remoteDisk->put('outside-backups/old/keep.txt', 'keep');

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);

            $this->assertFalse($remoteDisk->exists($prefix.'/'.$expiredDirectory.'/manifest.json'));
            $this->assertTrue($remoteDisk->exists($prefix.'/'.$recentDirectory.'/manifest.json'));
            $this->assertTrue($remoteDisk->exists($prefix.'/unrecognized/keep.txt'));
            $this->assertTrue($remoteDisk->exists('outside-backups/old/keep.txt'));
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verification_fails_when_decrypted_sqlite_temporary_file_cannot_be_removed(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);
        $temporaryPlaintextPath = null;

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $backupDirectory = File::directories($backupRoot)[0];

            File::partialMock()
                ->shouldReceive('delete')
                ->once()
                ->withArgs(function (string $path) use (&$temporaryPlaintextPath): bool {
                    $temporaryPlaintextPath = $path;

                    return str_starts_with($path, sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-verify-');
                })
                ->andReturnFalse();

            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])
                ->expectsOutput('復号した一時バックアップを削除できません。')
                ->assertExitCode(1);

            $this->assertIsString($temporaryPlaintextPath);
            $this->assertFalse(str_starts_with($temporaryPlaintextPath, $backupDirectory.DIRECTORY_SEPARATOR));
            $this->assertFileExists($temporaryPlaintextPath);
        } finally {
            if (is_string($temporaryPlaintextPath) && File::exists($temporaryPlaintextPath)) {
                @unlink($temporaryPlaintextPath);
            }

            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_failure_removes_partial_data_without_exposing_internal_errors(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $originalUmask = DIRECTORY_SEPARATOR === '\\' ? null : umask(self::TEST_BASE_UMASK);
        config([
            'opsdeck.backup.path' => $backupRoot,
            'database.connections.sqlite.driver' => 'unsupported',
        ]);

        try {
            $this->artisan('opsdeck:backup')
                ->expectsOutput('Backup failed. Incomplete backup data was removed.')
                ->assertExitCode(1);
            if (is_int($originalUmask)) {
                $this->assertSame(self::TEST_BASE_UMASK, umask());
            }

            $this->assertSame([], File::directories($backupRoot));
            $this->assertSame([], File::allFiles($backupRoot));
        } finally {
            File::deleteDirectory($backupRoot);
            if (is_int($originalUmask)) {
                umask($originalUmask);
            }
        }
    }

    public function test_backup_verify_rejects_manifest_path_traversal(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $directories = File::directories($backupRoot);
            $manifestPath = $directories[0].DIRECTORY_SEPARATOR.'manifest.json';
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['evidence_files'][] = '../manifest.json';
            $manifest['evidence_sha256'][] = hash('sha256', 'unexpected');
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

            $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verify_accepts_legacy_manifest_without_checksums(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        try {
            Storage::fake('legacy-evidence-test');
            config(['opsdeck.backup.path' => $backupRoot, 'opsdeck.evidence.disk' => 'legacy-evidence-test']);

            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $directories = File::directories($backupRoot);
            app(BackupEncryptionService::class)->decryptFile($directories[0].DIRECTORY_SEPARATOR.'database.sqlite.enc', $directories[0].DIRECTORY_SEPARATOR.'database.sqlite');
            File::delete($directories[0].DIRECTORY_SEPARATOR.'database.sqlite.enc');
            $manifestPath = $directories[0].DIRECTORY_SEPARATOR.'manifest.json';
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['database']['file'] = 'database.sqlite';
            unset($manifest['encryption'], $manifest['evidence_index_file'], $manifest['evidence_index_sha256'], $manifest['database_sha256'], $manifest['evidence_sha256'], $manifest['import_files'], $manifest['import_sha256'], $manifest['import_sources_index_file'], $manifest['import_sources_index_sha256'], $manifest['manifest_hmac_sha256']);
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

            $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(0);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verification_rejects_a_manifest_changed_without_its_authentication_key(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $backupDirectory = File::directories($backupRoot)[0];
            $manifestPath = $backupDirectory.DIRECTORY_SEPARATOR.'manifest.json';
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['database']['file'] = 'evidence-index.enc';
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])
                ->expectsOutput('バックアップマニフェストの認証に失敗しました。')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verify_rejects_malformed_manifest_without_throwing(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());

        try {
            File::ensureDirectoryExists($backupRoot);
            File::put($backupRoot.DIRECTORY_SEPARATOR.'manifest.json', '{"database":null}');

            $this->artisan('opsdeck:backup:verify', ['path' => $backupRoot])->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_backup_verify_rejects_unknown_database_driver(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $directories = File::directories($backupRoot);
            $manifestPath = $directories[0].DIRECTORY_SEPARATOR.'manifest.json';
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['database']['driver'] = 'unknown';
            File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

            $this->artisan('opsdeck:backup:verify', ['path' => $directories[0]])->assertExitCode(1);
        } finally {
            File::deleteDirectory($backupRoot);
        }
    }

    private function assertPrivateBackupPath(string $path, bool $isDirectory): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $permissions = fileperms($path);
        $this->assertIsInt($permissions, 'Expected the backup path permissions to be readable.');
        $this->assertSame(0, $permissions & self::GROUP_AND_WORLD_PERMISSION_MASK, $isDirectory
            ? 'Backup directories must not grant group or world permissions.'
            : 'Backup files must not grant group or world permissions.');
    }
}
