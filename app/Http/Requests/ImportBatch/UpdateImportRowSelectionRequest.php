<?php

namespace App\Http\Requests\ImportBatch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateImportRowSelectionRequest extends FormRequest
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
        $visibleRowNumbers = $this->input('visible_row_numbers', []);
        $visibleRowNumbers = is_array($visibleRowNumbers) ? $visibleRowNumbers : [];

        return [
            'visible_row_numbers' => ['present', 'array'],
            'visible_row_numbers.*' => ['integer', 'min:1', 'distinct'],
            'selected_row_numbers' => ['sometimes', 'array'],
            'selected_row_numbers.*' => ['integer', 'min:1', Rule::in($visibleRowNumbers)],
            'row_results_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
