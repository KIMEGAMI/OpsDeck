<?php

namespace App\Services\Import;

use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Services\PlanFeatureService;
use App\Services\ProductEventRecorder;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportPreviewPreparationService
{
    public function __construct(
        private CsvImportSource $csvImportSource,
        private XlsxImportSource $xlsxImportSource,
        private GoogleSheetsImportSource $googleSheetsImportSource,
        private ImportSourceIntegrityService $sourceIntegrityService,
        private ImportPreviewRowWriter $previewRowWriter,
        private ProductEventRecorder $productEventRecorder,
    ) {}

    public function prepare(int $importBatchId): void
    {
        $importBatch = ImportBatch::query()->whereKey($importBatchId)->where('status', 'previewing')->first();
        if ($importBatch === null) {
            return;
        }

        if ($importBatch->source_type === 'google_sheets') {
            app(PlanFeatureService::class)->assertAvailable($importBatch->organization, 'google_sheets', 'Google Sheets連携は現在利用できません。');
        }

        $stagingStream = tmpfile();
        if (! is_resource($stagingStream)) {
            throw new RuntimeException('Import Previewの一時領域を準備できません。');
        }

        try {
            $parsed = $this->parseToStream($importBatch, $stagingStream);
            $headers = $parsed['headers'];
            abort_unless($headers !== [], 422, 'ファイルヘッダーがありません。');
            abort_if(in_array('', $headers, true) || count($headers) !== count(array_unique($headers)), 422, 'ヘッダーには空欄や重複を含められません。');

            $completedBatch = DB::transaction(function () use ($importBatch, $headers, $parsed, $stagingStream): ?ImportBatch {
                $lockedBatch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->first();
                if ($lockedBatch === null || $lockedBatch->status !== 'previewing') {
                    return null;
                }

                $lockedBatch->update([
                    'headers_json' => $headers,
                    'header_row_number' => $parsed['header_row_number'] ?? $lockedBatch->header_row_number,
                    'header_end_row_number' => $parsed['header_end_row_number'] ?? $lockedBatch->resolvedHeaderEndRowNumber(),
                    'data_start_row_number' => $parsed['data_start_row_number'] ?? $lockedBatch->resolvedDataStartRowNumber(),
                    'total_rows' => $parsed['total_rows'],
                    'status' => 'preview',
                ]);
                rewind($stagingStream);
                $this->previewRowWriter->write($lockedBatch, $this->stagedRows($stagingStream), $lockedBatch->selected_sheet_name);

                return $lockedBatch;
            });
        } finally {
            fclose($stagingStream);
        }

        if ($completedBatch === null) {
            return;
        }

        $organization = $completedBatch->organization;
        if ($organization !== null) {
            $this->productEventRecorder->recordOnce(
                'first_import_previewed',
                $organization,
            );
        }
    }

    /**
     * @param  resource  $stagingStream
     * @return array{headers: list<string>, total_rows: int, header_row_number?: int, header_end_row_number?: int, data_start_row_number?: int}
     */
    private function parseToStream(ImportBatch $importBatch, $stagingStream): array
    {
        $this->sourceIntegrityService->assertUnchanged($importBatch);

        if ($importBatch->source_type === 'csv' && $importBatch->source_file_path !== null) {
            $sourceStream = Storage::disk($importBatch->sourceStorageDiskName())->readStream($importBatch->source_file_path);
            if (! is_resource($sourceStream)) {
                throw new RuntimeException('CSVを読み込めません。');
            }

            try {
                return $this->csvImportSource->parseEachStream(
                    $sourceStream,
                    function (array $row) use ($stagingStream): void {
                        $this->writeSourceRowNumber($stagingStream, $row);
                    },
                    headerRowNumber: (int) $importBatch->header_row_number,
                    dataStartRowNumber: $importBatch->data_start_row_number,
                    headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber(),
                );
            } finally {
                fclose($sourceStream);
            }
        }

        if ($importBatch->source_type === 'xlsx' && $importBatch->source_file_path !== null && $importBatch->selected_sheet_name !== null) {
            return app(ImportFileLocalStager::class)->withLocalCopy(
                $importBatch->sourceStorageDiskName(),
                $importBatch->source_file_path,
                fn (string $localPath): array => $this->xlsxImportSource->parseEach(
                    $localPath,
                    $importBatch->selected_sheet_name,
                    function (array $row) use ($stagingStream): void {
                        $this->writeSourceRowNumber($stagingStream, $row);
                    },
                    headerRowNumber: (int) $importBatch->header_row_number,
                    dataStartRowNumber: $importBatch->data_start_row_number,
                    headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber(),
                ),
            );
        }

        if ($importBatch->source_type === 'google_sheets'
            && $importBatch->source_external_id !== null
            && $importBatch->selected_sheet_name !== null) {
            $connection = GoogleSheetConnection::query()->where('organization_id', $importBatch->organization_id)->firstOrFail();

            return $this->googleSheetsImportSource->parseEach(
                $connection,
                $importBatch->source_external_id,
                $importBatch->selected_sheet_name,
                function (array $row) use ($stagingStream): void {
                    $this->writeSourceRowNumber($stagingStream, $row);
                },
                headerRowNumber: (int) $importBatch->header_row_number,
                dataStartRowNumber: $importBatch->resolvedDataStartRowNumber(),
                headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber(),
            );
        }

        throw ValidationException::withMessages(['file' => 'Previewを作成する元データが見つかりません。']);
    }

    /**
     * @param  resource  $stagingStream
     * @param  array{source_row_number: int, values: list<string>}  $row
     */
    private function writeSourceRowNumber($stagingStream, array $row): void
    {
        $line = $row['source_row_number'].PHP_EOL;
        if (fwrite($stagingStream, $line) !== strlen($line)) {
            throw new RuntimeException('Import Previewの一時領域へ書き込めません。');
        }

    }

    /**
     * @param  resource  $stagingStream
     * @return Generator<int, array{source_row_number: int}>
     */
    private function stagedRows($stagingStream): Generator
    {
        while (($line = fgets($stagingStream)) !== false) {
            $sourceRowNumber = filter_var(trim($line), FILTER_VALIDATE_INT);
            if (! is_int($sourceRowNumber) || $sourceRowNumber < 1) {
                throw new RuntimeException('Import Previewの一時データを読み取れません。');
            }

            yield ['source_row_number' => $sourceRowNumber];
        }

        if (! feof($stagingStream)) {
            throw new RuntimeException('Import Previewの一時データを読み取れません。');
        }
    }
}
