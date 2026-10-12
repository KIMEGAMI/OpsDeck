<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

class XlsxImportSource
{
    private const BUILT_IN_DATE_FORMAT_IDS = [14, 15, 16, 17, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    private const EXCEL_1900_EPOCH = '1899-12-31';

    private const EXCEL_1900_LEAP_DAY_SERIAL = 60;

    private const EXCEL_1900_DATE_OFFSET = 1;

    private const EXCEL_1900_MINIMUM_SERIAL = 1;

    private const EXCEL_1900_MAXIMUM_SERIAL = 2958465;

    private const EXCEL_1904_EPOCH = '1904-01-01';

    private const EXCEL_1904_MINIMUM_SERIAL = 0;

    private const EXCEL_1904_MAXIMUM_SERIAL = 2957003;

    /** @return array{headers: list<string>, rows: list<array{source_row_number: int, values: list<string>}>} */
    public function parse(string $path, ?string $sheetName = null, int $headerRowNumber = 1, ?int $dataStartRowNumber = null, ?int $headerEndRowNumber = null): array
    {
        $rows = [];
        $parsed = $this->parseEach($path, $sheetName, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        }, headerRowNumber: $headerRowNumber, dataStartRowNumber: $dataStartRowNumber, headerEndRowNumber: $headerEndRowNumber);

        return ['headers' => $parsed['headers'], 'rows' => $rows];
    }

