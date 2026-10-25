<?php

namespace App\Http\Requests;

use App\Rules\SupportErrorReferenceRule;
use App\Services\SupportErrorReference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(array_keys(config('opsdeck.support.categories', [])))],
            'message' => ['required', 'string', 'max:5000'],
            'error_reference' => ['nullable', 'string', 'max:'.SupportErrorReference::MAX_LENGTH, new SupportErrorReferenceRule],
        ];
    }
}
