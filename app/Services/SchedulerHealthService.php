<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SchedulerHealthService
{
    public function status(): string
    {
        if (! Schema::hasTable('scheduler_heartbeats')) {
            return 'unknown';
        }

        $lastSeenAt = DB::table('scheduler_heartbeats')->where('name', 'scheduler')->value('last_seen_at');
        if ($lastSeenAt === null) {
            return 'missing';
        }

        try {
            return Carbon::parse($lastSeenAt)->isAfter(now()->subMinutes((int) config('opsdeck.scheduler.heartbeat_max_age_minutes'))) ? 'ok' : 'stale';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
