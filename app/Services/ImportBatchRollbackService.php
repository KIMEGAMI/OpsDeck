<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
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
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ImportBatchRollbackService
{
    public function rollback(int $importBatchId, int $actorUserId): void
    {
        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));

        DB::transaction(function () use ($importBatchId, $actorUserId, $chunkSize): void {
            $importBatch = ImportBatch::query()->whereKey($importBatchId)->lockForUpdate()->first();
            if ($importBatch === null || $importBatch->status !== 'rolling_back') {
                return;
            }

            $actor = User::query()->find($actorUserId);
            abort_unless($actor !== null, 403);
            Gate::forUser($actor)->authorize('update', $importBatch);

            $importBatch->rowResults()->whereIn('action', ['inserted', 'updated'])->chunkByIdDesc($chunkSize, function (Collection $results) use ($importBatch): bool {
                foreach ($results as $result) {
                    $record = $this->findImportTarget($importBatch, $result);
                    abort_unless($record !== null, 409, '取消対象のデータが見つかりません。');
                    abort_unless($this->matchesSnapshot($record, $result->after_json ?? []), 409, 'Import後に変更されたデータがあるため、安全な取消を中止しました。');

                    if ($result->action === 'inserted') {
                        $asset = null;
                        if ($record instanceof AssetAssignment) {
                            $asset = Asset::query()
                                ->where('organization_id', $importBatch->organization_id)
                                ->whereKey($record->asset_id)
                                ->lockForUpdate()
                                ->firstOrFail();
                            abort_unless($asset->status === config('opsdeck.asset_status.assigned'), 409, 'Import後に関連資産の状態が変更されたため、安全な取消を中止しました。');
                            abort_unless(! AssetAssignment::query()
                                ->where('organization_id', $importBatch->organization_id)
                                ->where('asset_id', $asset->id)
                                ->whereNull('returned_at')
                                ->where('id', '!=', $record->id)
                                ->exists(), 409, 'Import後に別の割当が追加されたため、安全な取消を中止しました。');
                        }
                        $record->delete();
                        if ($asset instanceof Asset) {
                            $asset->update(['status' => config('opsdeck.asset_status.in_stock')]);
                        }
                        $result->update(['action' => 'rolled_back']);

                        continue;
                    }

                    $record->update($result->before_json ?? []);
                    $result->update(['action' => 'rolled_back']);
                }

                return true;
            });

            $importBatch->update(['status' => 'cancelled', 'failure_message' => null, 'completed_at' => now()]);
            AuditLog::query()->create([
                'organization_id' => $importBatch->organization_id,
                'actor_user_id' => $actorUserId,
                'entity_type' => ImportBatch::class,
                'entity_id' => $importBatch->id,
                'action' => AuditLog::ImportBatchRolledBack,
                'after_json' => ['status' => 'cancelled'],
            ]);
            if (config('opsdeck.release_features.impact_report')) {
                app(ImpactReportMetricsService::class)->capture($importBatch->organization, $importBatch, 'rollback_after');
            }
        });
    }

    private function findImportTarget(ImportBatch $importBatch, ImportRowResult $result): Employee|Department|Asset|AssetAssignment|SaasService|SaasAssignment|Contract|SecurityControl|Evidence|QuestionnaireAnswer|null
    {
        return match ($result->target_type) {
            'employees' => Employee::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'departments' => Department::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'assets' => Asset::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
            'asset_assignments' => AssetAssignment::query()->where('organization_id', $importBatch->organization_id)->whereKey($result->target_id)->lockForUpdate()->first(),
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
    private function matchesSnapshot(Employee|Department|Asset|AssetAssignment|SaasService|SaasAssignment|Contract|SecurityControl|Evidence|QuestionnaireAnswer $record, array $snapshot): bool
    {
        foreach ($snapshot as $field => $expected) {
            $current = $record->getAttribute($field);
            if ($current instanceof DateTimeInterface && $expected !== null) {
                $currentDate = CarbonImmutable::instance($current);
                $expectedDate = CarbonImmutable::parse((string) $expected, $currentDate->getTimezone());

                if (! $currentDate->equalTo($expectedDate)) {
                    return false;
                }

                continue;
            }

            if (is_bool($current) || is_bool($expected)) {
                if (filter_var($current, FILTER_VALIDATE_BOOL) !== filter_var($expected, FILTER_VALIDATE_BOOL)) {
                    return false;
                }

                continue;
            }

            $cast = $record->getCasts()[$field] ?? null;
            if (is_string($cast) && str_starts_with($cast, 'decimal:')) {
                $scale = (int) substr($cast, strlen('decimal:'));
                if ($this->normalizeDecimalValue($record->getRawOriginal($field), $scale) !== $this->normalizeDecimalValue($expected, $scale)) {
                    return false;
                }

                continue;
            }

            if ((string) $record->getRawOriginal($field) !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    private function normalizeDecimalValue(mixed $value, int $scale): ?string
    {
        if ($value === null || ! preg_match('/^(?<sign>-?)(?<integer>\d+)(?:\.(?<fraction>\d*))?$/', trim((string) $value), $matches)) {
            return $value === null ? null : (string) $value;
        }

        $integer = ltrim($matches['integer'], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = substr(str_pad($matches['fraction'] ?? '', $scale, '0'), 0, $scale);
        $sign = $matches['sign'] === '-' && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '';

        return $sign.$integer.($scale > 0 ? '.'.$fraction : '');
    }
}
