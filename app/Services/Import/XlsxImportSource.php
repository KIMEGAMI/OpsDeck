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
    public function parse(string $path, ?string $sheetName = null): array
    {
        $rows = [];
        $parsed = $this->parseEach($path, $sheetName, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return ['headers' => $parsed['headers'], 'rows' => $rows];
    }

    /**
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     * @return array{headers: list<string>, total_rows: int}
     */
    public function parseEach(string $path, ?string $sheetName, callable $onRow, ?int $maximumDataRows = null): array
    {
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
            $dateStyles = $this->dateStyles($archive);
            $uses1904DateSystem = $this->uses1904DateSystem($archive);
            $headers = [];
            $totalRows = 0;
            $maximumImportRows = max(1, (int) config('opsdeck.import.max_rows'));
            $cellCount = 0;
            $worksheetRowCount = 0;
            $this->withArchiveEntryReader($archive, $sheets[$sheetName], function (XMLReader $reader) use (&$headers, &$totalRows, &$cellCount, &$worksheetRowCount, $namespace, $sharedStrings, $dateStyles, $uses1904DateSystem, $onRow, $maximumDataRows, $maximumImportRows): void {
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row' || $reader->namespaceURI !== $namespace) {
                        continue;
                    }

                    $worksheetRowCount++;
                    $rowNumber = $reader->getAttribute('r');
                    $sourceRowNumber = $rowNumber === null ? $worksheetRowCount : (int) $rowNumber;
                    $this->assertWorksheetRowWithinLimit($sourceRowNumber);
                    if ($worksheetRowCount > (int) config('opsdeck.import.xlsx_max_rows')) {
                        $this->rejectOversizedWorksheet();
                    }

                    $values = $this->readWorksheetRow($reader, $namespace, $sharedStrings, $dateStyles, $uses1904DateSystem, $cellCount);
                    ksort($values);
                    $rowValues = $values === [] ? [] : array_fill(0, (int) array_key_last($values) + 1, '');
                    foreach ($values as $column => $value) {
                        $rowValues[$column] = $value;
                    }

                    if ($worksheetRowCount === 1) {
                        $headers = array_map(static fn (string $header): string => trim($header), $rowValues);

                        continue;
                    }

                    if (count(array_filter($rowValues, static fn (string $value): bool => trim($value) !== '')) !== 0) {
                        if ($totalRows >= $maximumImportRows) {
                            throw ValidationException::withMessages([
                                'file' => "XLSXの取込上限（{$maximumImportRows}行）を超えています。ファイルを分割して再度お試しください。",
                            ]);
                        }
                        $onRow(['source_row_number' => $sourceRowNumber, 'values' => $rowValues], $headers);
                        $totalRows++;
                        if ($maximumDataRows !== null && $totalRows >= max(1, $maximumDataRows)) {
                            break;
                        }
                    }
                }
            }, 'XLSXのシートを解析できません。');

            return ['headers' => $headers, 'total_rows' => $totalRows];
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

    /**
     * @param  array<int, string>  $sharedStrings
     * @param  array<int, bool>  $dateStyles
     * @return array<int, string>
     */
    private function readWorksheetRow(XMLReader $reader, string $namespace, array $sharedStrings, array $dateStyles, bool $uses1904DateSystem, int &$cellCount): array
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
            $value = $this->readWorksheetCellValue($reader, $cellType, $namespace);
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

    private function readWorksheetCellValue(XMLReader $reader, string $cellType, string $namespace): string
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
            if (($cellType === 'inlineStr' && $reader->localName === 't') || ($cellType !== 'inlineStr' && $reader->localName === 'v')) {
                $value .= $reader->readString();
            }
        }

        return $value;
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
        $index = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $index = ($index * 26) + ord($letter) - 64;
            if ($index > (int) config('opsdeck.import.xlsx_max_columns')) {
                $this->rejectOversizedWorksheet();
            }
        }

        return $index - 1;
    }
}
