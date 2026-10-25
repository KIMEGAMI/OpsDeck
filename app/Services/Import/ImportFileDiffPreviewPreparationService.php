<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Services\ImportBatchExecutionService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportFileDiffPreviewPreparationService
{
    public function __construct(
        private CsvImportSource $csvImportSource,
        private XlsxImportSource $xlsxImportSource,
        private ImportSourceIntegrityService $sourceIntegrityService,
        private ImportNormalizationSuggestionService $normalizationService,
        private ImportBatchExecutionService $executionService,
    ) {}

    public function prepare(int $sourceBatchId, int $diffBatchId): void
    {
        $sourceBatch = ImportBatch::query()->whereKey($sourceBatchId)->whereIn('source_type', ['csv', 'xlsx'])->where('status', 'completed')->first();
        $diffBatch = ImportBatch::query()->whereKey($diffBatchId)->where('diff_source_batch_id', $sourceBatchId)
            ->where('organization_id', $sourceBatch?->organization_id)->where('source_type', $sourceBatch?->source_type)->where('status', 'previewing')->first();
        if ($sourceBatch === null || $diffBatch === null) {
            return;
        }

        $mapping = $sourceBatch->mappings()->where('status', 'ready')->latest('id')->firstOrFail();
        $mappedHeaders = array_keys(array_filter($mapping->column_map ?? [], static fn (mixed $targetField): bool => is_string($targetField) && $targetField !== ''));
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
            ->cursor() as $previousRow) {
            $key = $this->executionService->duplicateKey($mapping->target_type, $previousRow->after_json ?? []);
            if ($key === null) {
                continue;
            }
            if (isset($ambiguousPreviousByKey[$key])) {
                $ambiguousPreviousByKey[$key][] = $previousRow;
            } elseif (isset($previousByKey[$key])) {
                $ambiguousPreviousByKey[$key] = [$previousByKey[$key], $previousRow];
                unset($previousByKey[$key]);
            } else {
                $previousByKey[$key] = $previousRow;
            }
        }

        $timestamp = now()->toDateTimeString();
        $currentKeys = [];
        $counts = ['new' => 0, 'updated' => 0, 'error' => 0];
        $headerIndexes = null;
        try {
            $this->sourceIntegrityService->assertUnchanged($diffBatch);
            $parsed = $this->parseRows($diffBatch, function (array $row, array $headers) use ($mapping, $mappedHeaders, &$headerIndexes, &$currentKeys, $previousByKey, $ambiguousPreviousByKey, &$counts, $diffBatch, $timestamp, $stagingStream): void {
                $headerIndexes ??= array_flip($headers);
                foreach ($mappedHeaders as $sourceHeader) {
                    if (! isset($headerIndexes[$sourceHeader])) {
                        throw ValidationException::withMessages(['file' => '差分取込元の列見出しが前回のマッピングと一致しません。通常のPreviewで新しい列マッピングを確認してください。']);
                    }
                }
                $values = [];
                foreach ($mapping->column_map as $sourceHeader => $targetField) {
                    if (is_string($targetField) && $targetField !== '') {
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
                $action = $key === null || $duplicateCurrentKey || ($key !== null && isset($ambiguousPreviousByKey[$key]))
                    ? 'error'
                    : ($previous === null ? 'new_candidate' : ($this->executionService->sameImportValues($previous->after_json ?? [], $values) ? 'unchanged' : 'update_candidate'));
                if ($action === 'new_candidate') {
                    $counts['new']++;
                } elseif ($action === 'update_candidate') {
                    $counts['updated']++;
                } elseif ($action === 'error') {
                    $counts['error']++;
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
                    'errors_json' => $action === 'error' ? json_encode(['row' => $duplicateCurrentKey ? '今回のファイル内で差分キーが重複しています。' : '差分キーが未入力、または前回データで重複しています。'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                    'after_json' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            });
            abort_unless($parsed['headers'] !== [], 422, 'ファイルヘッダーがありません。');
            abort_if(in_array('', $parsed['headers'], true) || count($parsed['headers']) !== count(array_unique($parsed['headers'])), 422, 'ヘッダーには空欄や重複を含められません。');

            DB::transaction(function () use ($sourceBatch, $diffBatch, $mapping, $parsed, $stagingStream, $previousByKey, $ambiguousPreviousByKey, $currentKeys, $counts, $timestamp): void {
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
                    'headers_json' => $parsed['headers'],
                    'header_row_number' => $parsed['header_row_number'] ?? $lockedBatch->header_row_number,
                    'header_end_row_number' => $parsed['header_end_row_number'] ?? $lockedBatch->resolvedHeaderEndRowNumber(),
                    'data_start_row_number' => $parsed['data_start_row_number'] ?? $lockedBatch->resolvedDataStartRowNumber(),
                    'total_rows' => $parsed['total_rows'],
                    'preview_new_rows' => $counts['new'],
                    'preview_updated_rows' => $counts['updated'],
                    'preview_error_rows' => $counts['error'],
                ]);
                $this->writeRowResults($stagingStream, $lockedBatch, $mapping->target_type, $previousByKey, $ambiguousPreviousByKey, $currentKeys, $timestamp);
                $lockedBatch->update(['status' => 'ready', 'completed_at' => now()]);
            });
        } finally {
            fclose($stagingStream);
        }
    }

    /**
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int, header_row_number?: int, header_end_row_number?: int, data_start_row_number?: int}
     */
    private function parseRows(ImportBatch $batch, callable $onRow): array
    {
        $disk = $batch->sourceStorageDiskName();
        if ($batch->source_type === 'xlsx' && $batch->source_file_path !== null && $batch->selected_sheet_name !== null) {
            return app(ImportFileLocalStager::class)->withLocalCopy(
                $disk,
                $batch->source_file_path,
                fn (string $localPath): array => $this->xlsxImportSource->parseEach(
                    $localPath,
                    $batch->selected_sheet_name,
                    $onRow,
                    headerRowNumber: (int) $batch->header_row_number,
                    dataStartRowNumber: $batch->data_start_row_number,
                    headerEndRowNumber: $batch->resolvedHeaderEndRowNumber(),
                ),
            );
        }
        if ($batch->source_type === 'csv' && $batch->source_file_path !== null) {
            $stream = Storage::disk($disk)->readStream($batch->source_file_path);
            if (! is_resource($stream)) {
                throw new RuntimeException('CSVを読み込めません。');
            }
            try {
                return $this->csvImportSource->parseEachStream($stream, $onRow,
                    headerRowNumber: (int) $batch->header_row_number,
                    dataStartRowNumber: $batch->data_start_row_number,
                    headerEndRowNumber: $batch->resolvedHeaderEndRowNumber());
            } finally {
                fclose($stream);
            }
        }
        throw ValidationException::withMessages(['file' => 'Previewを作成する元データが見つかりません。']);
    }

    /** @param resource $stagingStream
     * @param  array<string, mixed>  $rowResult
     */
    private function writeStagedRow($stagingStream, array $rowResult): void
    {
        $line = Crypt::encryptString(json_encode($rowResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)).PHP_EOL;
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
            if (! isset($currentKeys[$key])) {
                $rowResultChunk[] = $this->deletedCandidate($batch, $targetType, $previous, $timestamp);
            }
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
            'after_json' => json_encode($previous->after_json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }
}
