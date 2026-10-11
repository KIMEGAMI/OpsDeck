<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class ContractAmountValidator
{
    private const string MAXIMUM_AMOUNT = '999999999999.99';

    /** @return list<string> */
    public function rules(): array
    {
        return ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAXIMUM_AMOUNT];
    }

    public function isValid(mixed $amount): bool
    {
        return Validator::make(['amount' => $amount], ['amount' => $this->rules()])->passes();
    }
}
