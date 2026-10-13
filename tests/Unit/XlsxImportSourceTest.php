<?php

namespace Tests\Unit;

use App\Services\Import\XlsxImportSource;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class XlsxImportSourceTest extends TestCase
{
    public function test_it_reads_the_first_sheet_and_shared_strings(): void
    {
        $path = $this->createXlsxFile([
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="社員一覧" sheetId="1" r:id="rId1"/><sheet name="資産一覧" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/><Relationship Id="rId2" Target="worksheets/sheet3.xml" Type="worksheet"/></Relationships>',
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="164"/><xf numFmtId="4"/></cellXfs></styleSheet>',
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>名前</t></si><si><t>メール</t></si><si><t>入社日</t></si><si><t>金額</t></si><si><t>Alice</t></si><si><t>a@example.test</t></si><si><t>Bob</t></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row><row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2" s="1"><v>45292</v></c><c r="D2" s="2"><v>1234.56</v></c></row><row r="4"><c r="A4" t="s"><v>6</v></c><c r="C4" s="1"><v>46023</v></c><c r="D4" s="2"><v>9999</v></c></row></sheetData></worksheet>',
            'xl/worksheets/sheet3.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>資産番号</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>AST-001</t></is></c></row></sheetData></worksheet>',
        ]);

        try {
            $parsed = (new XlsxImportSource)->parse($path);
            $streamedRows = [];
            $streamed = (new XlsxImportSource)->parseEach($path, null, static function (array $row) use (&$streamedRows): void {
                $streamedRows[] = $row;
            });
            $limitedRows = [];
            $limited = (new XlsxImportSource)->parseEach($path, null, static function (array $row) use (&$limitedRows): void {
                $limitedRows[] = $row;
            }, 1);
            $this->assertSame(['社員一覧', '資産一覧'], (new XlsxImportSource)->sheets($path));
            $this->assertSame(['名前', 'メール', '入社日', '金額'], $parsed['headers']);
            $this->assertSame($parsed['headers'], $streamed['headers']);
            $this->assertSame(count($parsed['rows']), $streamed['total_rows']);
            $this->assertSame($parsed['rows'], $streamedRows);
            $this->assertSame(1, $limited['total_rows']);
            $this->assertSame([2], array_column($limitedRows, 'source_row_number'));
            $this->assertSame(['Alice', 'a@example.test', '2024-01-01', '1234.56'], $parsed['rows'][0]['values']);
            $this->assertSame(2, $parsed['rows'][0]['source_row_number']);
            $this->assertSame(['Bob', '', '2026-01-01', '9999'], $parsed['rows'][1]['values']);
            $this->assertSame(4, $parsed['rows'][1]['source_row_number']);

            $selectedSheet = (new XlsxImportSource)->parse($path, '資産一覧');
            $this->assertSame(['資産番号'], $selectedSheet['headers']);
            $this->assertSame(['AST-001'], $selectedSheet['rows'][0]['values']);
        } finally {
            unlink($path);
        }
    }

    public function test_sheet_discovery_does_not_expand_external_xml_entities(): void
    {
        $externalEntityContents = 'OPSDECK_EXTERNAL_ENTITY_SENTINEL';
        $temporaryDirectory = storage_path('framework/testing-xlsx');
        File::ensureDirectoryExists($temporaryDirectory);
        $externalEntityPath = tempnam($temporaryDirectory, 'opsdeck-xxe-');
        $this->assertIsString($externalEntityPath);
        $this->assertSame(strlen($externalEntityContents), file_put_contents($externalEntityPath, $externalEntityContents));
        $externalEntityUri = 'file:///'.ltrim(str_replace('\\', '/', $externalEntityPath), '/');
        $workbookXml = '<!DOCTYPE workbook [<!ENTITY external SYSTEM "'.htmlspecialchars($externalEntityUri, ENT_QUOTES | ENT_XML1).'">]>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="&external;" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $path = $this->createXlsxFile(array_replace($this->minimalXlsxFiles(''), ['xl/workbook.xml' => $workbookXml]));

        try {
            try {
                $sheetNames = (new XlsxImportSource)->sheets($path);
                $this->assertArrayNotHasKey($externalEntityContents, $sheetNames);
            } catch (\Throwable $exception) {
                $this->assertStringNotContainsString($externalEntityContents, $exception->getMessage());
            }
        } finally {
            unlink($path);
            unlink($externalEntityPath);
        }
    }

    public function test_it_rejects_xlsx_imports_above_the_shared_row_limit(): void
    {
        config(['opsdeck.import.max_rows' => 1]);
        $path = $this->createXlsxFile($this->minimalXlsxFiles('<row r="1"><c r="A1" t="inlineStr"><is><t>Name</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Alice</t></is></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>Bob</t></is></c></row>'));

        try {
            (new XlsxImportSource)->parse($path);
            $this->fail('XLSX imports above the configured row limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['XLSXの取込上限（1行）を超えています。ファイルを分割して再度お試しください。'], $exception->errors()['file']);
        } finally {
            unlink($path);
        }
    }

    public function test_it_uses_the_requested_header_row_and_preserves_worksheet_row_numbers(): void
    {
        $worksheetRows = '<row r="1"><c r="A1" t="inlineStr"><is><t>社員管理台帳</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>2026年度</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>name</t></is></c><c r="B3" t="inlineStr"><is><t>email</t></is></c></row>'
            .'<row r="4"><c r="A4" t="inlineStr"><is><t>note</t></is></c><c r="B4" t="inlineStr"><is><t>集計対象外</t></is></c></row>'
            .'<row r="5"><c r="A5" t="inlineStr"><is><t>Alice</t></is></c><c r="B5" t="inlineStr"><is><t>alice@example.test</t></is></c></row>';
        $path = $this->createXlsxFile($this->minimalXlsxFiles($worksheetRows));

        try {
            $parsed = (new XlsxImportSource)->parse($path, null, 3, 5);

            $this->assertSame(['name', 'email'], $parsed['headers']);
            $this->assertSame(1, count($parsed['rows']));
            $this->assertSame(5, $parsed['rows'][0]['source_row_number']);
            $this->assertSame(['Alice', 'alice@example.test'], $parsed['rows'][0]['values']);
        } finally {
            unlink($path);
        }
    }

    public function test_it_combines_multiple_header_rows_and_preserves_worksheet_row_numbers(): void
    {
        $worksheetRows = '<row r="2"><c r="A2" t="inlineStr"><is><t>社員</t></is></c><c r="B2" t="inlineStr"><is><t>社員</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>氏名</t></is></c><c r="B3" t="inlineStr"><is><t>メール</t></is></c></row>'
            .'<row r="4"><c r="A4" t="inlineStr"><is><t>Alice</t></is></c><c r="B4" t="inlineStr"><is><t>alice@example.test</t></is></c></row>';
        $path = $this->createXlsxFile($this->minimalXlsxFiles($worksheetRows));

        try {
            $rows = [];
            $parsed = (new XlsxImportSource)->parseEach($path, null, static function (array $row) use (&$rows): void {
                $rows[] = $row;
            }, headerRowNumber: 2, dataStartRowNumber: 4, headerEndRowNumber: 3);

            $this->assertSame(['社員 / 氏名', '社員 / メール'], $parsed['headers']);
            $this->assertSame(2, $parsed['header_row_number']);
            $this->assertSame(3, $parsed['header_end_row_number']);
            $this->assertSame(4, $parsed['data_start_row_number']);
            $this->assertSame(4, $rows[0]['source_row_number']);
            $this->assertSame(['Alice', 'alice@example.test'], $rows[0]['values']);
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_formula_cells_in_imported_rows_but_ignores_pre_header_notes(): void
    {
        $metadataFormulaPath = $this->createXlsxFile($this->minimalXlsxFiles(
            '<row r="1"><c r="A1"><f>SUM(1,1)</f><v>2</v></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row r="4"><c r="A4" t="inlineStr"><is><t>Alice</t></is></c></row>'
        ));
        $importFormulaPath = $this->createXlsxFile($this->minimalXlsxFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row r="2"><c r="A2"><f>CONCAT("Al","ice")</f><v>Alice</v></c></row>'
        ));

        try {
            $parsed = (new XlsxImportSource)->parse($metadataFormulaPath, null, 3);
            $this->assertSame(['name'], $parsed['headers']);
            $this->assertSame(['Alice'], $parsed['rows'][0]['values']);

            try {
                (new XlsxImportSource)->parse($importFormulaPath);
                $this->fail('Formula cells in imported rows must not be silently treated as values.');
            } catch (ValidationException $exception) {
                $this->assertSame(['見出し行または取込データに数式セルがあります。値に変換してから再度アップロードしてください。'], $exception->errors()['file']);
            }
        } finally {
            unlink($metadataFormulaPath);
            unlink($importFormulaPath);
        }
    }

    public function test_it_rejects_duplicate_headers_after_trimming(): void
    {
        $path = $this->createXlsxFile($this->minimalXlsxFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Employee name</t></is></c><c r="B1" t="inlineStr"><is><t> Employee name </t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>Alice</t></is></c><c r="B2" t="inlineStr"><is><t>Bob</t></is></c></row>'
        ));

        try {
            (new XlsxImportSource)->parse($path);
            $this->fail('Duplicate XLSX headers must be rejected after trimming.');
        } catch (ValidationException $exception) {
            $this->assertSame(['見出しに同じ名前、または複数の空欄があります。列名を一意にしてから再度アップロードしてください。'], $exception->errors()['file']);
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_merged_cells_in_imported_rows_but_ignores_pre_header_merges(): void
    {
        $metadataMergePath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Employee table</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>name</t></is></c><c r="B3" t="inlineStr"><is><t>email</t></is></c></row>'
            .'<row r="4"><c r="A4" t="inlineStr"><is><t>Alice</t></is></c><c r="B4" t="inlineStr"><is><t>alice@example.test</t></is></c></row>',
            '<mergeCell ref="A1:B1"/>',
        ));
        $duplicateHeaderMergePath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>email</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>Alice</t></is></c><c r="B2" t="inlineStr"><is><t>alice@example.test</t></is></c></row>',
            '<mergeCell ref="A1:B1"/>',
        ));
        $hierarchicalHeaderMergePath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Employee</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>name</t></is></c><c r="B2" t="inlineStr"><is><t>email</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>Alice</t></is></c><c r="B3" t="inlineStr"><is><t>alice@example.test</t></is></c></row>',
            '<mergeCell ref="A1:B1"/>',
        ));
        $crossingHeaderMergePath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Employee</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>Alice</t></is></c></row>',
            '<mergeCell ref="A1:A3"/>',
        ));
        $overlappingHeaderMergePath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Employee</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>name</t></is></c><c r="B2" t="inlineStr"><is><t>email</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>Alice</t></is></c><c r="B3" t="inlineStr"><is><t>alice@example.test</t></is></c></row>',
            '<mergeCell ref="A1:B1"/><mergeCell ref="B1:C1"/>',
        ));
        $dataMergePath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>email</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>Alice</t></is></c><c r="B2" t="inlineStr"><is><t>alice@example.test</t></is></c></row>',
            '<mergeCell ref="A2:B2"/>',
        ));

        try {
            $parsed = (new XlsxImportSource)->parse($metadataMergePath, null, 3);
            $this->assertSame(['name', 'email'], $parsed['headers']);
            $this->assertSame(['Alice', 'alice@example.test'], $parsed['rows'][0]['values']);

            $hierarchical = (new XlsxImportSource)->parseEach($hierarchicalHeaderMergePath, null, static function (array $row): void {}, headerRowNumber: 1, dataStartRowNumber: 3, headerEndRowNumber: 2);
            $this->assertSame(['Employee / name', 'Employee / email'], $hierarchical['headers']);

            try {
                (new XlsxImportSource)->parseEach($crossingHeaderMergePath, null, static function (array $row): void {}, headerRowNumber: 1, dataStartRowNumber: 3, headerEndRowNumber: 2);
                $this->fail('Merged ranges crossing from header to data must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(['見出し範囲をまたぐ結合セルがあります。結合を解除するか、範囲を見直してください。'], $exception->errors()['file']);
            }

            try {
                (new XlsxImportSource)->parseEach($overlappingHeaderMergePath, null, static function (array $row): void {}, headerRowNumber: 1, dataStartRowNumber: 3, headerEndRowNumber: 2);
                $this->fail('Overlapping merged ranges must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(['XLSXの結合セル範囲が重複しています。ファイル構造を確認してください。'], $exception->errors()['file']);
            }

            foreach ([
                $duplicateHeaderMergePath => '見出しに同じ名前、または複数の空欄があります。列名を一意にしてから再度アップロードしてください。',
                $dataMergePath => '見出し行または取込データに結合セルがあります。結合を解除してから再度アップロードしてください。',
            ] as $path => $expectedMessage) {
                try {
                    (new XlsxImportSource)->parse($path);
                    $this->fail('Merged cells in the selected import rows must be rejected.');
                } catch (ValidationException $exception) {
                    $this->assertSame([$expectedMessage], $exception->errors()['file']);
                }
            }
        } finally {
            unlink($metadataMergePath);
            unlink($duplicateHeaderMergePath);
            unlink($hierarchicalHeaderMergePath);
            unlink($crossingHeaderMergePath);
            unlink($overlappingHeaderMergePath);
            unlink($dataMergePath);
        }
    }

    public function test_it_rejects_hidden_rows_and_columns_with_data_in_the_import_range(): void
    {
        $hiddenMetadataPath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1" hidden="1"><c r="B1" t="inlineStr"><is><t>Internal note</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row r="4"><c r="A4" t="inlineStr"><is><t>Alice</t></is></c></row>',
            '',
            '<col min="2" max="2" hidden="1"/>',
        ));
        $hiddenRowPath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row r="2" hidden="true"><c r="A2" t="inlineStr"><is><t>Alice</t></is></c></row>',
            '',
        ));
        $hiddenColumnPath = $this->createXlsxFile($this->worksheetFiles(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>email</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>Alice</t></is></c><c r="B2" t="inlineStr"><is><t>alice@example.test</t></is></c></row>',
            '',
            '<col min="2" max="2" hidden="1"/>',
        ));

        try {
            $parsed = (new XlsxImportSource)->parse($hiddenMetadataPath, null, 3);
            $this->assertSame(['name'], $parsed['headers']);
            $this->assertSame(['Alice'], $parsed['rows'][0]['values']);

            foreach ([
                $hiddenRowPath => '見出し行または取込データに非表示の行があります。表示してから再度アップロードしてください。',
                $hiddenColumnPath => '見出し行または取込データに非表示の列があります。表示してから再度アップロードしてください。',
            ] as $path => $expectedMessage) {
                try {
                    (new XlsxImportSource)->parse($path);
                    $this->fail('Hidden row or column data must not be imported silently.');
                } catch (ValidationException $exception) {
                    $this->assertSame([$expectedMessage], $exception->errors()['file']);
                }
            }
        } finally {
            unlink($hiddenMetadataPath);
            unlink($hiddenRowPath);
            unlink($hiddenColumnPath);
        }
    }

    public function test_it_reports_the_actual_header_row_when_defaulting_to_the_first_populated_worksheet_row(): void
    {
        $worksheetRows = '<row r="1"></row><row r="2"></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>name</t></is></c></row>'
            .'<row r="4"><c r="A4" t="inlineStr"><is><t>Alice</t></is></c></row>';
        $path = $this->createXlsxFile($this->minimalXlsxFiles($worksheetRows));

        try {
            $parsed = (new XlsxImportSource)->parseEach($path, null, static function (array $row): void {});

            $this->assertSame(['name'], $parsed['headers']);
            $this->assertSame(3, $parsed['header_row_number']);
            $this->assertSame(4, $parsed['data_start_row_number']);
            $this->assertSame(1, $parsed['total_rows']);
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_malformed_worksheet_xml(): void
    {
        $files = $this->minimalXlsxFiles('');
        $files['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">';
        $path = $this->createXlsxFile($files);

        try {
            $this->expectException(\RuntimeException::class);
            (new XlsxImportSource)->parse($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_converts_dates_in_the_1904_system_and_rejects_the_fictitious_1900_leap_day(): void
    {
        $path = $this->createXlsxFile([
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="1"/><sheets><sheet name="Dates" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>',
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Date</t></is></c></row><row r="2"><c r="A2" s="1"><v>0</v></c></row></sheetData></worksheet>',
        ]);
        $invalid1900Path = $this->createXlsxFile([
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Dates" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>',
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Date</t></is></c></row><row r="2"><c r="A2" s="1"><v>60</v></c></row></sheetData></worksheet>',
        ]);

        try {
            $parsed = (new XlsxImportSource)->parse($path);
            $this->assertSame('1904-01-01', $parsed['rows'][0]['values'][0]);

            try {
                (new XlsxImportSource)->parse($invalid1900Path);
                $this->fail('The fictitious 1900-02-29 date must not be silently changed.');
            } catch (ValidationException $exception) {
                $this->assertSame(['XLSXに対応できない日付値があります。セルの日付を確認してください。'], $exception->errors()['file']);
            }
        } finally {
            unlink($path);
            unlink($invalid1900Path);
        }
    }

    public function test_it_rejects_xlsx_packages_and_worksheet_dimensions_over_configured_limits(): void
    {
        config(['opsdeck.import.xlsx_max_uncompressed_xml_kilobytes' => 1]);
        $oversizedPackagePath = $this->createXlsxFile($this->minimalXlsxFiles('<row r="1"><c r="A1" t="inlineStr"><is><t>Name</t></is></c></row>') + [
            'xl/custom.xml' => str_repeat('x', 2048),
        ]);

        try {
            try {
                (new XlsxImportSource)->sheets($oversizedPackagePath);
                $this->fail('Sheet discovery must enforce the expanded XML-size limit.');
            } catch (ValidationException $exception) {
                $this->assertSame(['XLSXのXML展開サイズまたは行・列数が上限を超えています。ファイルを分割して再度お試しください。'], $exception->errors()['file']);
            }

            (new XlsxImportSource)->parse($oversizedPackagePath);
            $this->fail('An XLSX package over the expanded-size limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['XLSXのXML展開サイズまたは行・列数が上限を超えています。ファイルを分割して再度お試しください。'], $exception->errors()['file']);
        } finally {
            unlink($oversizedPackagePath);
        }

        config(['opsdeck.import.xlsx_max_archive_entries' => 1]);
        $tooManyEntriesPath = $this->createXlsxFile($this->minimalXlsxFiles('<row r="1"><c r="A1" t="inlineStr"><is><t>Name</t></is></c></row>'));
        try {
            (new XlsxImportSource)->sheets($tooManyEntriesPath);
            $this->fail('A package over the configured ZIP-entry limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['XLSXのXML展開サイズまたは行・列数が上限を超えています。ファイルを分割して再度お試しください。'], $exception->errors()['file']);
        } finally {
            unlink($tooManyEntriesPath);
        }

        config([
            'opsdeck.import.xlsx_max_uncompressed_xml_kilobytes' => 16384,
            'opsdeck.import.xlsx_max_rows' => 2,
            'opsdeck.import.xlsx_max_columns' => 2,
        ]);
        $oversizedRowPath = $this->createXlsxFile($this->minimalXlsxFiles('<row r="3"><c r="A3" t="inlineStr"><is><t>Name</t></is></c></row>'));
        $oversizedColumnPath = $this->createXlsxFile($this->minimalXlsxFiles('<row r="1"><c r="C1" t="inlineStr"><is><t>Name</t></is></c></row>'));

        foreach ([$oversizedRowPath, $oversizedColumnPath] as $path) {
            try {
                (new XlsxImportSource)->parse($path);
                $this->fail('A worksheet outside its configured dimensions must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(['XLSXのXML展開サイズまたは行・列数が上限を超えています。ファイルを分割して再度お試しください。'], $exception->errors()['file']);
            } finally {
                unlink($path);
            }
        }

        config(['opsdeck.import.xlsx_max_cells' => 1]);
        $oversizedCellCountPath = $this->createXlsxFile($this->minimalXlsxFiles('<row r="1"><c r="A1" t="inlineStr"><is><t>Name</t></is></c><c r="B1" t="inlineStr"><is><t>Email</t></is></c></row>'));
        try {
            (new XlsxImportSource)->parse($oversizedCellCountPath);
            $this->fail('A worksheet over its configured cell limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['XLSXのXML展開サイズまたは行・列数が上限を超えています。ファイルを分割して再度お試しください。'], $exception->errors()['file']);
        } finally {
            unlink($oversizedCellCountPath);
        }

        $oversizedSharedStringsPath = $this->createXlsxFile($this->minimalXlsxFiles('<row r="1"><c r="A1" t="s"><v>0</v></c></row>') + [
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Name</t></si><si><t>Unused</t></si></sst>',
        ]);
        try {
            (new XlsxImportSource)->parse($oversizedSharedStringsPath);
            $this->fail('A shared-string table over the configured cell limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['XLSXのXML展開サイズまたは行・列数が上限を超えています。ファイルを分割して再度お試しください。'], $exception->errors()['file']);
        } finally {
            unlink($oversizedSharedStringsPath);
        }
    }

    /** @param array<string, string> $files */
    private function createXlsxFile(array $files): string
    {
        $temporaryDirectory = storage_path('framework/testing-xlsx');
        File::ensureDirectoryExists($temporaryDirectory);
        $path = tempnam($temporaryDirectory, 'opsdeck-xlsx-');
        $archive = new ZipArchive;
        $this->assertSame(true, $archive->open($path) === true);
        foreach ($files as $name => $contents) {
            $archive->addFromString($name, $contents);
        }
        $archive->close();

        return $path;
    }

    /** @return array<string, string> */
    private function minimalXlsxFiles(string $worksheetRow): array
    {
        return [
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$worksheetRow.'</sheetData></worksheet>',
        ];
    }

    /** @return array<string, string> */
    private function worksheetFiles(string $worksheetRows, string $mergedCells, string $columns = ''): array
    {
        $files = $this->minimalXlsxFiles($worksheetRows);
        $columnDefinitions = $columns === '' ? '' : '<cols>'.$columns.'</cols>';
        $mergedCellDefinitions = $mergedCells === '' ? '' : '<mergeCells>'.$mergedCells.'</mergeCells>';
        $files['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$columnDefinitions.'<sheetData>'.$worksheetRows.'</sheetData>'.$mergedCellDefinitions.'</worksheet>';

        return $files;
    }
}
