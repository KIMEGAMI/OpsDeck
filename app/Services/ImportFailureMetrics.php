<?php

namespace App\Services;

use App\Models\ImportBatch;

class ImportFailureMetrics
{
    /**
     * @return array{total:int, failed:int, rate:float}
     */
    public function summarize(): array
    {
        $terminalStatuses = config('opsdeck.import_terminal_statuses');
        $total = ImportBatch::query()->whereIn('status', $terminalStatuses)->count();
        $failed = ImportBatch::query()->where('status', 'failed')->count();

        return [
            'total' => $total,
            'failed' => $failed,
            'rate' => $total > 0 ? round(($failed / $total) * 100, 2) : 0.0,
        ];
    }
}
