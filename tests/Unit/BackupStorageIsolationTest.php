<?php

namespace Tests\Unit;

use App\Services\BackupStorageIsolation;
use Tests\TestCase;

class BackupStorageIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
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
        ]);
    }

    public function test_backup_disk_must_use_a_distinct_bucket_and_credentials_from_production_and_development(): void
    {
        $isolation = app(BackupStorageIsolation::class);
        $backupDisk = [
            'driver' => 's3',
            'key' => 'backup-access-key',
            'secret' => 'backup-secret',
            'bucket' => 'opsdeck-private-backups',
        ];

        $this->assertTrue($isolation->isDedicated($backupDisk));
        $this->assertFalse($isolation->isDedicated([...$backupDisk, 'bucket' => 'opsdeck-prod-files']));
        $this->assertFalse($isolation->isDedicated([...$backupDisk, 'bucket' => 'opsdeck-dev-files']));
        $this->assertFalse($isolation->isDedicated([...$backupDisk, 'key' => 'production-access-key']));
        $this->assertFalse($isolation->isDedicated([...$backupDisk, 'secret' => 'development-secret']));
    }
}
