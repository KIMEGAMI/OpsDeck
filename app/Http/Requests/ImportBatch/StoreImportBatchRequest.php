<?php

namespace App\Http\Requests\ImportBatch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportBatchRequest extends FormRequest
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
            'source_name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:'.implode(',', config('opsdeck.import.allowed_extensions')), 'max:'.config('opsdeck.import.max_size_kilobytes')],
            'target_type' => ['nullable', 'string', Rule::in(config('opsdeck.import_target_types', []))],
        ];
    }
}
