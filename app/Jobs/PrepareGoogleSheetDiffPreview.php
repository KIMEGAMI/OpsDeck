<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\GoogleSheetDiffPreviewPreparationService;
use App\Services\Import\ImportFailureMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PrepareGoogleSheetDiffPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $sourceBatchId, public int $diffBatchId)
    {
        $this->timeout = max(1, (int) config('opsdeck.import.job_timeout_seconds'));
    }

    public function handle(GoogleSheetDiffPreviewPreparationService $preparationService): void
    {
        $preparationService->prepare($this->sourceBatchId, $this->diffBatchId);
    }

    public function failed(?Throwable $exception): void
    {
        ImportBatch::query()
            ->whereKey($this->diffBatchId)
            ->where('diff_source_batch_id', $this->sourceBatchId)
            ->where('status', 'previewing')
            ->update(['status' => 'failed', 'failure_message' => ImportFailureMessage::safeMessage($exception), 'completed_at' => now()]);
    }
}
