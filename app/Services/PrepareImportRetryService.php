<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PrepareImportRetryService
{
    public function createSynchronously(ImportBatch $sourceBatch, int $userId): ImportBatch
    {
        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));

        return DB::transaction(function () use ($sourceBatch, $userId, $chunkSize): ImportBatch {
            $lockedSource = ImportBatch::query()->whereKey($sourceBatch->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedSource->status === 'failed', 409, 'このImportはすでに再試行中です。');

            $retryBatch = $lockedSource->replicate(['created_at', 'updated_at']);
            $retryBatch->status = 'preview';
            $retryBatch->mapping_id = null;
            $retryBatch->total_rows = $lockedSource->total_rows;
            $retryBatch->inserted_rows = 0;
            $retryBatch->updated_rows = 0;
            $retryBatch->skipped_rows = 0;
            $retryBatch->error_rows = 0;
            $retryBatch->started_at = null;
            $retryBatch->completed_at = null;
            $retryBatch->failure_message = null;
            $retryBatch->executed_by = $userId;
            $retryBatch->save();

            $this->copyContents($lockedSource, $retryBatch, $userId, $chunkSize);

            return $retryBatch;
        });
    }

    public function prepare(int $sourceBatchId, int $retryBatchId, int $userId): void
    {
        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));

        DB::transaction(function () use ($sourceBatchId, $retryBatchId, $userId, $chunkSize): void {
            $sourceBatch = ImportBatch::query()->whereKey($sourceBatchId)->lockForUpdate()->first();
            $retryBatch = ImportBatch::query()->whereKey($retryBatchId)->lockForUpdate()->first();

            if ($sourceBatch === null || $retryBatch === null || $sourceBatch->status !== 'retrying' || $retryBatch->status !== 'retrying') {
                return;
            }

            abort_unless($sourceBatch->organization_id === $retryBatch->organization_id, 404);
            $actor = User::query()->find($userId);
            abort_unless($actor !== null, 403);
            Gate::forUser($actor)->authorize('update', $sourceBatch);

            $this->copyContents($sourceBatch, $retryBatch, $userId, $chunkSize);
            $sourceBatch->update(['status' => 'failed']);
        });
    }

    private function copyContents(ImportBatch $sourceBatch, ImportBatch $retryBatch, int $userId, int $chunkSize): void
    {

        $mappingId = null;
        foreach ($sourceBatch->mappings()->where('status', 'ready')->lazyById($chunkSize) as $mapping) {
            $copiedMapping = $retryBatch->mappings()->create([
                'target_type' => $mapping->target_type,
                'column_map' => $mapping->column_map,
                'normalization_rules' => $mapping->normalization_rules,
                'status' => 'ready',
                'created_by' => $userId,
            ]);
            $mappingId = (string) $copiedMapping->id;
        }

        $previewActions = ['new_candidate', 'update_candidate', 'confirmation_required', 'error', 'preview'];
        $latestRowResultIds = $sourceBatch->rowResults()
            ->selectRaw('MAX(import_row_results.id)')
            ->groupBy('source_row_number');
        $sourceBatch->rowResults()->whereIn('id', $latestRowResultIds)->chunkById($chunkSize, function (Collection $rowResults) use ($retryBatch, $previewActions): bool {
            foreach ($rowResults as $rowResult) {
                $action = in_array($rowResult->action, $previewActions, true) ? $rowResult->action : 'preview';
                $retryBatch->rowResults()->create([
                    'sheet_name' => $rowResult->sheet_name,
                    'source_row_number' => $rowResult->source_row_number,
                    'target_type' => $rowResult->target_type,
                    'action' => $action,
                    'is_selected' => $rowResult->is_selected,
                    'after_json' => in_array($action, ['new_candidate', 'update_candidate', 'confirmation_required'], true) ? $rowResult->after_json : null,
                    'errors_json' => $action === 'error' ? $rowResult->errors_json : null,
                ]);
            }

            return true;
        });

        $sourceBatch->reviewCandidates()->chunkById($chunkSize, function (Collection $candidates) use ($retryBatch): bool {
            foreach ($candidates as $candidate) {
                $retryBatch->reviewCandidates()->create([
                    'candidate_type' => $candidate->candidate_type,
                    'source_row_numbers_json' => $candidate->source_row_numbers_json,
                    'confidence' => $candidate->confidence,
                    'details_json' => $candidate->details_json,
                    'status' => $candidate->status,
                    'reviewed_by' => $candidate->reviewed_by,
                    'reviewed_at' => $candidate->reviewed_at,
                ]);
            }

            return true;
        });

        if ($mappingId !== null) {
            $retryBatch->update(['mapping_id' => $mappingId, 'status' => 'ready']);
        } else {
            $retryBatch->update(['status' => 'preview']);
        }
    }
}
