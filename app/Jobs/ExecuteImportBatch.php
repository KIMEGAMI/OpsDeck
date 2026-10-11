<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\ImportFailureMessage;
use App\Services\ImportBatchExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $importBatchId, public int $userId)
    {
        $this->timeout = max(1, (int) config('opsdeck.import.job_timeout_seconds'));
    }

    public function handle(ImportBatchExecutionService $executionService): void
    {
        $executionService->execute($this->importBatchId, $this->userId, 'queued');
    }

    public function failed(?Throwable $exception): void
    {
        ImportBatch::query()
            ->whereKey($this->importBatchId)
            ->whereIn('status', ['queued', 'executing'])
            ->update(['status' => 'failed', 'failure_message' => ImportFailureMessage::safeMessage($exception), 'completed_at' => now()]);
    }
}
