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
        $this->assertSame([2, 4], array_column($rows, 'source_row_number'));
        $this->assertSame("Tokyo,\r\nHQ", $rows[0]['values'][0]);
    }

    public function test_it_keeps_quoted_commas_and_original_row_numbers(): void
    {
        $parsed = (new CsvImportSource)->parse("name,email\r\n\"Tokyo, HQ\",tokyo@example.test\r\n,\r\nAlice,alice@example.test\r\n");

        $this->assertSame([2, 4], array_column($parsed['rows'], 'source_row_number'));
        $this->assertSame('Tokyo, HQ', $parsed['rows'][0]['values'][0]);
    }

    public function test_it_uses_the_requested_header_row_and_keeps_original_record_numbers(): void
    {
        $contents = "社員管理台帳\r\n2026年度\r\nname,email\r\nnote,集計対象外\r\nAlice,alice@example.test\r\n";
        $rows = [];
        $parsed = (new CsvImportSource)->parseEach($contents, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        }, headerRowNumber: 3, dataStartRowNumber: 5);

        $this->assertSame(['name', 'email'], $parsed['headers']);
        $this->assertSame(1, $parsed['total_rows']);
        $this->assertSame(5, $rows[0]['source_row_number']);
        $this->assertSame(5, $parsed['data_start_row_number']);
        $this->assertSame(['Alice', 'alice@example.test'], $rows[0]['values']);
    }

    public function test_it_keeps_physical_source_row_numbers_after_multiline_csv_fields(): void
    {
        $contents = "name,notes\r\nAlice,\"first line\r\nsecond line\"\r\nBob,ready\r\n";

        $parsed = (new CsvImportSource)->parse($contents);

        $this->assertSame([2, 4], array_column($parsed['rows'], 'source_row_number'));
        $this->assertSame("first line\r\nsecond line", $parsed['rows'][0]['values'][1]);
        $this->assertSame(['Bob', 'ready'], $parsed['rows'][1]['values']);
    }

    public function test_it_combines_multiple_header_rows_without_changing_data_values(): void
    {
        $contents = "台帳タイトル\r\n社員,社員,契約\r\n氏名,メール,開始日\r\nAlice,alice@example.test,2026-01-01\r\n";
        $rows = [];

        $parsed = (new CsvImportSource)->parseEach($contents, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        }, headerRowNumber: 2, dataStartRowNumber: 4, headerEndRowNumber: 3);

        $this->assertSame(['社員 / 氏名', '社員 / メール', '契約 / 開始日'], $parsed['headers']);
        $this->assertSame(2, $parsed['header_row_number']);
        $this->assertSame(3, $parsed['header_end_row_number']);
        $this->assertSame(4, $parsed['data_start_row_number']);
        $this->assertSame(4, $rows[0]['source_row_number']);
        $this->assertSame(['Alice', 'alice@example.test', '2026-01-01'], $rows[0]['values']);
    }

    public function test_it_rejects_a_data_start_row_inside_a_multi_row_header(): void
    {
        try {
            (new CsvImportSource)->parseEach("group\nname\nAlice\n", static function (array $row): void {}, headerRowNumber: 1, dataStartRowNumber: 2, headerEndRowNumber: 2);
            $this->fail('Data rows must begin after the complete header range.');
        } catch (ValidationException $exception) {
            $this->assertSame(['データ開始行番号は見出し範囲の終了行より後、かつ許可範囲内で指定してください。'], $exception->errors()['file']);
        }
    }

    public function test_it_rejects_a_multi_row_header_range_that_ends_after_the_csv(): void
    {
        try {
            (new CsvImportSource)->parseEach("group\nname\nAlice\n", static function (array $row): void {}, headerRowNumber: 1, headerEndRowNumber: 4);
            $this->fail('Incomplete header ranges must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['指定した見出し範囲がCSV内にありません。行番号を確認してください。'], $exception->errors()['file']);
        }
    }

    public function test_it_rejects_duplicate_and_multiple_blank_headers(): void
    {
        foreach ([
            "Employee name, employee name\nAlice,Bob\n",
            "name,,\nAlice,Bob,Carol\n",
        ] as $contents) {
            try {
                (new CsvImportSource)->parse($contents);
                $this->fail('Duplicate or multiple blank headers must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(['見出しに同じ名前、または複数の空欄があります。列名を一意にしてから再度アップロードしてください。'], $exception->errors()['file']);
            }
        }
    }

    public function test_it_rejects_a_data_start_row_not_after_the_header_or_above_the_source_limit(): void
    {
        config(['opsdeck.import.max_source_row_number' => 10]);

        foreach ([3, 11] as $dataStartRowNumber) {
            try {
                (new CsvImportSource)->parseEach("title\nname\nnote\nAlice\n", static function (array $row): void {}, headerRowNumber: 3, dataStartRowNumber: $dataStartRowNumber);
                $this->fail('Invalid data start rows must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(['データ開始行番号は見出し範囲の終了行より後、かつ許可範囲内で指定してください。'], $exception->errors()['file']);
            }
        }
    }

    public function test_it_rejects_a_header_row_outside_the_configured_range(): void
    {
        config(['opsdeck.import.max_header_row_number' => 2]);

        try {
            (new CsvImportSource)->parse("title\nname\nAlice\n", 3);
            $this->fail('CSV header rows above the configured limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['見出し行番号が許可範囲外です。'], $exception->errors()['file']);
        }
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
