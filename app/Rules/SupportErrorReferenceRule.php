<?php

namespace App\Rules;

use App\Services\SupportErrorReference;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SupportErrorReferenceRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (SupportErrorReference::normalize($value) === null) {
            $fail('エラー照会IDの形式を確認してください。');
        }
    }
}
