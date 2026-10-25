<?php

namespace Tests\Unit;

use App\Services\BackupRestoreCleanupService;
use Illuminate\Database\Schema\Builder;
use RuntimeException;
use Tests\TestCase;

class BackupRestoreCleanupServiceTest extends TestCase
{
    public function test_table_cleanup_is_attempted_when_view_cleanup_fails(): void
    {
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('dropAllViews')->once()->andThrow(new RuntimeException('view cleanup failed'));
        $schema->shouldReceive('dropAllTables')->once();

        $this->assertFalse(app(BackupRestoreCleanupService::class)->clean($schema));
    }

    public function test_cleanup_reports_failure_when_table_cleanup_fails(): void
    {
        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('dropAllViews')->once();
        $schema->shouldReceive('dropAllTables')->once()->andThrow(new RuntimeException('table cleanup failed'));

        $this->assertFalse(app(BackupRestoreCleanupService::class)->clean($schema));
    }
}
