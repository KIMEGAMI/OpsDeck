<?php

namespace App\Services\Import;

use Illuminate\Validation\ValidationException;

class ImportNormalizationSuggestionService
{
    /** @return list<array{field: string, rule: string, raw_preview: string, normalized: string, reason: string}> */
    public function suggest(string $targetType, array $headers, array $rows, array $columnMap): array
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
                $rule = $this->configuredRule($targetType, $targetField, $columnMap);
                if ($rule === null) {
                    continue;
                }
                [$normalized, $reason] = $this->normalize($rule, $raw);
                if ($normalized === $raw || $reason === null) {
                    continue;
                }
                $preview = in_array($targetField, ['email', 'employee_email'], true) ? '***' : mb_substr($raw, 0, 80);
                $key = $targetField.'|'.$raw.'|'.$normalized;
                $suggestions[$key] = ['field' => $targetField, 'rule' => $rule, 'raw_preview' => $preview, 'normalized' => $normalized, 'reason' => $reason];
                if (count($suggestions) >= 20) {
                    break 2;
                }
            }
        }

        return array_values($suggestions);
    }

    /** @param array<string, mixed> $columnMap
     * @return array<string, string>
     */
    public function validateRules(string $targetType, array $columnMap, mixed $rules): array
    {
        if ($rules === null) {
            return [];
        }
        if (! is_array($rules)) {
            throw ValidationException::withMessages(['normalization_rules' => '正規化ルールの形式が正しくありません。']);
        }

        $allowedRules = config('opsdeck.import_normalization_rules.'.$targetType, []);
        $validatedRules = [];
        foreach ($rules as $field => $rule) {
            if (! is_string($field) || ! is_string($rule) || ! is_string($columnMap[$field] ?? null) || $columnMap[$field] === '' || ($allowedRules[$field] ?? null) !== $rule) {
                throw ValidationException::withMessages(['normalization_rules' => '対象項目に許可されていない正規化ルールが含まれています。']);
            }
            $validatedRules[$field] = $rule;
        }

        return $validatedRules;
    }

    /** @param array<string, string> $values
     * @param  array<string, mixed>|null  $rules
     * @return array<string, string>
     */
    public function apply(string $targetType, array $values, ?array $rules): array
    {
        foreach ($this->validateRules($targetType, array_fill_keys(array_keys($values), 'mapped'), $rules ?? []) as $field => $rule) {
            if (isset($values[$field])) {
                [$values[$field]] = $this->normalize($rule, $values[$field]);
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $columnMap */
    private function configuredRule(string $targetType, string $field, array $columnMap): ?string
    {
        if (! in_array($field, $columnMap, true)) {
            return null;
        }

        $rule = config('opsdeck.import_normalization_rules.'.$targetType.'.'.$field);

        return is_string($rule) ? $rule : null;
    }

    /** @return array{0: string, 1: string|null} */
    private function normalize(string $rule, string $value): array
    {
        if ($rule === 'strip_amount_format') {
            $normalized = str_replace([',', ' ', '¥', '円'], '', $value);

            return [$normalized, $normalized !== $value ? '金額記号・区切りを除去' : null];
        }
        if ($rule === 'normalize_boolean_values') {
            $normalized = match (mb_strtolower($value)) {
                '1', 'true', 'yes', 'y', '有', 'はい' => 'true',
                '0', 'false', 'no', 'n', '無', 'いいえ' => 'false',
                default => $value,
            };

            return [$normalized, $normalized !== $value ? '真偽値へ変換' : null];
        }
        if ($rule === 'normalize_iso_date') {
            $dateParts = null;
            if (preg_match('~^(\d{4})([-/.])(\d{1,2})\2(\d{1,2})$~', $value, $matches) === 1) {
                $dateParts = [(int) $matches[1], (int) $matches[3], (int) $matches[4]];
            } elseif (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日$/u', $value, $matches) === 1) {
                $dateParts = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
            }
            if ($dateParts !== null && checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
                $normalized = sprintf('%04d-%02d-%02d', $dateParts[0], $dateParts[1], $dateParts[2]);

                return [$normalized, $normalized !== $value ? '明確な年月日をISO日付へ変換' : null];
            }
        }

        return [$value, null];
    }
}
