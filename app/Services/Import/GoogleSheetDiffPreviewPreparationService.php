<?php

namespace App\Services\Import;

use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Services\ImportBatchExecutionService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GoogleSheetDiffPreviewPreparationService
{
    public function __construct(
        private GoogleSheetsImportSource $googleSheetsImportSource,
        private ImportBatchExecutionService $executionService,
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

        $mapping = $sourceBatch->mappings()->where('status', 'ready')->latest('id')->firstOrFail();
        $connection = GoogleSheetConnection::query()->where('organization_id', $sourceBatch->organization_id)->firstOrFail();
        $stagingStream = tmpfile();
        if (! is_resource($stagingStream)) {
            throw new RuntimeException('差分プレビューの一時領域を準備できません。');
        }

        $previousByKey = [];
        foreach ($sourceBatch->rowResults()->whereIn('action', ['inserted', 'updated'])->get() as $previousRow) {
            $key = $this->executionService->duplicateKey($mapping->target_type, $previousRow->after_json ?? []);
            if ($key !== null) {
                $previousByKey[$key] = $previousRow;
            }
        }

        $timestamp = now()->toDateTimeString();
        $currentKeys = [];
        $headers = [];
        $headerIndexes = null;
        try {
            $parsed = $this->googleSheetsImportSource->parseEach(
                $connection,
                (string) $sourceBatch->source_external_id,
                (string) $sourceBatch->selected_sheet_name,
                function (array $row, array $headers) use ($mapping, &$headerIndexes, &$currentKeys, &$previousByKey, $diffBatch, $timestamp, $stagingStream): void {
                    $headerIndexes ??= array_flip($headers);
                    $values = [];
                    foreach ($mapping->column_map as $sourceHeader => $targetField) {
                        if ($targetField !== null && $targetField !== '' && isset($headerIndexes[$sourceHeader])) {
                            $values[$targetField] = trim((string) ($row['values'][$headerIndexes[$sourceHeader]] ?? ''));
                        }
                    }
                    $values = $this->executionService->normalizeImportValues($mapping->target_type, $values);
                    $key = $this->executionService->duplicateKey($mapping->target_type, $values);
                    if ($key !== null) {
                        $currentKeys[$key] = true;
                    }
                    $previous = $key !== null ? ($previousByKey[$key] ?? null) : null;
                    $this->writeStagedRow($stagingStream, [
                        'import_batch_id' => $diffBatch->id,
                        'sheet_name' => $diffBatch->selected_sheet_name,
                        'source_row_number' => $row['source_row_number'],
                        'target_type' => $mapping->target_type,
                        'target_id' => $previous?->target_id,
                        'action' => $previous === null ? 'new_candidate' : ($this->executionService->sameImportValues($previous->after_json ?? [], $values) ? 'unchanged' : 'changed'),
                        'after_json' => json_encode($values, JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                },
                $mapping->target_type === 'contracts',
            );
            $headers = $parsed['headers'];
            $headerIndexes = array_flip($headers);

            DB::transaction(function () use ($sourceBatch, $diffBatch, $mapping, $headers, $parsed, $stagingStream, $previousByKey, $currentKeys, $timestamp): void {
                $lockedBatch = ImportBatch::query()->whereKey($diffBatch->id)->lockForUpdate()->first();
                if ($lockedBatch === null || $lockedBatch->status !== 'previewing' || $lockedBatch->diff_source_batch_id !== $sourceBatch->id) {
                    return;
                }

                $copiedMapping = $lockedBatch->mappings()->create([
                    'target_type' => $mapping->target_type,
                    'column_map' => $mapping->column_map,
                    'normalization_rules' => $mapping->normalization_rules,
                    'status' => 'ready',
                    'created_by' => $lockedBatch->executed_by,
                ]);
                $lockedBatch->update([
                    'mapping_id' => (string) $copiedMapping->id,
                    'headers_json' => $headers,
                    'total_rows' => $parsed['total_rows'],
                ]);

                $this->writeRowResults($stagingStream, $lockedBatch, $mapping->target_type, $previousByKey, $currentKeys, $timestamp);

                $lockedBatch->update(['status' => 'preview']);
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
     * @param  array<string, true>  $currentKeys
     */
    private function writeRowResults($stagingStream, ImportBatch $batch, string $targetType, array $previousByKey, array $currentKeys, string $timestamp): void
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
            $rowResultChunk[] = [
                'import_batch_id' => $batch->id,
                'sheet_name' => $batch->selected_sheet_name,
                'source_row_number' => $previous->source_row_number,
                'target_type' => $targetType,
                'target_id' => $previous->target_id,
                'action' => 'deleted_candidate',
                'after_json' => json_encode($previous->after_json, JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            if (count($rowResultChunk) === $chunkSize) {
                ImportRowResult::query()->insert($rowResultChunk);
                $rowResultChunk = [];
            }
        }

        if ($rowResultChunk !== []) {
            ImportRowResult::query()->insert($rowResultChunk);
        }
    }
}
