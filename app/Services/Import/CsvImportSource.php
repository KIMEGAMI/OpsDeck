<?php

namespace App\Services\Import;

use Illuminate\Validation\ValidationException;

class CsvImportSource
{
    /**
     * @return array{headers: list<string>, rows: list<array{source_row_number: int, values: list<string>}>}
     */
    public function parse(string $contents): array
    {
        $rows = [];
        $parsed = $this->parseEach($contents, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return ['headers' => $parsed['headers'], 'rows' => $rows];
    }

    /**
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int}
     */
    public function parseEach(string $contents, callable $onRow, ?int $maximumDataRows = null): array
    {
        $contents = $this->decode($contents);
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new \RuntimeException('CSVを解析できません。');
        }

        try {
            if (fwrite($stream, $contents) !== strlen($contents)) {
                throw new \RuntimeException('CSVを解析できません。');
            }
            rewind($stream);

            return $this->parseUtf8Stream($stream, $onRow, $maximumDataRows);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $contentsStream
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int}
     */
    public function parseEachStream(mixed $contentsStream, callable $onRow, ?int $maximumDataRows = null): array
    {
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
                return $this->fallbackToStringParser($stream, $onRow, $maximumDataRows);
            }
            if ($utf8Valid) {
                fseek($stream, $bomOffset);

                return $this->parseUtf8Stream($stream, $onRow, $maximumDataRows);
            }

            $shiftJisValid = $this->validateStreamEncoding($stream, 'convert.iconv.CP932/UTF-8', $bomOffset);
            if ($shiftJisValid === null) {
                return $this->fallbackToStringParser($stream, $onRow, $maximumDataRows);
            }
            if (! $shiftJisValid) {
                throw ValidationException::withMessages([
                    'file' => 'CSVはUTF-8またはShift_JIS（Windows-31J）形式で保存してください。',
                ]);
            }

            fseek($stream, $bomOffset);
            $conversionFilter = stream_filter_append($stream, 'convert.iconv.CP932/UTF-8', STREAM_FILTER_READ);
            if (! is_resource($conversionFilter)) {
                return $this->fallbackToStringParser($stream, $onRow, $maximumDataRows);
            }

            try {
                return $this->parseUtf8Stream($stream, $onRow, $maximumDataRows);
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
     * @return array{headers: list<string>, total_rows: int}
     */
    private function parseUtf8Stream(mixed $stream, callable $onRow, ?int $maximumDataRows): array
    {
        $headers = fgetcsv($stream, null, ',', '"', '');
        if (! is_array($headers) || $headers === []) {
            throw ValidationException::withMessages(['file' => 'CSVヘッダーがありません。']);
        }
        $headers = array_map(static fn (?string $header): string => trim((string) $header), $headers);
        $sourceRowNumber = 2;
        $totalRows = 0;
        $maximumImportRows = max(1, (int) config('opsdeck.import.max_rows'));
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($row === [null] || count(array_filter($row, static fn (?string $value): bool => $value !== null && trim($value) !== '')) === 0) {
                $sourceRowNumber++;

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
            $sourceRowNumber++;
        }

        return ['headers' => $headers, 'total_rows' => $totalRows];
    }

    /**
     * @param  resource  $stream
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int}
     */
    private function fallbackToStringParser(mixed $stream, callable $onRow, ?int $maximumDataRows): array
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        if (! is_string($contents)) {
            throw new \RuntimeException('CSVを読み込めません。');
        }

        return $this->parseEach($contents, $onRow, $maximumDataRows);
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