    /**
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int, header_row_number: int, header_end_row_number: int, data_start_row_number: int}
     */
    public function parseEach(string $path, ?string $sheetName, callable $onRow, ?int $maximumDataRows = null, int $headerRowNumber = 1, ?int $dataStartRowNumber = null, ?int $headerEndRowNumber = null): array
    {
        $headerEndRowNumber ??= $headerRowNumber;
        if ($headerRowNumber < 1
            || $headerEndRowNumber < $headerRowNumber
            || $headerEndRowNumber > (int) config('opsdeck.import.max_header_row_number')) {
            throw ValidationException::withMessages(['file' => '見出し行番号が許可範囲外です。']);
        }
        $this->assertDataStartRowNumberAllowed($headerEndRowNumber, $dataStartRowNumber);
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new RuntimeException('XLSXファイルを開けません。');
        }
        try {
            $this->assertArchiveWithinLimits($archive);
            $sharedStrings = $this->sharedStrings($archive);
            $sheets = $this->sheetPaths($archive);
            $sheetName ??= array_key_first($sheets);
            abort_unless(is_string($sheetName) && isset($sheets[$sheetName]), 422, '指定したXLSXシートが見つかりません。');
            $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $worksheetStructure = $this->worksheetStructure($archive, $sheets[$sheetName], $namespace);
            $mergedRows = $worksheetStructure['merged_rows'];
            $mergedRanges = $worksheetStructure['merged_ranges'];
            $hiddenColumns = $worksheetStructure['hidden_columns'];
            $dateStyles = $this->dateStyles($archive);
            $uses1904DateSystem = $this->uses1904DateSystem($archive);
            $headers = [];
            $headerRows = [];
            $resolvedHeaderRowNumber = null;
            $resolvedHeaderEndRowNumber = null;
            $mergedHeaderValues = [];
            $totalRows = 0;
            $maximumImportRows = max(1, (int) config('opsdeck.import.max_rows'));
            $cellCount = 0;
            $worksheetRowCount = 0;
            $this->withArchiveEntryReader($archive, $sheets[$sheetName], function (XMLReader $reader) use (&$headers, &$headerRows, &$resolvedHeaderRowNumber, &$resolvedHeaderEndRowNumber, &$mergedHeaderValues, &$totalRows, &$cellCount, &$worksheetRowCount, $namespace, $sharedStrings, $dateStyles, $uses1904DateSystem, $onRow, $maximumDataRows, $maximumImportRows, $headerRowNumber, $headerEndRowNumber, $dataStartRowNumber, $mergedRows, $mergedRanges, $hiddenColumns): void {
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row' || $reader->namespaceURI !== $namespace) {
                        continue;
                    }

                    $worksheetRowCount++;
                    $rowNumber = $reader->getAttribute('r');
                    $sourceRowNumber = $rowNumber === null ? $worksheetRowCount : (int) $rowNumber;
                    $isHiddenRow = filter_var($reader->getAttribute('hidden') ?? false, FILTER_VALIDATE_BOOL);
                    $this->assertWorksheetRowWithinLimit($sourceRowNumber);
                    if ($worksheetRowCount > (int) config('opsdeck.import.xlsx_max_rows')) {
                        $this->rejectOversizedWorksheet();
                    }

                    $containsFormula = false;
                    $values = $this->readWorksheetRow($reader, $namespace, $sharedStrings, $dateStyles, $uses1904DateSystem, $cellCount, $containsFormula);
                    ksort($values);
                    $rowValues = $values === [] ? [] : array_fill(0, (int) array_key_last($values) + 1, '');
                    foreach ($values as $column => $value) {
                        $rowValues[$column] = $value;
                    }

                    $autoDetectFirstHeader = $headerRowNumber === 1 && $headerEndRowNumber === 1 && $headers === [];
                    if (($sourceRowNumber >= $headerRowNumber && $sourceRowNumber <= $headerEndRowNumber) || $autoDetectFirstHeader) {
                        if ($autoDetectFirstHeader && count(array_filter($rowValues, static fn (string $value): bool => trim($value) !== '')) === 0) {
                            continue;
                        }
                        $this->rejectFormulaRow($containsFormula);
                        $this->rejectHiddenRow($isHiddenRow);
                        $effectiveHeaderStart = $autoDetectFirstHeader ? $sourceRowNumber : $headerRowNumber;
                        $effectiveHeaderEnd = $autoDetectFirstHeader ? $sourceRowNumber : $headerEndRowNumber;
                        $rowValues = $this->expandMergedHeaderValues(
                            $sourceRowNumber,
                            $rowValues,
                            $mergedRanges,
                            $effectiveHeaderStart,
                            $effectiveHeaderEnd,
                            $mergedHeaderValues,
                        );
                        $this->rejectHiddenColumns($rowValues, $hiddenColumns);
                        $headerRows[] = array_map(static fn (string $header): string => trim($header), $rowValues);
                        if ($resolvedHeaderRowNumber === null) {
                            $resolvedHeaderRowNumber = $sourceRowNumber;
                        }
                        if ($autoDetectFirstHeader || $sourceRowNumber === $headerEndRowNumber) {
                            $resolvedHeaderEndRowNumber = $sourceRowNumber;
                            $headerValidator = app(ImportHeaderValidator::class);
                            $headers = $headerValidator->composeRows($headerRows);
                            $headerValidator->assertUnique($headers);
                        }

                        continue;
                    }
                    if ($sourceRowNumber < $headerRowNumber) {
                        continue;
                    }
                    if ($headers === []) {
                        throw ValidationException::withMessages(['file' => '指定した行にXLSXヘッダーがありません。行番号を確認してください。']);
                    }
                    $minimumDataRowNumber = max(
                        $dataStartRowNumber ?? (($resolvedHeaderEndRowNumber ?? $headerEndRowNumber) + 1),
                        ($resolvedHeaderEndRowNumber ?? $headerEndRowNumber) + 1,
                    );
                    if ($sourceRowNumber < $minimumDataRowNumber) {
                        continue;
                    }

                    $this->rejectFormulaRow($containsFormula);
                    $this->rejectMergedRow($sourceRowNumber, $mergedRows);
                    if (count(array_filter($rowValues, static fn (string $value): bool => trim($value) !== '')) !== 0) {
                        if ($totalRows >= $maximumImportRows) {
                            throw ValidationException::withMessages([
                                'file' => "XLSXの取込上限（{$maximumImportRows}行）を超えています。ファイルを分割して再度お試しください。",
                            ]);
                        }
                        $this->rejectHiddenRow($isHiddenRow);
                        $this->rejectHiddenColumns($values, $hiddenColumns);
                        $onRow(['source_row_number' => $sourceRowNumber, 'values' => $rowValues], $headers);
                        $totalRows++;
                        if ($maximumDataRows !== null && $totalRows >= max(1, $maximumDataRows)) {
                            break;
                        }
                    }
                }
            }, 'XLSXのシートを解析できません。');

            if ($headers === [] && $headerRows !== [] && $resolvedHeaderRowNumber !== null) {
                if ($headerRowNumber !== 1 || $headerEndRowNumber !== 1) {
                    throw ValidationException::withMessages(['file' => '指定した見出し範囲がシート内にありません。行番号を確認してください。']);
                }
                $resolvedHeaderEndRowNumber = $resolvedHeaderRowNumber;
                $headerValidator = app(ImportHeaderValidator::class);
                $headers = $headerValidator->composeRows($headerRows);
                $headerValidator->assertUnique($headers);
            }
            if ($headers === []) {
                throw ValidationException::withMessages(['file' => '指定した行にXLSXヘッダーがありません。行番号を確認してください。']);
            }

            $resolvedHeader = $resolvedHeaderRowNumber ?? $headerRowNumber;
            $resolvedHeaderEnd = $resolvedHeaderEndRowNumber ?? $resolvedHeader;

            return [
                'headers' => $headers,
                'total_rows' => $totalRows,
                'header_row_number' => $resolvedHeader,
                'header_end_row_number' => $resolvedHeaderEnd,
                'data_start_row_number' => max($dataStartRowNumber ?? $resolvedHeaderEnd + 1, $resolvedHeaderEnd + 1),
            ];
        } finally {
            $archive->close();
        }
    }

    /** @return list<string> */
    public function sheets(string $path): array
    {
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new RuntimeException('XLSXファイルを開けません。');
        }
        try {
            $this->assertArchiveWithinLimits($archive);

            return array_keys($this->sheetPaths($archive));
        } finally {
            $archive->close();
        }
    }

    private function assertArchiveWithinLimits(ZipArchive $archive): void
    {
        $maximumEntries = (int) config('opsdeck.import.xlsx_max_archive_entries');
        $maximumUncompressedXmlBytes = (int) config('opsdeck.import.xlsx_max_uncompressed_xml_kilobytes') * 1024;
        if ($archive->numFiles > $maximumEntries) {
            $this->rejectOversizedWorksheet();
        }

        $uncompressedBytes = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);
            if (! is_array($entry) || ! isset($entry['size']) || ! is_int($entry['size']) || $entry['size'] < 0) {
                throw new RuntimeException('XLSXのファイル情報を確認できません。');
            }
            $entryName = $entry['name'] ?? '';
            if (! is_string($entryName) || (! str_ends_with(strtolower($entryName), '.xml') && ! str_ends_with(strtolower($entryName), '.rels'))) {
                continue;
            }
            if ($entry['size'] > $maximumUncompressedXmlBytes - $uncompressedBytes) {
                $this->rejectOversizedWorksheet();
            }
            $uncompressedBytes += $entry['size'];
        }
    }

    private function assertWorksheetRowWithinLimit(int $rowNumber): void
    {
        if ($rowNumber < 1 || $rowNumber > (int) config('opsdeck.import.xlsx_max_rows')) {
            $this->rejectOversizedWorksheet();
        }
    }

    private function assertDataStartRowNumberAllowed(int $headerEndRowNumber, ?int $dataStartRowNumber): void
    {
        $maximumSourceRowNumber = min(
            (int) config('opsdeck.import.max_source_row_number'),
            (int) config('opsdeck.import.xlsx_max_rows'),
        );
        if ($dataStartRowNumber !== null
            && ($dataStartRowNumber <= $headerEndRowNumber || $dataStartRowNumber > $maximumSourceRowNumber)) {
            throw ValidationException::withMessages(['file' => 'データ開始行番号は見出し範囲の終了行より後、かつ許可範囲内で指定してください。']);
        }
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @param  array<int, bool>  $dateStyles
     * @return array<int, string>
     */
    private function readWorksheetRow(XMLReader $reader, string $namespace, array $sharedStrings, array $dateStyles, bool $uses1904DateSystem, int &$cellCount, bool &$containsFormula): array
    {
        $rowDepth = $reader->depth;
        $values = [];
        if ($reader->isEmptyElement) {
            return $values;
        }

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row' && $reader->depth === $rowDepth) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c' || $reader->namespaceURI !== $namespace) {
                continue;
            }

            $cellCount++;
            if ($cellCount > (int) config('opsdeck.import.xlsx_max_cells')) {
                $this->rejectOversizedWorksheet();
            }

            $reference = $reader->getAttribute('r') ?? '';
            $column = $this->columnIndex($reference);
            $cellType = $reader->getAttribute('t') ?? '';
            $styleIndex = (int) ($reader->getAttribute('s') ?? 0);
            $value = $this->readWorksheetCellValue($reader, $cellType, $namespace, $containsFormula);
            if ($cellType === 's') {
                $value = $sharedStrings[(int) $value] ?? '';
            }
            if (($dateStyles[$styleIndex] ?? false) && is_numeric($value)) {
                $value = $this->excelDateToIsoDate((float) $value, $uses1904DateSystem);
            }
            $values[$column] = $value;
        }

        return $values;
    }

    private function readWorksheetCellValue(XMLReader $reader, string $cellType, string $namespace, bool &$containsFormula): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $cellDepth = $reader->depth;
        $value = '';
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $reader->depth === $cellDepth) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->namespaceURI !== $namespace) {
                continue;
            }
            if ($reader->localName === 'f') {
                $containsFormula = true;
            }
            if (($cellType === 'inlineStr' && $reader->localName === 't') || ($cellType !== 'inlineStr' && $reader->localName === 'v')) {
                $value .= $reader->readString();
            }
        }

        return $value;
    }

    private function rejectFormulaRow(bool $containsFormula): void
    {
        if ($containsFormula) {
            throw ValidationException::withMessages(['file' => '見出し行または取込データに数式セルがあります。値に変換してから再度アップロードしてください。']);
        }
    }

    /** @param array<int, true> $mergedRows */
    private function rejectMergedRow(int $sourceRowNumber, array $mergedRows): void
    {
        if (isset($mergedRows[$sourceRowNumber])) {
            throw ValidationException::withMessages(['file' => '見出し行または取込データに結合セルがあります。結合を解除してから再度アップロードしてください。']);
        }
    }

    private function rejectHiddenRow(bool $isHiddenRow): void
    {
        if ($isHiddenRow) {
            throw ValidationException::withMessages(['file' => '見出し行または取込データに非表示の行があります。表示してから再度アップロードしてください。']);
        }
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<int, true>  $hiddenColumns
     */
    private function rejectHiddenColumns(array $values, array $hiddenColumns): void
    {
        foreach ($values as $column => $value) {
            if (isset($hiddenColumns[$column]) && trim($value) !== '') {
                throw ValidationException::withMessages(['file' => '見出し行または取込データに非表示の列があります。表示してから再度アップロードしてください。']);
            }
        }
    }

    /**
     * @return array{merged_rows: array<int, true>, merged_ranges: list<array{start_row: int, end_row: int, start_column: int, end_column: int}>, hidden_columns: array<int, true>}
     */
    private function worksheetStructure(ZipArchive $archive, string $entryName, string $namespace): array
    {
        $mergedRows = [];
        $mergedRanges = [];
        $mergedCellCoordinates = [];
        $hiddenColumns = [];
        $maximumMergedItems = max(1, (int) config('opsdeck.import.xlsx_max_cells'));
        $maximumColumns = max(1, (int) config('opsdeck.import.xlsx_max_columns'));
        $mergedRangeCount = 0;
        $mergedRowReferenceCount = 0;
        $hiddenColumnDefinitionCount = 0;
        $hiddenColumnReferenceCount = 0;
        $mergedCellReferenceCount = 0;
        $this->withArchiveEntryReader($archive, $entryName, function (XMLReader $reader) use (&$mergedRows, &$mergedRanges, &$mergedCellCoordinates, &$hiddenColumns, &$mergedRangeCount, &$mergedRowReferenceCount, &$mergedCellReferenceCount, &$hiddenColumnDefinitionCount, &$hiddenColumnReferenceCount, $maximumMergedItems, $maximumColumns, $namespace): void {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->namespaceURI !== $namespace) {
                    continue;
                }

                if ($reader->localName === 'mergeCell') {
                    $reference = $reader->getAttribute('ref') ?? '';
                    if (preg_match('/^([A-Z]+)([1-9][0-9]*):([A-Z]+)([1-9][0-9]*)$/i', $reference, $matches) !== 1) {
                        throw new RuntimeException('XLSXの結合セル情報が不正です。');
                    }
                    $mergedRangeCount++;
                    if ($mergedRangeCount > $maximumMergedItems) {
                        $this->rejectOversizedWorksheet();
                    }

                    $firstRow = (int) $matches[2];
                    $lastRow = (int) $matches[4];
                    $firstColumn = $this->columnIndexFromLetters($matches[1]);
                    $lastColumn = $this->columnIndexFromLetters($matches[3]);
                    if ($firstRow > $lastRow || $firstColumn > $lastColumn) {
                        throw new RuntimeException('XLSXの結合セル情報が不正です。');
                    }
                    $this->assertWorksheetRowWithinLimit($firstRow);
                    $this->assertWorksheetRowWithinLimit($lastRow);
                    $mergedCellCount = ($lastRow - $firstRow + 1) * ($lastColumn - $firstColumn + 1);
                    if ($mergedCellCount > $maximumMergedItems - $mergedCellReferenceCount) {
                        $this->rejectOversizedWorksheet();
                    }
                    $mergedCellReferenceCount += $mergedCellCount;
                    $mergedRanges[] = [
                        'start_row' => $firstRow,
                        'end_row' => $lastRow,
                        'start_column' => $firstColumn,
                        'end_column' => $lastColumn,
                    ];

                    for ($rowNumber = $firstRow; $rowNumber <= $lastRow; $rowNumber++) {
                        $mergedRowReferenceCount++;
                        if ($mergedRowReferenceCount > $maximumMergedItems) {
                            $this->rejectOversizedWorksheet();
                        }
                        $mergedRows[$rowNumber] = true;
                        for ($column = $firstColumn; $column <= $lastColumn; $column++) {
                            $coordinate = $rowNumber.':'.$column;
                            if (isset($mergedCellCoordinates[$coordinate])) {
                                throw ValidationException::withMessages(['file' => 'XLSXの結合セル範囲が重複しています。ファイル構造を確認してください。']);
                            }
                            $mergedCellCoordinates[$coordinate] = true;
                        }
                    }

                    continue;
                }

                if ($reader->localName !== 'col' || ! filter_var($reader->getAttribute('hidden') ?? false, FILTER_VALIDATE_BOOL)) {
                    continue;
                }
                $hiddenColumnDefinitionCount++;
                if ($hiddenColumnDefinitionCount > $maximumMergedItems) {
                    $this->rejectOversizedWorksheet();
                }

                $firstColumn = filter_var($reader->getAttribute('min'), FILTER_VALIDATE_INT);
                $lastColumn = filter_var($reader->getAttribute('max'), FILTER_VALIDATE_INT);
                if (! is_int($firstColumn) || ! is_int($lastColumn) || $firstColumn < 1 || $lastColumn < $firstColumn) {
                    throw new RuntimeException('XLSXの非表示列情報が不正です。');
                }
                if ($firstColumn > $maximumColumns) {
                    continue;
                }

                for ($column = $firstColumn; $column <= min($lastColumn, $maximumColumns); $column++) {
                    $hiddenColumnReferenceCount++;
                    if ($hiddenColumnReferenceCount > $maximumMergedItems) {
                        $this->rejectOversizedWorksheet();
                    }
                    $hiddenColumns[$column - 1] = true;
                    if (count($hiddenColumns) > $maximumColumns) {
                        $this->rejectOversizedWorksheet();
                    }
                }
            }
        }, 'XLSXのシート構造を解析できません。');

        return ['merged_rows' => $mergedRows, 'merged_ranges' => $mergedRanges, 'hidden_columns' => $hiddenColumns];
    }

    /**
     * @param  array<int, string>  $rowValues
     * @param  list<array{start_row: int, end_row: int, start_column: int, end_column: int}>  $mergedRanges
     * @param  array<int, string>  $mergedHeaderValues
     * @return array<int, string>
     */
    private function expandMergedHeaderValues(
        int $sourceRowNumber,
        array $rowValues,
        array $mergedRanges,
        int $headerStartRowNumber,
        int $headerEndRowNumber,
        array &$mergedHeaderValues,
    ): array {
        foreach ($mergedRanges as $rangeIndex => $range) {
            if ($sourceRowNumber < $range['start_row'] || $sourceRowNumber > $range['end_row']) {
                continue;
            }
            if ($range['start_row'] < $headerStartRowNumber || $range['end_row'] > $headerEndRowNumber) {
                throw ValidationException::withMessages(['file' => '見出し範囲をまたぐ結合セルがあります。結合を解除するか、範囲を見直してください。']);
            }

            if ($sourceRowNumber === $range['start_row']) {
                $mergedHeaderValues[$rangeIndex] = $rowValues[$range['start_column']] ?? '';
            }
            $value = $mergedHeaderValues[$rangeIndex] ?? '';
            for ($columnIndex = $range['start_column']; $columnIndex <= $range['end_column']; $columnIndex++) {
                $rowValues[$columnIndex] = $value;
            }
        }

        return $rowValues;
    }

    /**
     * @param  callable(XMLReader): mixed  $callback
     */
    private function withArchiveEntryReader(ZipArchive $archive, string $entryName, callable $callback, string $errorMessage): mixed
    {
        $stream = $archive->getStream($entryName);
        if (! is_resource($stream)) {
            throw new RuntimeException($errorMessage);
        }

        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = null;
        try {
            if (method_exists(XMLReader::class, 'fromStream')) {
                $reader = XMLReader::fromStream($stream, null, LIBXML_NONET);
            } else {
                $contents = stream_get_contents($stream);
                if (! is_string($contents)) {
                    throw new RuntimeException($errorMessage);
                }

                $reader = new XMLReader;
                if (! $reader->XML($contents, null, LIBXML_NONET)) {
                    throw new RuntimeException($errorMessage);
                }
            }
            $result = $callback($reader);
            if (libxml_get_errors() !== []) {
                throw new RuntimeException($errorMessage);
            }

            return $result;
        } finally {
            $reader?->close();
            fclose($stream);
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    private function rejectOversizedWorksheet(): never
    {
        throw ValidationException::withMessages(['file' => 'XLSXのXML展開サイズまたは行・列数が上限を超えています。ファイルを分割して再度お試しください。']);
    }

    /** @return array<string, string> */
    private function sheetPaths(ZipArchive $archive): array
    {
        $workbookContents = $archive->getFromName('xl/workbook.xml');
        $relationshipsContents = $archive->getFromName('xl/_rels/workbook.xml.rels');
        $workbook = $workbookContents === false ? false : simplexml_load_string($workbookContents);
        $relationships = $relationshipsContents === false ? false : simplexml_load_string($relationshipsContents);
        if (! $workbook instanceof SimpleXMLElement || ! $relationships instanceof SimpleXMLElement) {
            throw new RuntimeException('XLSXのシート情報を解析できません。');
        }

        $relationshipNamespace = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $relationshipTargets = [];
        foreach ($relationships->children($relationshipNamespace)->Relationship as $relationship) {
            $attributes = $relationship->attributes();
            if ((string) ($attributes['TargetMode'] ?? '') === 'External') {
                continue;
            }
            $relationshipTargets[(string) ($attributes['Id'] ?? '')] = (string) ($attributes['Target'] ?? '');
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $officeRelationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheets = [];
        foreach ($workbook->children($namespace)->sheets->children($namespace)->sheet as $sheet) {
            $sheetAttributes = $sheet->attributes();
            $relationshipAttributes = $sheet->attributes($officeRelationshipNamespace);
            $name = (string) ($sheetAttributes['name'] ?? '');
            $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
            $target = $relationshipTargets[$relationshipId] ?? '';
            $target = ltrim($target, '/');
            if (! str_starts_with($target, 'xl/')) {
                $target = 'xl/'.$target;
            }
            if ($name === '' || $target === 'xl/' || ! str_starts_with($target, 'xl/worksheets/') || ! str_ends_with($target, '.xml')) {
                throw new RuntimeException('XLSXのシート参照が不正です。');
            }
            $sheets[$name] = $target;
        }

        if ($sheets === []) {
            throw new RuntimeException('XLSXにシートがありません。');
        }

        return $sheets;
    }

    /** @return array<int, bool> */
    private function dateStyles(ZipArchive $archive): array
    {
        $contents = $archive->getFromName('xl/styles.xml');
        if ($contents === false) {
            return [];
        }
        $xml = simplexml_load_string($contents);
        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('XLSXのセル書式を解析できません。');
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xml->registerXPathNamespace('x', $namespace);
        $customFormats = [];
        foreach ($xml->xpath('/x:styleSheet/x:numFmts/x:numFmt') ?: [] as $numberFormat) {
            $attributes = $numberFormat->attributes();
            $customFormats[(int) ($attributes['numFmtId'] ?? 0)] = (string) ($attributes['formatCode'] ?? '');
        }

        $styles = [];
        foreach ($xml->xpath('/x:styleSheet/x:cellXfs/x:xf') ?: [] as $index => $style) {
            $numberFormatId = (int) ($style->attributes()['numFmtId'] ?? 0);
            $styles[$index] = $this->isDateNumberFormat($numberFormatId, $customFormats[$numberFormatId] ?? null);
        }

        return $styles;
    }

    /** @param array<int, string> $customFormats */
    private function isDateNumberFormat(int $numberFormatId, ?string $customFormat): bool
    {
        if (in_array($numberFormatId, self::BUILT_IN_DATE_FORMAT_IDS, true)) {
            return true;
        }
        if ($customFormat === null) {
            return false;
        }

        $format = preg_replace('/"[^"]*"|\\\\.|_.?/u', '', $customFormat) ?? $customFormat;
        $format = preg_replace('/\\[[^\\]]*\\]/u', '', $format) ?? $format;

        return preg_match('/[yd]/i', $format) === 1;
    }

    private function uses1904DateSystem(ZipArchive $archive): bool
    {
        $contents = $archive->getFromName('xl/workbook.xml');
        $xml = $contents === false ? false : simplexml_load_string($contents);
        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('XLSXのブック設定を解析できません。');
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $date1904 = $xml->children($namespace)->workbookPr->attributes()['date1904'] ?? '0';

        return in_array(strtolower((string) $date1904), ['1', 'true'], true);
    }

    private function excelDateToIsoDate(float $serial, bool $uses1904DateSystem): string
    {
        $minimumSerial = $uses1904DateSystem ? self::EXCEL_1904_MINIMUM_SERIAL : self::EXCEL_1900_MINIMUM_SERIAL;
        $maximumSerial = $uses1904DateSystem ? self::EXCEL_1904_MAXIMUM_SERIAL : self::EXCEL_1900_MAXIMUM_SERIAL;
        if (! is_finite($serial) || $serial > $maximumSerial || $serial < $minimumSerial) {
            throw ValidationException::withMessages(['file' => 'XLSXに対応できない日付値があります。セルの日付を確認してください。']);
        }

        $days = (int) floor($serial);
        if (! $uses1904DateSystem && ($days === self::EXCEL_1900_LEAP_DAY_SERIAL || $days > $maximumSerial)) {
            throw ValidationException::withMessages(['file' => 'XLSXに対応できない日付値があります。セルの日付を確認してください。']);
        }
        if (! $uses1904DateSystem && $days > self::EXCEL_1900_LEAP_DAY_SERIAL) {
            $days -= self::EXCEL_1900_DATE_OFFSET;
        }
        $baseDate = $uses1904DateSystem ? self::EXCEL_1904_EPOCH : self::EXCEL_1900_EPOCH;

        return CarbonImmutable::parse($baseDate)->addDays($days)->toDateString();
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $archive): array
    {
        if ($archive->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        return $this->withArchiveEntryReader($archive, 'xl/sharedStrings.xml', function (XMLReader $reader) use ($namespace): array {
            $values = [];
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si' || $reader->namespaceURI !== $namespace) {
                    continue;
                }

                $itemDepth = $reader->depth;
                $text = '';
                if (! $reader->isEmptyElement) {
                    while ($reader->read()) {
                        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si' && $reader->depth === $itemDepth) {
                            break;
                        }
                        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $reader->namespaceURI === $namespace) {
                            $text .= $reader->readString();
                        }
                    }
                }
                $values[] = $text;
            }

            return $values;
        }, 'XLSXの共有文字列を解析できません。');
    }

    private function columnIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]+)([1-9][0-9]*)$/iD', $reference, $matches) !== 1) {
            $this->rejectOversizedWorksheet();
        }

        $this->assertWorksheetRowWithinLimit((int) $matches[2]);

        return $this->columnIndexFromLetters($matches[1]);
    }

    private function columnIndexFromLetters(string $columnLetters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($columnLetters)) as $letter) {
            $index = ($index * 26) + ord($letter) - 64;
            if ($index > (int) config('opsdeck.import.xlsx_max_columns')) {
                $this->rejectOversizedWorksheet();
            }
        }

        return $index - 1;
    }
}
