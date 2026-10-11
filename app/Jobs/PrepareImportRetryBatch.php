<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\ImportFailureMessage;
use App\Services\PrepareImportRetryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PrepareImportRetryBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $sourceBatchId, public int $retryBatchId, public int $userId)
    {
        $this->timeout = max(1, (int) config('opsdeck.import.job_timeout_seconds'));
    }

    public function handle(PrepareImportRetryService $retryService): void
    {
        $retryService->prepare($this->sourceBatchId, $this->retryBatchId, $this->userId);
    }

    public function failed(?Throwable $exception): void
    {
        ImportBatch::query()
            ->whereKey($this->retryBatchId)
            ->where('status', 'retrying')
            ->update(['status' => 'failed', 'failure_message' => ImportFailureMessage::safeMessage($exception), 'completed_at' => now()]);

        ImportBatch::query()
            ->whereKey($this->sourceBatchId)
            ->where('status', 'retrying')
            ->update(['status' => 'failed']);
    }
}
