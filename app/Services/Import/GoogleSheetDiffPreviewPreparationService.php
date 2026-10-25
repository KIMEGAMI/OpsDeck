<?php

namespace App\Services\Import;

use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Services\ImportBatchExecutionService;
use App\Services\PlanFeatureService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GoogleSheetDiffPreviewPreparationService
{
    public function __construct(
        private GoogleSheetsImportSource $googleSheetsImportSource,
        private ImportBatchExecutionService $executionService,
        private ImportNormalizationSuggestionService $normalizationService,
    ) {}

    public function prepare(int $sourceBatchId, int $diffBatchId): void
    {
        $sourceBatch = ImportBatch::query()
            ->whereKey($sourceBatchId)
            ->where('source_type', 'google_sheets')
            ->where('status', 'completed')
            ->first();
        $diffBatch = ImportBatch::query()
            ->whereKey($diffBatchId)
            ->where('diff_source_batch_id', $sourceBatchId)
            ->where('organization_id', $sourceBatch?->organization_id)
            ->where('status', 'previewing')
            ->first();

        if ($sourceBatch === null || $diffBatch === null) {
            return;
        }

        app(PlanFeatureService::class)->assertAvailable($sourceBatch->organization, 'google_sheets', 'Google Sheets連携は現在利用できません。');

        $mapping = $sourceBatch->mappings()->where('status', 'ready')->latest('id')->firstOrFail();
        $mappedHeaders = array_keys(array_filter($mapping->column_map ?? [], static fn (mixed $targetField): bool => is_string($targetField) && $targetField !== ''));
        $connection = GoogleSheetConnection::query()->where('organization_id', $sourceBatch->organization_id)->firstOrFail();
        $stagingStream = tmpfile();
        if (! is_resource($stagingStream)) {
            throw new RuntimeException('差分プレビューの一時領域を準備できません。');
        }

        $previousByKey = [];
        $ambiguousPreviousByKey = [];
        $latestSourceRowResultIds = $sourceBatch->rowResults()
            ->selectRaw('MAX(id)')
            ->groupBy('source_row_number');
        foreach ($sourceBatch->rowResults()
            ->whereIn('id', $latestSourceRowResultIds)
            ->whereIn('action', ['inserted', 'updated', 'unchanged', 'stale_conflict'])
            ->get() as $previousRow) {
            $key = $this->executionService->duplicateKey($mapping->target_type, $previousRow->after_json ?? []);
            if ($key !== null) {
                if (isset($ambiguousPreviousByKey[$key])) {
                    $ambiguousPreviousByKey[$key][] = $previousRow;
                } elseif (isset($previousByKey[$key])) {
                    $ambiguousPreviousByKey[$key] = [$previousByKey[$key], $previousRow];
                    unset($previousByKey[$key]);
                } else {
                    $previousByKey[$key] = $previousRow;
                }
            }
        }

        $timestamp = now()->toDateTimeString();
        $currentKeys = [];
        $previewCounts = ['new' => 0, 'updated' => 0, 'error' => 0];
        $headers = [];
        $headerIndexes = null;
        try {
            $parsed = $this->googleSheetsImportSource->parseEach(
                $connection,
                (string) $sourceBatch->source_external_id,
                (string) $sourceBatch->selected_sheet_name,
                function (array $row, array $headers) use ($mapping, &$headerIndexes, &$currentKeys, &$previousByKey, &$ambiguousPreviousByKey, &$previewCounts, $diffBatch, $timestamp, $stagingStream): void {
                    $headerIndexes ??= array_flip($headers);
                    $values = [];
                    foreach ($mapping->column_map as $sourceHeader => $targetField) {
                        if ($targetField !== null && $targetField !== '' && isset($headerIndexes[$sourceHeader])) {
                            $values[$targetField] = trim((string) ($row['values'][$headerIndexes[$sourceHeader]] ?? ''));
                        }
                    }
                    $values = $this->normalizationService->apply($mapping->target_type, $values, $mapping->normalization_rules ?? [], $mapping->value_mappings ?? []);
                    $values = $this->executionService->normalizeImportValues($mapping->target_type, $values);
                    $key = $this->executionService->duplicateKey($mapping->target_type, $values);
                    $duplicateCurrentKey = $key !== null && isset($currentKeys[$key]);
                    if ($key !== null) {
                        $currentKeys[$key] = true;
                    }
                    $previous = $key !== null ? ($previousByKey[$key] ?? null) : null;
                    $ambiguousPreviousKey = $key !== null && isset($ambiguousPreviousByKey[$key]);
                    $action = $key === null || $duplicateCurrentKey || $ambiguousPreviousKey
                        ? 'error'
                        : ($previous === null ? 'new_candidate' : ($this->executionService->sameImportValues($previous->after_json ?? [], $values) ? 'unchanged' : 'update_candidate'));
                    if ($action === 'new_candidate') {
                        $previewCounts['new']++;
                    } elseif ($action === 'update_candidate') {
                        $previewCounts['updated']++;
                    } elseif ($action === 'error') {
                        $previewCounts['error']++;
                    }
                    $this->writeStagedRow($stagingStream, [
                        'import_batch_id' => $diffBatch->id,
                        'sheet_name' => $diffBatch->selected_sheet_name,
                        'source_row_number' => $row['source_row_number'],
                        'target_type' => $mapping->target_type,
                        'target_id' => $previous?->target_id,
                        'action' => $action,
                        'is_selected' => in_array($action, ['new_candidate', 'update_candidate'], true),
                        'before_json' => $previous === null ? null : json_encode($previous->after_json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'errors_json' => $action === 'error' ? json_encode(['row' => $duplicateCurrentKey ? '今回のシート内で差分キーが重複しています。' : '差分キーが未入力、または前回データで重複しています。'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                        'after_json' => json_encode($values, JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                },
                $mapping->target_type === 'contracts',
                headerRowNumber: (int) $sourceBatch->header_row_number,
                dataStartRowNumber: $sourceBatch->resolvedDataStartRowNumber(),
                headerEndRowNumber: $sourceBatch->resolvedHeaderEndRowNumber(),
            );
            $headers = $parsed['headers'];
            $missingHeaders = array_values(array_diff($mappedHeaders, $headers));
            if ($missingHeaders !== []) {
                throw ValidationException::withMessages(['file' => '差分取込元の列見出しが前回のマッピングと一致しません。通常のPreviewで新しい列マッピングを確認してください。']);
            }
            $headerIndexes = array_flip($headers);

            DB::transaction(function () use ($sourceBatch, $diffBatch, $mapping, $headers, $parsed, $stagingStream, $previousByKey, $ambiguousPreviousByKey, $currentKeys, $previewCounts, $timestamp): void {
                $lockedBatch = ImportBatch::query()->whereKey($diffBatch->id)->lockForUpdate()->first();
                if ($lockedBatch === null || $lockedBatch->status !== 'previewing' || $lockedBatch->diff_source_batch_id !== $sourceBatch->id) {
                    return;
                }

                $copiedMapping = $lockedBatch->mappings()->create([
                    'target_type' => $mapping->target_type,
                    'column_map' => $mapping->column_map,
                    'normalization_rules' => $mapping->normalization_rules,
                    'value_mappings' => $mapping->value_mappings,
                    'status' => 'ready',
                    'created_by' => $lockedBatch->executed_by,
                ]);
                $lockedBatch->update([
                    'mapping_id' => (string) $copiedMapping->id,
                    'headers_json' => $headers,
                    'total_rows' => $parsed['total_rows'],
                    'preview_new_rows' => $previewCounts['new'],
                    'preview_updated_rows' => $previewCounts['updated'],
                    'preview_error_rows' => $previewCounts['error'],
                ]);

                $this->writeRowResults($stagingStream, $lockedBatch, $mapping->target_type, $previousByKey, $ambiguousPreviousByKey, $currentKeys, $timestamp);

                $lockedBatch->update(['status' => 'ready']);
            });
        } finally {
            fclose($stagingStream);
        }
    }

    /**
     * @param  resource  $stagingStream
     * @param  array<string, mixed>  $rowResult
     */
    private function writeStagedRow($stagingStream, array $rowResult): void
    {
        $line = Crypt::encryptString(json_encode($rowResult, JSON_THROW_ON_ERROR)).PHP_EOL;
        if (fwrite($stagingStream, $line) !== strlen($line)) {
            throw new RuntimeException('差分プレビューの一時データを書き込めません。');
        }
    }

    /**
     * @param  resource  $stagingStream
     * @param  array<string, ImportRowResult>  $previousByKey
     * @param  array<string, list<ImportRowResult>>  $ambiguousPreviousByKey
     * @param  array<string, true>  $currentKeys
     */
    private function writeRowResults($stagingStream, ImportBatch $batch, string $targetType, array $previousByKey, array $ambiguousPreviousByKey, array $currentKeys, string $timestamp): void
    {
        rewind($stagingStream);
        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));
        $rowResultChunk = [];
        while (($line = fgets($stagingStream)) !== false) {
            $rowResult = json_decode(Crypt::decryptString(trim($line)), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($rowResult)) {
                throw new RuntimeException('差分プレビューの一時データを読み取れません。');
            }
            $rowResultChunk[] = $rowResult;
            if (count($rowResultChunk) === $chunkSize) {
                ImportRowResult::query()->insert($rowResultChunk);
                $rowResultChunk = [];
            }
        }
        if (! feof($stagingStream)) {
            throw new RuntimeException('差分プレビューの一時データを読み取れません。');
        }

        foreach ($previousByKey as $key => $previous) {
            if (isset($currentKeys[$key])) {
                continue;
            }
            $rowResultChunk[] = $this->deletedCandidate($batch, $targetType, $previous, $timestamp);
            if (count($rowResultChunk) === $chunkSize) {
                ImportRowResult::query()->insert($rowResultChunk);
                $rowResultChunk = [];
            }
        }
        foreach ($ambiguousPreviousByKey as $key => $previousRows) {
            if (! isset($currentKeys[$key])) {
                foreach ($previousRows as $previous) {
                    $rowResultChunk[] = $this->deletedCandidate($batch, $targetType, $previous, $timestamp);
                    if (count($rowResultChunk) === $chunkSize) {
                        ImportRowResult::query()->insert($rowResultChunk);
                        $rowResultChunk = [];
                    }
                }
            }
        }

        if ($rowResultChunk !== []) {
            ImportRowResult::query()->insert($rowResultChunk);
        }
    }

    /** @return array<string, mixed> */
    private function deletedCandidate(ImportBatch $batch, string $targetType, ImportRowResult $previous, string $timestamp): array
    {
        return [
            'import_batch_id' => $batch->id,
            'sheet_name' => $batch->selected_sheet_name,
            'source_row_number' => $previous->source_row_number,
            'target_type' => $targetType,
            'target_id' => $previous->target_id,
            'action' => 'deleted_candidate',
            'is_selected' => false,
            'before_json' => null,
            'errors_json' => null,
            'after_json' => json_encode($previous->after_json, JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }
}
