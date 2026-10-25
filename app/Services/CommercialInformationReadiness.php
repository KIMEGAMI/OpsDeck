<?php

namespace App\Services;

class CommercialInformationReadiness
{
    private const REQUIRED_FIELDS = ['seller_name', 'seller_address', 'seller_phone', 'seller_representative'];

    private const PLACEHOLDER_VALUES = ['未設定', '入力してください', '準備中', 'サンプル', 'テスト', 'tbd', 'todo', 'sample', 'test', 'example', 'changeme'];

    public function isConfigured(): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = config('opsdeck.commercial_information.'.$field);

            if (! is_string($value) || $this->isPlaceholder($value)) {
                return false;
            }
        }

        return true;
    }

    private function isPlaceholder(string $value): bool
    {
        $normalizedValue = mb_strtolower(trim($value));

        return $normalizedValue === '' || in_array($normalizedValue, self::PLACEHOLDER_VALUES, true);
    }
}
