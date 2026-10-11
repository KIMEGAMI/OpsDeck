<?php

namespace Tests\Unit;

use App\Services\Import\CsvImportSource;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CsvImportSourceTest extends TestCase
{
    public function test_it_parses_utf8_bom_and_shift_jis_csv_with_the_same_values(): void
    {
        $utf8 = "\xEF\xBB\xBF氏名,email\r\n山田太郎,taro@example.test\r\n\r\n";
        $source = new CsvImportSource;

        $utf8Parsed = $source->parse($utf8);
        $shiftJisParsed = $source->parse(mb_convert_encoding(substr($utf8, 3), 'SJIS-win', 'UTF-8'));
        $streamedRows = [];
        $streamed = $source->parseEach($utf8, static function (array $row) use (&$streamedRows): void {
            $streamedRows[] = $row;
        });

        $this->assertSame(['氏名', 'email'], $utf8Parsed['headers']);
        $this->assertSame($utf8Parsed, $shiftJisParsed);
        $this->assertSame(['氏名', 'email'], $streamed['headers']);
        $this->assertSame(1, $streamed['total_rows']);
        $this->assertSame($utf8Parsed['rows'], $streamedRows);
        $this->assertSame(2, $shiftJisParsed['rows'][0]['source_row_number']);
        $this->assertSame(['山田太郎', 'taro@example.test'], $shiftJisParsed['rows'][0]['values']);
    }

    public function test_it_streams_utf8_and_shift_jis_csv_with_the_same_values(): void
    {
        $contents = "\xEF\xBB\xBF氏名,email\r\n山田太郎,taro@example.test\r\n\r\n";
        $source = new CsvImportSource;
        $utf8Stream = fopen('php://temp', 'w+b');
        fwrite($utf8Stream, $contents);
        rewind($utf8Stream);
        $utf8Rows = [];
        $utf8Parsed = $source->parseEachStream($utf8Stream, static function (array $row) use (&$utf8Rows): void {
            $utf8Rows[] = $row;
        });
        fclose($utf8Stream);

        $shiftJisStream = fopen('php://temp', 'w+b');
        fwrite($shiftJisStream, mb_convert_encoding(substr($contents, 3), 'SJIS-win', 'UTF-8'));
        rewind($shiftJisStream);
        $shiftJisRows = [];
        $shiftJisParsed = $source->parseEachStream($shiftJisStream, static function (array $row) use (&$shiftJisRows): void {
            $shiftJisRows[] = $row;
        });
        fclose($shiftJisStream);

        $this->assertSame(['氏名', 'email'], $utf8Parsed['headers']);
        $this->assertSame($utf8Parsed, $shiftJisParsed);
        $this->assertSame(1, $utf8Parsed['total_rows']);
        $this->assertSame($shiftJisRows, $utf8Rows);
        $this->assertSame('山田太郎', $utf8Rows[0]['values'][0]);
    }

    public function test_it_parses_non_seekable_streams_with_quoted_newlines(): void
    {
        $contents = "name,email\r\n\"Tokyo,\r\nHQ\",tokyo@example.test\r\nAlice,alice@example.test\r\n";
        $stream = fopen('data://text/plain;base64,'.base64_encode($contents), 'rb');
        $rows = [];
        $parsed = (new CsvImportSource)->parseEachStream($stream, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });
        fclose($stream);

        $this->assertSame(['name', 'email'], $parsed['headers']);
        $this->assertSame([2, 3], array_column($rows, 'source_row_number'));
        $this->assertSame("Tokyo,\r\nHQ", $rows[0]['values'][0]);
    }

    public function test_it_keeps_quoted_commas_and_original_row_numbers(): void
    {
        $parsed = (new CsvImportSource)->parse("name,email\r\n\"Tokyo, HQ\",tokyo@example.test\r\n,\r\nAlice,alice@example.test\r\n");

        $this->assertSame([2, 4], array_column($parsed['rows'], 'source_row_number'));
        $this->assertSame('Tokyo, HQ', $parsed['rows'][0]['values'][0]);
    }

    public function test_it_stops_after_the_configured_number_of_data_rows(): void
    {
        $rows = [];
        $parsed = (new CsvImportSource)->parseEach("name,email\nAlice,alice@example.test\nBob,bob@example.test\n", static function (array $row) use (&$rows): void {
            $rows[] = $row;
        }, 1);

        $this->assertSame(1, $parsed['total_rows']);
        $this->assertSame([2], array_column($rows, 'source_row_number'));
        $this->assertSame('Alice', $rows[0]['values'][0]);
    }

    public function test_it_rejects_csv_that_exceeds_the_configured_import_row_limit(): void
    {
        config(['opsdeck.import.max_rows' => 2]);
        $rows = [];

        try {
            (new CsvImportSource)->parseEach("name\nAlice\nBob\nCarol\n", static function (array $row) use (&$rows): void {
                $rows[] = $row;
            });
            $this->fail('CSV imports above the configured row limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['CSVの取込上限（2行）を超えています。ファイルを分割して再度お試しください。'], $exception->errors()['file']);
            $this->assertSame([2, 3], array_column($rows, 'source_row_number'));
        }
    }

    public function test_it_rejects_csv_with_an_unsupported_encoding(): void
    {
        try {
            (new CsvImportSource)->parse("\x81");
            $this->fail('Unsupported CSV encodings must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['CSVはUTF-8またはShift_JIS（Windows-31J）形式で保存してください。'], $exception->errors()['file']);
        }
    }
}
