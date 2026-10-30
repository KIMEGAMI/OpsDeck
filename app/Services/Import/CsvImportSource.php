<?php

namespace App\Services\Import;

use Illuminate\Validation\ValidationException;

class CsvImportSource
{
    /**
     * @return array{headers: list<string>, rows: list<array{source_row_number: int, values: list<string>}>}
     */
    public function parse(string $contents, int $headerRowNumber = 1, ?int $dataStartRowNumber = null, ?int $headerEndRowNumber = null): array
    {
        $rows = [];
        $parsed = $this->parseEach($contents, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        }, headerRowNumber: $headerRowNumber, dataStartRowNumber: $dataStartRowNumber, headerEndRowNumber: $headerEndRowNumber);

        return ['headers' => $parsed['headers'], 'rows' => $rows];
    }

    /**
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int, header_row_number: int, header_end_row_number: int, data_start_row_number: int}
     */
    public function parseEach(string $contents, callable $onRow, ?int $maximumDataRows = null, int $headerRowNumber = 1, ?int $dataStartRowNumber = null, ?int $headerEndRowNumber = null): array
    {
        $headerEndRowNumber ??= $headerRowNumber;
        $this->assertHeaderRangeAllowed($headerRowNumber, $headerEndRowNumber);
        $this->assertDataStartRowNumberAllowed($headerEndRowNumber, $dataStartRowNumber);
        $contents = $this->decode($contents);
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new \RuntimeException('CSVを解析できません。');
        }

        try {
            if (fwrite($stream, $contents) !== strlen($contents)) {
                throw new \RuntimeException('CSVを解析できません。');
            }
            $this->assertMaximumColumnsAllowed($stream);
            rewind($stream);

            return $this->parseUtf8Stream($stream, $onRow, $maximumDataRows, $headerRowNumber, $dataStartRowNumber, $headerEndRowNumber);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $contentsStream
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int, header_row_number: int, header_end_row_number: int, data_start_row_number: int}
     */
    public function parseEachStream(mixed $contentsStream, callable $onRow, ?int $maximumDataRows = null, int $headerRowNumber = 1, ?int $dataStartRowNumber = null, ?int $headerEndRowNumber = null): array
    {
        $headerEndRowNumber ??= $headerRowNumber;
        $this->assertHeaderRangeAllowed($headerRowNumber, $headerEndRowNumber);
        $this->assertDataStartRowNumberAllowed($headerEndRowNumber, $dataStartRowNumber);
        if (! is_resource($contentsStream) || get_resource_type($contentsStream) !== 'stream') {
            throw new \RuntimeException('CSVを解析できません。');
        }

        $stream = $contentsStream;
        $ownsStream = false;
        if (! (stream_get_meta_data($stream)['seekable'] ?? false)) {
            $stream = fopen('php://temp', 'w+b');
            if (! is_resource($stream)) {
                throw new \RuntimeException('CSVを解析できません。');
            }
            $ownsStream = true;
            if (stream_copy_to_stream($contentsStream, $stream) === false) {
                fclose($stream);
                throw new \RuntimeException('CSVを読み込めません。');
            }
            rewind($stream);
        }

        try {
            $bomOffset = $this->bomOffset($stream);
            $utf8Valid = $this->validateStreamEncoding($stream, 'convert.iconv.UTF-8/UTF-8', $bomOffset);
            if ($utf8Valid === null) {
                return $this->fallbackToStringParser($stream, $onRow, $maximumDataRows, $headerRowNumber, $dataStartRowNumber, $headerEndRowNumber);
            }
            if ($utf8Valid) {
                $this->assertMaximumColumnsAllowed($stream, $bomOffset);
                fseek($stream, $bomOffset);

                return $this->parseUtf8Stream($stream, $onRow, $maximumDataRows, $headerRowNumber, $dataStartRowNumber, $headerEndRowNumber);
            }

            $shiftJisValid = $this->validateStreamEncoding($stream, 'convert.iconv.CP932/UTF-8', $bomOffset);
            if ($shiftJisValid === null) {
                return $this->fallbackToStringParser($stream, $onRow, $maximumDataRows, $headerRowNumber, $dataStartRowNumber, $headerEndRowNumber);
            }
            if (! $shiftJisValid) {
                throw ValidationException::withMessages([
                    'file' => 'CSVはUTF-8またはShift_JIS（Windows-31J）形式で保存してください。',
                ]);
            }

            $this->assertMaximumColumnsAllowed($stream, $bomOffset);
            fseek($stream, $bomOffset);
            $conversionFilter = stream_filter_append($stream, 'convert.iconv.CP932/UTF-8', STREAM_FILTER_READ);
            if (! is_resource($conversionFilter)) {
                return $this->fallbackToStringParser($stream, $onRow, $maximumDataRows, $headerRowNumber, $dataStartRowNumber, $headerEndRowNumber);
            }

            try {
                return $this->parseUtf8Stream($stream, $onRow, $maximumDataRows, $headerRowNumber, $dataStartRowNumber, $headerEndRowNumber);
            } finally {
                set_error_handler(static fn (): bool => true);
                stream_filter_remove($conversionFilter);
                restore_error_handler();
            }
        } finally {
            if ($ownsStream) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  resource  $stream
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int, header_row_number: int, header_end_row_number: int, data_start_row_number: int}
     */
    private function parseUtf8Stream(mixed $stream, callable $onRow, ?int $maximumDataRows, int $headerRowNumber, ?int $dataStartRowNumber, int $headerEndRowNumber): array
    {
        $headers = [];
        $headerRows = [];
        $headerEndReached = false;
        $sourceRowNumber = 1;
        $totalRows = 0;
        $maximumImportRows = max(1, (int) config('opsdeck.import.max_rows'));
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($sourceRowNumber < $headerRowNumber) {
                $sourceRowNumber = $this->nextSourceRowNumber($sourceRowNumber, $row);

                continue;
            }
            if ($sourceRowNumber >= $headerRowNumber && $sourceRowNumber <= $headerEndRowNumber) {
                $headerRows[] = array_map(static fn (?string $header): string => trim((string) $header), $row);
                if ($sourceRowNumber === $headerEndRowNumber) {
                    $validator = app(ImportHeaderValidator::class);
                    $headers = $validator->composeRows($headerRows);
                    $validator->assertUnique($headers);
                    $headerEndReached = true;
                }
                $sourceRowNumber = $this->nextSourceRowNumber($sourceRowNumber, $row);

                continue;
            }
            if ($sourceRowNumber < ($dataStartRowNumber ?? $headerEndRowNumber + 1)) {
                $sourceRowNumber = $this->nextSourceRowNumber($sourceRowNumber, $row);

                continue;
            }
            if ($row === [null] || count(array_filter($row, static fn (?string $value): bool => $value !== null && trim($value) !== '')) === 0) {
                $sourceRowNumber = $this->nextSourceRowNumber($sourceRowNumber, $row);

                continue;
            }
            if ($totalRows >= $maximumImportRows) {
                throw ValidationException::withMessages([
                    'file' => "CSVの取込上限（{$maximumImportRows}行）を超えています。ファイルを分割して再度お試しください。",
                ]);
            }

            $onRow([
                'source_row_number' => $sourceRowNumber,
                'values' => array_map(static fn (?string $value): string => (string) ($value ?? ''), $row),
            ], $headers);
            $totalRows++;
            if ($maximumDataRows !== null && $totalRows >= max(1, $maximumDataRows)) {
                break;
            }
            $sourceRowNumber = $this->nextSourceRowNumber($sourceRowNumber, $row);
        }

        if (! $headerEndReached) {
            throw ValidationException::withMessages(['file' => '指定した見出し範囲がCSV内にありません。行番号を確認してください。']);
        }
        if ($headers === []) {
            throw ValidationException::withMessages(['file' => '指定した行にCSVヘッダーがありません。行番号を確認してください。']);
        }

        return [
            'headers' => $headers,
            'total_rows' => $totalRows,
            'header_row_number' => $headerRowNumber,
            'header_end_row_number' => $headerEndRowNumber,
            'data_start_row_number' => $dataStartRowNumber ?? $headerEndRowNumber + 1,
        ];
    }

    /**
     * @param  list<?string>  $row
     */
    private function nextSourceRowNumber(int $sourceRowNumber, array $row): int
    {
        $physicalLineCount = 1;
        foreach ($row as $value) {
            if (! is_string($value)) {
                continue;
            }

            $lineBreakCount = preg_match_all('/\r\n|\r|\n/', $value);
            $physicalLineCount += $lineBreakCount === false ? 0 : $lineBreakCount;
        }

        return $sourceRowNumber + $physicalLineCount;
    }

    /**
     * @param  resource  $stream
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int, header_row_number: int, header_end_row_number: int, data_start_row_number: int}
     */
    private function fallbackToStringParser(mixed $stream, callable $onRow, ?int $maximumDataRows, int $headerRowNumber, ?int $dataStartRowNumber, int $headerEndRowNumber): array
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        if (! is_string($contents)) {
            throw new \RuntimeException('CSVを読み込めません。');
        }

        return $this->parseEach($contents, $onRow, $maximumDataRows, $headerRowNumber, $dataStartRowNumber, $headerEndRowNumber);
    }

    private function assertHeaderRangeAllowed(int $headerRowNumber, int $headerEndRowNumber): void
    {
        if ($headerRowNumber < 1
            || $headerEndRowNumber < $headerRowNumber
            || $headerEndRowNumber > (int) config('opsdeck.import.max_header_row_number')) {
            throw ValidationException::withMessages(['file' => '見出し行番号が許可範囲外です。']);
        }
    }

    private function assertDataStartRowNumberAllowed(int $headerEndRowNumber, ?int $dataStartRowNumber): void
    {
        if ($dataStartRowNumber !== null
            && ($dataStartRowNumber <= $headerEndRowNumber || $dataStartRowNumber > (int) config('opsdeck.import.max_source_row_number'))) {
            throw ValidationException::withMessages(['file' => 'データ開始行番号は見出し範囲の終了行より後、かつ許可範囲内で指定してください。']);
        }
    }

    /** @param resource $stream */
    private function assertMaximumColumnsAllowed(mixed $stream, int $startOffset = 0): void
    {
        $maximumColumns = max(1, (int) config('opsdeck.import.max_columns'));
        $columnCount = 1;
        $isFieldStart = true;
        $insideQuotedField = false;
        $quotePending = false;

        fseek($stream, $startOffset);
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new \RuntimeException('CSVを読み込めません。');
                }

                $chunkLength = strlen($chunk);
                for ($index = 0; $index < $chunkLength; $index++) {
                    $character = $chunk[$index];

                    if ($insideQuotedField) {
                        if (! $quotePending) {
                            if ($character === '"') {
                                $quotePending = true;
                            }

                            continue;
                        }

                        $quotePending = false;
                        if ($character === '"') {
                            continue;
                        }

                        $insideQuotedField = false;
                    }

                    if ($character === ',') {
                        $columnCount++;
                        if ($columnCount > $maximumColumns) {
                            throw ValidationException::withMessages([
                                'file' => "CSVの列数が上限（{$maximumColumns}列）を超えています。列数を減らして再度お試しください。",
                            ]);
                        }

                        $isFieldStart = true;

                        continue;
                    }

                    if ($character === '"' && $isFieldStart) {
                        $insideQuotedField = true;
                        $isFieldStart = false;

                        continue;
                    }

                    if ($character === "\r" || $character === "\n") {
                        $columnCount = 1;
                        $isFieldStart = true;

                        continue;
                    }

                    $isFieldStart = false;
                }
            }
        } finally {
            rewind($stream);
        }
    }

    /** @param resource $stream */
    private function bomOffset(mixed $stream): int
    {
        rewind($stream);
        $prefix = '';
        while (strlen($prefix) < 3 && ! feof($stream)) {
            $chunk = fread($stream, 3 - strlen($prefix));
            if ($chunk === false) {
                throw new \RuntimeException('CSVを読み込めません。');
            }
            $prefix .= $chunk;
        }
        rewind($stream);

        return $prefix === "\xEF\xBB\xBF" ? 3 : 0;
    }

    /**
     * @param  resource  $stream
     */
    private function validateStreamEncoding(mixed $stream, string $filterName, int $bomOffset): ?bool
    {
        $failed = false;
        $filter = false;
        set_error_handler(static function (int $severity, string $message) use (&$failed): bool {
            if ((error_reporting() & $severity) !== 0) {
                $failed = true;

                return true;
            }

            return false;
        });

        try {
            if (fseek($stream, $bomOffset) !== 0) {
                return null;
            }
            $filter = stream_filter_append($stream, $filterName, STREAM_FILTER_READ);
            if (! is_resource($filter)) {
                return null;
            }
            while (! feof($stream)) {
                if (fread($stream, 8192) === false) {
                    $failed = true;
                    break;
                }
            }
        } finally {
            if (is_resource($filter)) {
                stream_filter_remove($filter);
            }
            restore_error_handler();
            rewind($stream);
            fseek($stream, $bomOffset);
        }

        return ! $failed;
    }

    private function decode(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }
        if (mb_check_encoding($contents, 'SJIS-win')) {
            return mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');
        }

        throw ValidationException::withMessages([
            'file' => 'CSVはUTF-8またはShift_JIS（Windows-31J）形式で保存してください。',
        ]);
    }
}
