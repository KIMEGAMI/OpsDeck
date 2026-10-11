<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Services\Import\ImportFailureMessage;
use App\Services\Import\ImportMappingAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrepareImportMappingAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $importBatchId, public int $mappingId)
    {
        $this->timeout = max(1, (int) config('opsdeck.import.job_timeout_seconds'));
    }

    public function handle(ImportMappingAnalysisService $analysisService): void
    {
        $analysisService->analyze($this->importBatchId, $this->mappingId);
    }

    public function failed(?Throwable $exception): void
    {
        $failureMessage = ImportFailureMessage::safeMessage($exception);

        DB::transaction(function () use ($failureMessage): void {
            $batch = ImportBatch::query()
                ->whereKey($this->importBatchId)
                ->where('mapping_id', (string) $this->mappingId)
                ->where('status', 'analyzing')
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                return;
            }

            ImportMapping::query()
                ->whereKey($this->mappingId)
                ->where('import_batch_id', $batch->id)
                ->where('status', 'analyzing')
                ->update(['status' => 'failed', 'updated_at' => now()]);
            $batch->update(['status' => 'failed', 'failure_message' => $failureMessage, 'completed_at' => now()]);
        });
    }
}
