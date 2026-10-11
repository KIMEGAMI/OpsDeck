<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $nameMaximum = config('opsdeck.validation.name_max_length');

        return [
            'name' => ['required', 'string', 'max:'.$nameMaximum],
            'email' => ['required', 'string', 'email:rfc', 'max:'.$nameMaximum, 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'organization_name' => ['required', 'string', 'max:'.$nameMaximum],
            'company_size' => ['nullable', 'integer', 'min:1', 'max:'.config('opsdeck.validation.company_size_maximum')],
            'industry' => ['nullable', 'string', 'max:'.$nameMaximum],
            'timezone' => ['nullable', 'timezone'],
        ];
    }
}
