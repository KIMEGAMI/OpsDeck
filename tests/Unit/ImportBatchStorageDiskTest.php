<?php

namespace Tests\Unit;

use App\Models\ImportBatch;
use Tests\TestCase;

class ImportBatchStorageDiskTest extends TestCase
{
    public function test_legacy_import_uses_its_configured_disk_after_the_current_import_disk_changes(): void
    {
        config([
            'opsdeck.import.disk' => 'opsdeck_objects',
            'opsdeck.import.legacy_disk' => 'local',
        ]);

        $batch = new ImportBatch([
            'source_file_path' => 'imports/7/old-import.csv',
            'source_storage_disk' => null,
        ]);

        $this->assertSame('local', $batch->sourceStorageDiskName());
    }

    public function test_import_with_an_explicit_storage_disk_keeps_using_that_disk(): void
    {
        config([
            'opsdeck.import.disk' => 'opsdeck_objects',
            'opsdeck.import.legacy_disk' => 'local',
        ]);

        $batch = new ImportBatch([
            'source_file_path' => 'imports/7/archived-import.csv',
            'source_storage_disk' => 'import_archive',
        ]);

        $this->assertSame('import_archive', $batch->sourceStorageDiskName());
    }
}
