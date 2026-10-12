<?php

namespace App\Services\Import;

use Illuminate\Validation\ValidationException;

class ImportHeaderValidator
{
    /**
     * @param  list<list<string>>  $headerRows
     * @return list<string>
     */
    public function composeRows(array $headerRows): array
    {
        $columnCount = max(array_map('count', $headerRows));
        $headers = [];
        for ($columnIndex = 0; $columnIndex < $columnCount; $columnIndex++) {
            $parts = [];
            foreach ($headerRows as $headerRow) {
                $part = trim($headerRow[$columnIndex] ?? '');
                if ($part !== '' && ($parts === [] || end($parts) !== $part)) {
                    $parts[] = $part;
                }
            }
            $headers[] = implode(' / ', $parts);
        }

        return $headers;
    }

    /** @param list<string> $headers */
    public function assertUnique(array $headers): void
    {
        $seenHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeader = mb_strtolower(trim($header));
            if (array_key_exists($normalizedHeader, $seenHeaders)) {
                throw ValidationException::withMessages([
                    'file' => '見出しに同じ名前、または複数の空欄があります。列名を一意にしてから再度アップロードしてください。',
                ]);
            }

            $seenHeaders[$normalizedHeader] = true;
        }
    }
}
