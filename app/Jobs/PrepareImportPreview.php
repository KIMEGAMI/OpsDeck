<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\ImportFailureMessage;
use App\Services\Import\ImportPreviewPreparationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PrepareImportPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $importBatchId)
    {
        $this->timeout = max(1, (int) config('opsdeck.import.job_timeout_seconds'));
    }

    public function handle(ImportPreviewPreparationService $preparationService): void
    {
        $preparationService->prepare($this->importBatchId);
    }

    public function failed(?Throwable $exception): void
    {
        ImportBatch::query()
            ->whereKey($this->importBatchId)
            ->where('status', 'previewing')
            ->update(['status' => 'failed', 'failure_message' => ImportFailureMessage::safeMessage($exception), 'completed_at' => now()]);
    }
}
