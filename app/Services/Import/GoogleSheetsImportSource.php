<?php

namespace App\Services\Import;

use App\Models\GoogleSheetConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GoogleSheetsImportSource
{
    private const string GOOGLE_SHEETS_DATE_EPOCH = '1899-12-30';

    private const int GOOGLE_SHEETS_MAXIMUM_DATE_SERIAL = 2958465;

    /** @return list<array{id: string, name: string}> */
    public function spreadsheets(GoogleSheetConnection $connection): array
    {
        $response = $this->request($connection, 'https://www.googleapis.com/drive/v3/files', [
            'q' => "mimeType = 'application/vnd.google-apps.spreadsheet' and trashed = false",
            'fields' => 'files(id,name)',
            'orderBy' => 'name',
            'pageSize' => 100,
        ]);

        return collect($response->json('files', []))->map(fn (array $file): array => ['id' => (string) ($file['id'] ?? ''), 'name' => (string) ($file['name'] ?? '')])->filter(fn (array $file): bool => $file['id'] !== '' && $file['name'] !== '')->values()->all();
    }

    /** @return list<string> */
    public function sheets(GoogleSheetConnection $connection, string $spreadsheetId): array
    {
        $response = $this->request($connection, 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId), ['fields' => 'sheets.properties.title']);

        return collect($response->json('sheets', []))->map(fn (array $sheet): string => (string) ($sheet['properties']['title'] ?? ''))->filter()->values()->all();
    }

    /** @return array{headers: list<string>, rows: list<array{source_row_number: int, values: list<string>}>} */
    public function parse(GoogleSheetConnection $connection, string $spreadsheetId, string $sheetName, bool $forContracts = false, ?int $maximumDataRows = null): array
    {
        $rows = [];
        $parsed = $this->parseEachInRanges($connection, $spreadsheetId, $sheetName, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        }, $forContracts, $maximumDataRows, false);

        return ['headers' => $parsed['headers'], 'rows' => $rows];
    }

    /**
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int}
     */
    public function parseEach(GoogleSheetConnection $connection, string $spreadsheetId, string $sheetName, callable $onRow, bool $forContracts = false, ?int $maximumDataRows = null): array
    {
        return $this->parseEachInRanges($connection, $spreadsheetId, $sheetName, $onRow, $forContracts, $maximumDataRows, true);
    }

    /**
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int}
     */
    private function parseEachInRanges(GoogleSheetConnection $connection, string $spreadsheetId, string $sheetName, callable $onRow, bool $forContracts, ?int $maximumDataRows, bool $fetchInChunks): array
    {
        $query = ['majorDimension' => 'ROWS'];
        if ($forContracts) {
            $query['valueRenderOption'] = 'UNFORMATTED_VALUE';
            $query['dateTimeRenderOption'] = 'SERIAL_NUMBER';
        }
        $rowCount = $maximumDataRows !== null
            ? max(1, $maximumDataRows) + 1
            : ($fetchInChunks ? $this->sheetRowCount($connection, $spreadsheetId, $sheetName) : null);
        $chunkSize = $rowCount === null || ! $fetchInChunks
            ? $rowCount
            : max(1, (int) config('opsdeck.import.google_sheets_read_chunk_rows'));
        $headers = [];
        $totalRows = 0;
        $maximumImportRows = max(1, (int) config('opsdeck.import.max_rows'));
        $valuesUrl = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId).'/values/';
        $startRow = 1;
        while ($rowCount === null || $startRow <= $rowCount) {
            $endRow = $chunkSize === null ? null : min($rowCount, $startRow + $chunkSize - 1);
            $range = $endRow === null ? $sheetName : $this->quotedSheetName($sheetName).'!'.$startRow.':'.$endRow;
            $rows = $this->request($connection, $valuesUrl.rawurlencode($range), $query)->json('values', []);
            foreach ($rows as $index => $row) {
                $sourceRowNumber = $startRow + $index;
                if ($sourceRowNumber === 1) {
                    $headers = array_map(static fn (mixed $value): string => trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF"), $row);

                    continue;
                }
                if (count(array_filter($row, static fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if ($maximumDataRows !== null && $totalRows >= max(1, $maximumDataRows)) {
                    break 2;
                }
                if ($totalRows >= $maximumImportRows) {
                    throw ValidationException::withMessages([
                        'file' => "Google Sheetsの取込上限（{$maximumImportRows}行）を超えています。シートを分割して再度お試しください。",
                    ]);
                }

                $values = array_map(static fn (mixed $value): string => (string) $value, $row);
                if ($forContracts) {
                    foreach ($headers as $columnIndex => $header) {
                        if (! in_array($header, config('opsdeck.import_date_fields.contracts', []), true) || ! isset($values[$columnIndex])) {
                            continue;
                        }

                        $values[$columnIndex] = $this->dateSerialToIsoDate($values[$columnIndex]) ?? $values[$columnIndex];
                    }
                }

                $onRow(['source_row_number' => $sourceRowNumber, 'values' => $values], $headers);
                $totalRows++;
            }

            if ($endRow === null) {
                break;
            }
            $startRow = $endRow + 1;
        }

        return ['headers' => $headers, 'total_rows' => $totalRows];
    }

    private function sheetRowCount(GoogleSheetConnection $connection, string $spreadsheetId, string $sheetName): int
    {
        $response = $this->request($connection, 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId), [
            'fields' => 'sheets.properties.title,sheets.properties.gridProperties.rowCount',
        ]);
        foreach ($response->json('sheets', []) as $sheet) {
            if (($sheet['properties']['title'] ?? null) !== $sheetName) {
                continue;
            }

            $rowCount = $sheet['properties']['gridProperties']['rowCount'] ?? null;
            if (is_int($rowCount) && $rowCount > 0) {
                return $rowCount;
            }
        }

        throw new RuntimeException('Google Sheetsのシート行数を取得できません。');
    }

    private function quotedSheetName(string $sheetName): string
    {
        return "'".str_replace("'", "''", $sheetName)."'";
    }

    private function dateSerialToIsoDate(string $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $serial = (float) $value;
        if (! is_finite($serial) || $serial < 0 || $serial > self::GOOGLE_SHEETS_MAXIMUM_DATE_SERIAL) {
            return null;
        }

        return CarbonImmutable::parse(self::GOOGLE_SHEETS_DATE_EPOCH)
            ->addDays((int) floor($serial))
            ->toDateString();
    }

    private function request(GoogleSheetConnection $connection, string $url, array $query): Response
    {
        if ($connection->token_expires_at?->lte(now()->addMinute()) && $connection->refresh_token) {
            $tokenResponse = Http::asForm()->timeout(10)->post(config('services.google.token_endpoint'), [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
            abort_unless($tokenResponse->successful() && is_string($tokenResponse->json('access_token')), 422, 'Google Sheetsのアクセストークンを更新できませんでした。');
            $connection->update(['access_token' => $tokenResponse->json('access_token'), 'token_expires_at' => now()->addSeconds(max(0, (int) $tokenResponse->json('expires_in', 3600)))]);
        }
        abort_unless($connection->revoked_at === null && is_string($connection->access_token) && $connection->access_token !== '', 422, 'Google Sheets連携が有効ではありません。');
        $response = Http::withToken($connection->access_token)->acceptJson()->timeout(15)->get($url, $query);
        if ($response->failed()) {
            throw new RuntimeException('Google Sheets APIへのアクセスに失敗しました。');
        }

        return $response;
    }
}
