<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\BackupEncryptionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

#[Group('r2-integration')]
class OpsDeckR2StorageIntegrationTest extends TestCase
{
    private ?string $isolatedDatabasePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $temporaryDatabasePath = tempnam(sys_get_temp_dir(), 'opsdeck-r2-test-');
        if (! is_string($temporaryDatabasePath)) {
            $this->fail('An isolated R2 integration database could not be created.');
        }

        $this->isolatedDatabasePath = $temporaryDatabasePath;
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $temporaryDatabasePath,
        ]);
        DB::purge('sqlite');

        $this->assertSame($temporaryDatabasePath, DB::connection('sqlite')->getDatabaseName());
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        if (is_string($this->isolatedDatabasePath)
            && is_file($this->isolatedDatabasePath)
            && ! unlink($this->isolatedDatabasePath)) {
            throw new \RuntimeException('The isolated R2 integration database could not be removed.');
        }

        parent::tearDown();
    }

    public function test_evidence_upload_download_and_tenant_isolation_work_with_the_development_r2_bucket(): void
    {
        $diskName = $this->developmentR2Disk();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.evidence.disk' => $diskName]);
        Storage::forgetDisk($diskName);

        [$user, $organization] = $this->createMember();
        [$otherUser, $otherOrganization] = $this->createMember();
        $uniqueFileName = 'r2-evidence-'.Str::uuid().'.pdf';
        $file = UploadedFile::fake()->create($uniqueFileName, 10, 'application/pdf');
        $expectedContents = $file->getContent();
        $objectPath = null;

        try {
            $storeResponse = $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->post(route('evidence.store'), [
                    'title' => 'R2 integration evidence',
                    'evidence_type' => 'policy',
                    'file' => $file,
                ]);

            $storeResponse->assertRedirect();
            $evidence = Evidence::query()->where('organization_id', $organization->id)->sole();
            $objectPath = $evidence->storage_path;

            $this->assertSame($diskName, $evidence->storage_disk);
            $this->assertStringStartsWith('evidence/'.$organization->id.'/', $objectPath);
            $this->assertStringNotContainsString($uniqueFileName, $objectPath);
            $this->assertDatabaseHas('evidence', [
                'id' => $evidence->id,
                'organization_id' => $organization->id,
                'storage_disk' => $diskName,
                'storage_path' => $objectPath,
            ]);

            $this->actingAs($otherUser)
                ->withSession(['current_organization_id' => $otherOrganization->id])
                ->get(route('evidence.download', $evidence))
                ->assertNotFound();

            $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->get(route('evidence.download', $evidence))
                ->assertDownload('evidence-'.$evidence->id.'.pdf')
                ->assertStreamedContent($expectedContents);

            $storage = Storage::disk($diskName);
            $this->assertTrue($storage->delete($objectPath));
            $this->assertFalse($storage->exists($objectPath));
            $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->get(route('evidence.download', $evidence))
                ->assertNotFound();
            $objectPath = null;
        } finally {
            if (is_string($objectPath)) {
                try {
                    Storage::disk($diskName)->delete($objectPath);
                } catch (Throwable) {
                    // Preserve the original test failure while attempting cleanup.
                }
            }
        }
    }

    public function test_csv_upload_and_preview_read_from_the_development_r2_bucket(): void
    {
        $diskName = $this->developmentR2Disk();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.disk' => $diskName]);
        Storage::forgetDisk($diskName);

        [$user, $organization] = $this->createMember();
        [$otherUser, $otherOrganization] = $this->createMember();
        $uniqueFileName = 'r2-import-'.Str::uuid().'.csv';
        $sourceContents = "employee_number,name,email,employment_status\nEMP-R2-001,R2 Test,r2-import-".Str::uuid()."@example.test,active\n";
        $file = UploadedFile::fake()->createWithContent($uniqueFileName, $sourceContents);
        $objectPath = null;

        try {
            $storeResponse = $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->post(route('imports.store'), [
                    'target_type' => 'employees',
                    'source_name' => 'R2 integration CSV',
                    'file' => $file,
                ]);

            $batch = ImportBatch::query()->where('organization_id', $organization->id)->sole();
            $objectPath = $batch->source_file_path;

            $storeResponse->assertRedirect(route('imports.show', [$batch, 'target_type' => 'employees']));
            $this->assertSame($diskName, $batch->source_storage_disk);
            $this->assertStringStartsWith('imports/'.$organization->id.'/', $objectPath);
            $this->assertStringNotContainsString($uniqueFileName, $objectPath);
            $this->assertSame(hash('sha256', $sourceContents), $batch->source_file_sha256);
            $this->assertSame('preview', $batch->refresh()->status);
            $this->assertSame(['employee_number', 'name', 'email', 'employment_status'], $batch->headers_json);
            $this->assertSame(1, $batch->total_rows);

            $this->actingAs($otherUser)
                ->withSession(['current_organization_id' => $otherOrganization->id])
                ->get(route('imports.show', [$batch, 'target_type' => 'employees']))
                ->assertNotFound();

            $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->get(route('imports.show', [$batch, 'target_type' => 'employees']))
                ->assertOk()
                ->assertSee('R2 integration CSV');

            $storage = Storage::disk($diskName);
            $this->assertTrue($storage->delete($objectPath));
            $this->assertFalse($storage->exists($objectPath));
            $objectPath = null;
        } finally {
            if (is_string($objectPath)) {
                try {
                    Storage::disk($diskName)->delete($objectPath);
                } catch (Throwable) {
                    // Preserve the original test failure while attempting cleanup.
                }
            }
        }
    }

    public function test_evidence_and_import_sources_from_the_development_r2_bucket_can_be_backed_up_and_restored(): void
    {
        $diskName = $this->developmentR2Disk();
        $temporaryDatabasePath = tempnam(sys_get_temp_dir(), 'opsdeck-r2-backup-test-');
        $this->assertIsString($temporaryDatabasePath);

        $previousDefaultConnection = config('database.default');
        $connectionName = 'r2_backup_integration';
        config([
            'database.default' => $connectionName,
            'database.connections.'.$connectionName => [
                'driver' => 'sqlite',
                'database' => $temporaryDatabasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge($connectionName);

        $sourcePath = null;
        $importSourcePath = null;
        $restoredObjectPath = null;
        $restoredImportPath = null;
        $plaintextRestorePath = null;
        $backupRoot = storage_path('framework/testing-backups/'.Str::uuid());
        config([
            'opsdeck.backup.path' => $backupRoot,
            'opsdeck.backup.encryption_enabled' => true,
            'opsdeck.backup.encryption_key' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
        ]);
        Storage::forgetDisk($diskName);

        try {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

            [$user, $organization] = $this->createMember();
            [$otherUser, $otherOrganization] = $this->createMember();
            $sourcePath = 'evidence/'.$organization->id.'/'.Str::uuid().'.pdf';
            $sourceContents = 'R2 backup integration evidence '.Str::uuid();
            Storage::disk($diskName)->put($sourcePath, $sourceContents);
            $evidence = Evidence::query()->create([
                'organization_id' => $organization->id,
                'title' => 'R2 backup integration evidence',
                'evidence_type' => 'contract',
                'storage_disk' => $diskName,
                'storage_path' => $sourcePath,
                'mime_type' => 'application/pdf',
                'file_size' => strlen($sourceContents),
                'uploaded_by' => $user->id,
            ]);
            $importSourceContents = "employee_number,name\nEMP-R2-BACKUP,R2 Backup Test\n";
            $importSourcePath = 'imports/'.$organization->id.'/'.Str::uuid().'.csv';
            Storage::disk($diskName)->put($importSourcePath, $importSourceContents);
            $importBatch = ImportBatch::factory()->create([
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'R2 backup integration CSV',
                'source_file_path' => $importSourcePath,
                'source_storage_disk' => $diskName,
                'source_file_size_bytes' => strlen($importSourceContents),
                'source_file_sha256' => hash('sha256', $importSourceContents),
            ]);

            $this->actingAs($otherUser)
                ->withSession(['current_organization_id' => $otherOrganization->id])
                ->get(route('evidence.download', $evidence))
                ->assertNotFound();
            $this->actingAs($otherUser)
                ->withSession(['current_organization_id' => $otherOrganization->id])
                ->get(route('imports.show', [$importBatch, 'target_type' => 'employees']))
                ->assertNotFound();

            $this->artisan('opsdeck:backup')->assertExitCode(0);
            $backupDirectories = File::directories($backupRoot);
            $this->assertCount(1, $backupDirectories);
            $backupDirectory = $backupDirectories[0];
            $manifest = json_decode(File::get($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(1, $manifest['evidence_files']);
            $this->assertCount(1, $manifest['import_files']);

            $encryption = app(BackupEncryptionService::class);
            $indexContents = '';
            foreach ($encryption->decryptedChunks($backupDirectory.DIRECTORY_SEPARATOR.BackupEncryptionService::EVIDENCE_INDEX_FILE) as $chunk) {
                $indexContents .= $chunk;
            }
            $this->assertSame([['disk' => $diskName, 'path' => $sourcePath]], json_decode($indexContents, true, 512, JSON_THROW_ON_ERROR));

            $encryptedEvidencePath = $backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.$manifest['evidence_files'][0].'.enc';
            $restoredContents = '';
            foreach ($encryption->decryptedChunks($encryptedEvidencePath) as $chunk) {
                $restoredContents .= $chunk;
            }
            $this->assertSame($sourceContents, $restoredContents);

            $importIndexContents = '';
            foreach ($encryption->decryptedChunks($backupDirectory.DIRECTORY_SEPARATOR.'import_sources_index.enc') as $chunk) {
                $importIndexContents .= $chunk;
            }
            $this->assertSame([['disk' => $diskName, 'path' => $importSourcePath]], json_decode($importIndexContents, true, 512, JSON_THROW_ON_ERROR));
            $encryptedImportPath = $backupDirectory.DIRECTORY_SEPARATOR.'imports'.DIRECTORY_SEPARATOR.$manifest['import_files'][0].'.enc';
            $restoredImportContents = '';
            foreach ($encryption->decryptedChunks($encryptedImportPath) as $chunk) {
                $restoredImportContents .= $chunk;
            }
            $this->assertSame($importSourceContents, $restoredImportContents);

            $plaintextRestorePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-r2-restore-'.Str::uuid();
            $encryption->decryptFile($encryptedEvidencePath, $plaintextRestorePath);
            $restoreStream = fopen($plaintextRestorePath, 'rb');
            $this->assertIsResource($restoreStream);
            $restoredObjectPath = 'evidence/'.$organization->id.'/'.Str::uuid().'.pdf';
            try {
                $this->assertTrue(Storage::disk($diskName)->put($restoredObjectPath, $restoreStream));
            } finally {
                fclose($restoreStream);
            }
            $this->assertSame($sourceContents, Storage::disk($diskName)->get($restoredObjectPath));
            $restoredImportPath = 'imports/'.$organization->id.'/'.Str::uuid().'.csv';
            $this->assertTrue(Storage::disk($diskName)->put($restoredImportPath, $restoredImportContents));
            $this->assertSame($importSourceContents, Storage::disk($diskName)->get($restoredImportPath));
            $this->artisan('opsdeck:backup:verify', ['path' => $backupDirectory])->assertExitCode(0);
        } finally {
            $cleanupFailures = [];
            foreach ([$sourcePath, $importSourcePath, $restoredObjectPath, $restoredImportPath] as $objectPath) {
                if (! is_string($objectPath)) {
                    continue;
                }

                try {
                    $storage = Storage::disk($diskName);
                    $storage->delete($objectPath);
                    if ($storage->exists($objectPath)) {
                        $cleanupFailures[] = 'r2-object';
                    }
                } catch (Throwable) {
                    $cleanupFailures[] = 'r2-object';
                }
            }
            if (is_string($plaintextRestorePath) && is_file($plaintextRestorePath) && ! unlink($plaintextRestorePath)) {
                $cleanupFailures[] = 'plaintext-temporary-file';
            }
            File::deleteDirectory($backupRoot);
            if (File::isDirectory($backupRoot)) {
                $cleanupFailures[] = 'backup-directory';
            }
            DB::purge($connectionName);
            config(['database.default' => $previousDefaultConnection]);
            if (is_file($temporaryDatabasePath) && ! unlink($temporaryDatabasePath)) {
                $cleanupFailures[] = 'database-file';
            }
            $this->assertSame([], $cleanupFailures, 'R2 backup integration test cleanup was incomplete.');
        }
    }

    private function developmentR2Disk(): string
    {
        if (getenv('OPSDECK_RUN_R2_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Set OPSDECK_RUN_R2_INTEGRATION_TESTS=1 to use the non-production R2 bucket.');
        }

        $this->assertSame('testing', app()->environment());

        $diskName = 'opsdeck_objects_test';
        $diskConfiguration = config('filesystems.disks.'.$diskName);
        $this->assertSame('s3', $diskConfiguration['driver'] ?? null);
        $this->assertSame('opsdeck-dev-files', $diskConfiguration['bucket'] ?? null);

        return $diskName;
    }

    /** @return array{0: User, 1: Organization} */
    private function createMember(): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'r2-integration-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'R2 integration organization '.$suffix,
            'slug' => 'r2-integration-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }
}
