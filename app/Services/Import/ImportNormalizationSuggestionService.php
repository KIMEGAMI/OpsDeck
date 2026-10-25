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

    /**
     * @param  list<array<string, string|int>>  $rows
     * @param  array<string, string|null>  $columnMap
     * @return array<string, list<array{source: string, target: string, target_label: string}>>
     */
    public function suggestValueMappings(string $targetType, array $rows, array $columnMap): array
    {
        $allowedFields = config('opsdeck.import_value_mapping_fields.'.$targetType, []);
        $configuredSuggestions = config('opsdeck.import_value_mapping_suggestions.'.$targetType, []);
        $targetLabels = config('opsdeck.import_value_labels_by_target.'.$targetType, []);
        $suggestions = [];

        foreach ($columnMap as $sourceHeader => $targetField) {
            if (! is_string($targetField)
                || ! in_array($targetField, $allowedFields, true)
                || ! is_array($configuredSuggestions[$targetField] ?? null)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! array_key_exists($sourceHeader, $row)) {
                    continue;
                }

                $source = trim((string) $row[$sourceHeader]);
                $target = $configuredSuggestions[$targetField][$source] ?? null;
                $label = is_string($target) ? ($targetLabels[$targetField][$target] ?? null) : null;
                if ($source === '' || ! is_string($target) || ! is_string($label)) {
                    continue;
                }

                $suggestions[$targetField][$source] = [
                    'source' => $source,
                    'target' => $target,
                    'target_label' => $label,
                ];
            }
        }

        return array_map(static fn (array $fieldSuggestions): array => array_values($fieldSuggestions), $suggestions);
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
        $mappedFields = $this->mappedTargetFields($columnMap);
        $validatedRules = [];
        foreach ($rules as $field => $rule) {
            if (! is_string($field)) {
                throw ValidationException::withMessages(['normalization_rules' => '対象項目に許可されていない正規化ルールが含まれています。']);
            }

            if ($rule === null || $rule === '') {
                continue;
            }

            $ruleOptions = config('opsdeck.import_normalization_rule_options.'.$targetType.'.'.$field);
            $allowedFieldRules = is_array($ruleOptions) ? $ruleOptions : array_filter([$allowedRules[$field] ?? null]);
            if (! is_string($rule) || ! in_array($field, $mappedFields, true) || ! in_array($rule, $allowedFieldRules, true)) {
                throw ValidationException::withMessages(['normalization_rules' => '対象項目に許可されていない正規化ルールが含まれています。']);
            }

            $validatedRules[$field] = $rule;
        }

        return $validatedRules;
    }

    /**
     * @param  array<string, string|null>|list<string>  $columnMap
     * @return array<string, list<array{source: string, target: string}>>
     */
    public function validateValueMappings(string $targetType, array $columnMap, mixed $mappings): array
    {
        if ($mappings === null) {
            return [];
        }
        if (! is_array($mappings)) {
            throw ValidationException::withMessages(['value_mappings' => '値の置換設定の形式が正しくありません。']);
        }

        $allowedFields = config('opsdeck.import_value_mapping_fields.'.$targetType, []);
        $mappedFields = $this->mappedTargetFields($columnMap);
        $maximumEntries = (int) config('opsdeck.import.value_mapping_max_entries');
        $maximumValueLength = (int) config('opsdeck.import.value_mapping_max_value_length');
        $entryCount = 0;
        $validatedMappings = [];

        foreach ($mappings as $field => $entries) {
            if (! is_string($field)
                || ! in_array($field, $allowedFields, true)
                || ! in_array($field, $mappedFields, true)
                || ! is_array($entries)) {
                throw ValidationException::withMessages(['value_mappings' => '値の置換は対応項目のマッピング済みフィールドだけに設定できます。']);
            }

            $fieldMappings = [];
            $seenSources = [];
            foreach ($entries as $entryKey => $entry) {
                if (is_string($entry) && (is_string($entryKey) || is_int($entryKey))) {
                    $source = trim((string) $entryKey);
                    $target = trim($entry);
                } elseif (is_array($entry)
                    && is_string($entry['source'] ?? null)
                    && is_string($entry['target'] ?? null)) {
                    $source = trim($entry['source']);
                    $target = trim($entry['target']);
                } else {
                    throw ValidationException::withMessages(['value_mappings' => '値の置換行の形式が正しくありません。']);
                }
                if ($source === '' && $target === '') {
                    continue;
                }
                if ($source === '' || $target === '' || mb_strlen($source) > $maximumValueLength || mb_strlen($target) > $maximumValueLength) {
                    throw ValidationException::withMessages(['value_mappings' => '値の置換元と置換後を入力し、許可された文字数内にしてください。']);
                }
                if (isset($seenSources[$source])) {
                    throw ValidationException::withMessages(['value_mappings' => '同じ項目に重複した置換元を設定できません。']);
                }
                $seenSources[$source] = true;

                $entryCount++;
                if ($entryCount > $maximumEntries) {
                    throw ValidationException::withMessages(['value_mappings' => '値の置換数が上限を超えています。']);
                }

                $fieldMappings[] = ['source' => $source, 'target' => $target];
            }

            if ($fieldMappings !== []) {
                $validatedMappings[$field] = $fieldMappings;
            }
        }

        return $validatedMappings;
    }

    /** @param array<string, string> $values
     * @param  array<string, mixed>|null  $rules
     * @return array<string, string>
     */
    public function apply(string $targetType, array $values, ?array $rules, ?array $valueMappings = null): array
    {
        foreach ($this->validateValueMappings($targetType, array_keys($values), $valueMappings ?? []) as $field => $mappings) {
            if (! isset($values[$field]) || ! is_string($values[$field])) {
                continue;
            }
            foreach ($mappings as $mapping) {
                if ($values[$field] === $mapping['source']) {
                    $values[$field] = $mapping['target'];
                    break;
                }
            }
        }

        foreach ($this->validateRules($targetType, array_keys($values), $rules ?? []) as $field => $rule) {
            if (isset($values[$field])) {
                [$values[$field]] = $this->normalize($rule, $values[$field]);
            }
        }

        return $values;
    }

    /** @param array<string, string|null>|list<string> $columnMap
     * @return list<string>
     */
    private function mappedTargetFields(array $columnMap): array
    {
        return array_values(array_unique(array_filter($columnMap, static fn (mixed $field): bool => is_string($field) && $field !== '')));
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
        if (in_array($rule, ['normalize_month_first_date', 'normalize_day_first_date'], true)
            && preg_match('~^(\d{1,2})([-/.])(\d{1,2})\2(\d{4})$~', $value, $matches) === 1) {
            $firstPart = (int) $matches[1];
            $secondPart = (int) $matches[3];
            $year = (int) $matches[4];
            $month = $rule === 'normalize_month_first_date' ? $firstPart : $secondPart;
            $day = $rule === 'normalize_month_first_date' ? $secondPart : $firstPart;

            if (checkdate($month, $day, $year)) {
                return [sprintf('%04d-%02d-%02d', $year, $month, $day), '選択した日付順序で YYYY-MM-DD に変換'];
            }
        }

        return [$value, null];
    }
}
