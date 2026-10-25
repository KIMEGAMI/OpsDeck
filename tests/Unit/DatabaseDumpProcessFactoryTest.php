<?php

namespace Tests\Unit;

use App\Services\DatabaseDumpProcessFactory;
use Tests\TestCase;

class DatabaseDumpProcessFactoryTest extends TestCase
{
    public function test_database_process_timeout_uses_the_shared_backup_configuration(): void
    {
        config(['opsdeck.backup.process_timeout_seconds' => 4200]);

        $process = app(DatabaseDumpProcessFactory::class)->create(['php', '--version']);

        $this->assertSame(4200.0, $process->getTimeout());
    }
}
