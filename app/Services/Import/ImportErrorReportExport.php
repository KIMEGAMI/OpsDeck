<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Services\CsvFormulaSanitizer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportErrorReportExport
{
    public function __construct(private readonly CsvFormulaSanitizer $csvFormulaSanitizer) {}

    public function download(ImportBatch $importBatch): StreamedResponse
    {
        return response()->streamDownload(function () use ($importBatch): void {
            $output = fopen('php://output', 'wb');
            if (! is_resource($output)) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['元行番号', '取込対象', '確認事項'], ',', '"', '');

            $latestRowResultIds = $importBatch->rowResults()
                ->selectRaw('MAX(import_row_results.id)')
                ->groupBy('source_row_number');
            $importBatch->rowResults()
                ->whereIn('id', $latestRowResultIds)
                ->where('action', 'error')
                ->orderBy('source_row_number')
                ->orderBy('id')
                ->cursor()
                ->each(function (ImportRowResult $rowResult) use ($output): void {
                    $errors = collect($rowResult->errors_json ?? [])
                        ->map(fn (mixed $error): string => $this->safeErrorMessage($error))
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(' / ');

                    fputcsv($output, [
                        (string) $rowResult->source_row_number,
                        $this->csvFormulaSanitizer->sanitize((string) config('opsdeck.import_target_labels.'.$rowResult->target_type, '取込対象')),
                        $this->csvFormulaSanitizer->sanitize($errors !== '' ? $errors : 'この行の入力内容を確認してください。'),
                    ], ',', '"', '');
                });

            fclose($output);
        }, 'opsdeck-import-errors-'.$importBatch->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeErrorMessage(mixed $error): string
    {
        $labels = config('opsdeck.import_error_labels', []);
        if (is_string($error)) {
            if (isset($labels[$error]) && is_string($labels[$error])) {
                return $labels[$error];
            }
        }

        return 'この行を取り込めないため、項目と入力形式を確認してください。';
    }
}
