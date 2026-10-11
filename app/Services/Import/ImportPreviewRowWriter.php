<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportRowResult;

class ImportPreviewRowWriter
{
    /**
     * @param  iterable<array{source_row_number: int}>  $rows
     */
    public function write(ImportBatch $importBatch, iterable $rows, ?string $sheetName = null): void
    {
        $timestamp = now();
        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));
        $recordChunk = [];

        foreach ($rows as $row) {
            $recordChunk[] = [
                'import_batch_id' => $importBatch->id,
                'sheet_name' => $sheetName,
                'source_row_number' => $row['source_row_number'],
                'target_type' => 'unmapped',
                'action' => 'preview',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($recordChunk) === $chunkSize) {
                ImportRowResult::query()->insert($recordChunk);
                $recordChunk = [];
            }
        }

        if ($recordChunk !== []) {
            ImportRowResult::query()->insert($recordChunk);
        }
    }
}
