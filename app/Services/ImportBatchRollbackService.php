<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Models\QuestionnaireAnswer;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ImportBatchRollbackService
{
    public function rollback(int $importBatchId): void
    {
        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));

        DB::transaction(function () use ($importBatchId, $chunkSize): void {
            $importBatch = ImportBatch::query()->whereKey($importBatchId)->lockForUpdate()->first();
            if ($importBatch === null || $importBatch->status !== 'rolling_back') {
                return;
            }

            $importBatch->rowResults()->whereIn('action', ['inserted', 'updated'])->chunkByIdDesc($chunkSize, function (Collection $results) use ($importBatch): bool {
                foreach ($results as $result) {
                    $record = $this->findImportTarget($importBatch, $result);
                    abort_unless($record !== null, 409, '取消対象のデータが見つかりません。');
                    abort_unless($this->matchesSnapshot($record, $result->after_json ?? []), 409, 'Import後に変更されたデータがあるため、安全な取消を中止しました。');

                    if ($result->action === 'inserted') {
                        $record->delete();
                        $result->update(['action' => 'rolled_back']);

                        continue;
                    }

                    $record->update($result->before_json ?? []);
                    $result->update(['action' => 'rolled_back']);
                }

                return true;
            });

            $importBatch->update(['status' => 'cancelled', 'failure_message' => null, 'completed_at' => now()]);
            app(ImpactReportMetricsService::class)->capture($importBatch->organization, $importBatch, 'rollback_after');
        });
    }

    private function findImportTarget(ImportBatch $importBatch, ImportRowResult $result): Employee|Department|Asset|SaasService|SaasAssignment|Contract|SecurityControl|Evidence|QuestionnaireAnswer|null
    {
        return match ($result->target_type) {
            'employees' => Employee::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'departments' => Department::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'assets' => Asset::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'saas_services' => SaasService::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'saas_assignments' => SaasAssignment::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'contracts' => Contract::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'security_controls' => SecurityControl::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'evidence' => Evidence::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'questionnaire_answers' => QuestionnaireAnswer::query()->whereKey($result->target_id)->whereHas('question', fn ($query) => $query->whereHas('questionnaire', fn ($questionnaireQuery) => $questionnaireQuery->where('organization_id', $importBatch->organization_id)))->lockForUpdate()->first(),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function matchesSnapshot(Employee|Department|Asset|SaasService|SaasAssignment|Contract|SecurityControl|Evidence|QuestionnaireAnswer $record, array $snapshot): bool
    {
        foreach ($snapshot as $field => $expected) {
            if ((string) $record->getRawOriginal($field) !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
