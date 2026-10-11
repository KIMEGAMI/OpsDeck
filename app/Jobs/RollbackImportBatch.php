<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\ImportBatchRollbackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RollbackImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $importBatchId)
    {
        $this->timeout = max(1, (int) config('opsdeck.import.job_timeout_seconds'));
    }

    public function handle(ImportBatchRollbackService $rollbackService): void
    {
        $rollbackService->rollback($this->importBatchId);
    }

    public function failed(?Throwable $exception): void
    {
        ImportBatch::query()
            ->whereKey($this->importBatchId)
            ->where('status', 'rolling_back')
            ->update([
                'status' => 'completed',
                'failure_message' => 'Importの取消を完了できませんでした。現在のデータを確認してから再試行してください。',
            ]);
    }
}
