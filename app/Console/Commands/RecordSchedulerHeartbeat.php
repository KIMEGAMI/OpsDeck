<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('opsdeck:scheduler:heartbeat')]
#[Description('Record the scheduler heartbeat for operational health checks')]
class RecordSchedulerHeartbeat extends Command
{
    public function handle(): int
    {
        DB::table('scheduler_heartbeats')->upsert(
            [['name' => 'scheduler', 'last_seen_at' => now()]],
            ['name'],
            ['last_seen_at'],
        );

        return self::SUCCESS;
    }
}
