<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BackupRestoreHealthService
{
    /** @return array{status: 'ok'|'failed'|'stale'|'invalid'|'missing'|'not_applicable', completed_at: string} */
    public function report(): array
    {
        if (! app()->environment('production')) {
            return ['status' => 'not_applicable', 'completed_at' => '対象外'];
        }

        if (! Schema::hasTable('backup_restore_checks')) {
            return ['status' => 'missing', 'completed_at' => '未確認'];
        }

        $check = DB::table('backup_restore_checks')->orderByDesc('id')->first(['status', 'completed_at']);
        if ($check === null) {
            return ['status' => 'missing', 'completed_at' => '未確認'];
        }

        try {
            $completedAt = CarbonImmutable::parse($check->completed_at);
        } catch (Throwable) {
            return ['status' => 'invalid', 'completed_at' => '不正な記録'];
        }

        if ($completedAt->greaterThan(now()) || ! in_array($check->status, ['passed', 'failed'], true)) {
            return ['status' => 'invalid', 'completed_at' => '不正な記録'];
        }

        $completedAtLabel = $completedAt->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        if ($check->status === 'failed') {
            return ['status' => 'failed', 'completed_at' => $completedAtLabel];
        }

        $maximumAgeDays = max(1, (int) config('opsdeck.backup.restore_test_max_age_days'));
        if ($completedAt->lessThanOrEqualTo(now()->subDays($maximumAgeDays))) {
            return ['status' => 'stale', 'completed_at' => $completedAtLabel];
        }

        return ['status' => 'ok', 'completed_at' => $completedAtLabel];
    }
}
