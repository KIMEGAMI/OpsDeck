<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;

class ImportNormalizationSuggestionService
{
    /** @return list<array{field: string, raw_preview: string, normalized: string, reason: string}> */
    public function suggest(array $headers, array $rows, array $columnMap): array
    {
        $suggestions = [];
        foreach ($rows as $row) {
            foreach ($columnMap as $sourceHeader => $targetField) {
                if (! is_string($targetField) || $targetField === '' || ! isset($row[$sourceHeader])) {
                    continue;
                }
                $raw = trim((string) $row[$sourceHeader]);
                if ($raw === '') {
                    continue;
                }
                [$normalized, $reason] = $this->normalize($targetField, $raw);
                if ($normalized === $raw || $reason === null) {
                    continue;
                }
                $preview = in_array($targetField, ['email', 'employee_email'], true) ? '***' : mb_substr($raw, 0, 80);
                $key = $targetField.'|'.$raw.'|'.$normalized;
                $suggestions[$key] = ['field' => $targetField, 'raw_preview' => $preview, 'normalized' => $normalized, 'reason' => $reason];
                if (count($suggestions) >= 20) {
                    break 2;
                }
            }
        }

        return array_values($suggestions);
    }

    /** @return array{0: string, 1: string|null} */
    private function normalize(string $field, string $value): array
    {
        if ($field === 'amount' || $field === 'purchase_cost') {
            $normalized = str_replace([',', ' ', '¥', '円'], '', $value);

            return [$normalized, $normalized !== $value ? '金額記号・区切りを除去' : null];
        }
        if (in_array($field, ['auto_renew', 'is_active'], true)) {
            $normalized = match (mb_strtolower($value)) {
                '1', 'true', 'yes', 'y', '有', 'はい' => 'true',
                '0', 'false', 'no', 'n', '無', 'いいえ' => 'false',
                default => $value,
            };

            return [$normalized, $normalized !== $value ? '真偽値へ変換' : null];
        }
        if (in_array($field, ['joined_at', 'left_at', 'purchased_at', 'warranty_until', 'started_at', 'ends_at', 'cancellation_deadline', 'renewal_date', 'valid_from', 'valid_until'], true)) {
            try {
                $normalized = CarbonImmutable::parse($value)->toDateString();

                return [$normalized, $normalized !== $value ? 'ISO日付へ変換' : null];
            } catch (\Throwable) {
                return [$value, null];
            }
        }

        return [$value, null];
    }
}
