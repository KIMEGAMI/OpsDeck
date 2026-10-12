<?php

namespace App\Http\Requests\ImportBatch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportMappingRequest extends FormRequest
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
            'target_type' => ['required', 'string', 'in:'.implode(',', config('opsdeck.import_target_types'))],
            'column_map' => ['required', 'array', 'min:1'],
            'column_map.*' => ['nullable', 'string', 'max:100'],
            'normalization_rules' => ['nullable', 'array'],
            'normalization_rules.*' => ['required', 'string', Rule::in(array_keys(config('opsdeck.import_normalization_rule_labels', [])))],
            'profile_id' => ['nullable', 'integer'],
            'save_profile' => ['nullable', 'boolean'],
            'profile_name' => [
                'nullable', 'string', 'max:120',
                Rule::requiredIf(fn (): bool => $this->boolean('save_profile') && ! $this->filled('profile_id')),
            ],
        ];
    }
}
