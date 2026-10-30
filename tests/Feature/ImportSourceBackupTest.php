<?php

namespace Tests\Feature;

use App\Services\BackupEncryptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportSourceBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'opsdeck.backup.encryption_enabled' => true,
            'opsdeck.backup.encryption_key' => base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
        ]);
    }

    public function test_it_backs_up_and_verifies_import_source_files_from_their_configured_disk(): void
    {
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        $testDatabasePath = tempnam(sys_get_temp_dir(), 'opsdeck-import-backup-test-');
        if (! is_string($testDatabasePath)) {
            $this->fail('An isolated test database could not be created.');
        }
        $previousDefaultConnection = config('database.default');
        $connectionName = 'import_source_backup_test';
        config([
            'database.default' => $connectionName,
            'database.connections.'.$connectionName => [
                'driver' => 'sqlite',
                'database' => $testDatabasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge($connectionName);
        Schema::connection($connectionName)->create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->string('source_file_path')->nullable();
            $table->string('source_storage_disk')->nullable();
        });
        Storage::fake('import-source-backup-test');
        config(['opsdeck.import.disk' => 'import-source-backup-test']);
        Storage::disk('import-source-backup-test')->put('imports/organization/source.xlsx', 'import-source-content');
        DB::table('import_batches')->insert([
            'source_type' => 'xlsx',
            'source_storage_disk' => 'import-source-backup-test',
            'source_file_path' => 'imports/organization/source.xlsx',
        ]);

        try {
            foreach ([false, true] as $encryptionEnabled) {
                $backupPath = $backupRoot.DIRECTORY_SEPARATOR.($encryptionEnabled ? 'encrypted' : 'plaintext');
                config([
                    'opsdeck.backup.path' => $backupPath,
                    'opsdeck.backup.encryption_enabled' => $encryptionEnabled,
                ]);
                $this->artisan('opsdeck:backup')->assertExitCode(0);
                $backupDirectory = File::directories($backupPath)[0];
                $manifest = json_decode(File::get($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                $this->assertCount(1, $manifest['import_files']);
                $importPath = $backupDirectory.DIRECTORY_SEPARATOR.'imports'.DIRECTORY_SEPARATOR.$manifest['import_files'][0].($encryptionEnabled ? '.enc' : '');
                $this->assertFileExists($importPath);

                if ($encryptionEnabled) {
                    $contents = '';
                    foreach (app(BackupEncryptionService::class)->decryptedChunks($importPath) as $chunk) {
                        $contents .= $chunk;
                    }
                } else {
                    $contents = File::get($importPath);
                }

                $this->assertSame('import-source-content', $contents);
                $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(0);
                File::put($importPath, 'tampered-import-source');
                $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(1);
            }
        } finally {
            File::deleteDirectory($backupRoot);
            DB::purge($connectionName);
            config(['database.default' => $previousDefaultConnection]);
            if (is_string($testDatabasePath) && is_file($testDatabasePath)) {
                unlink($testDatabasePath);
            }
        }
    }
}
