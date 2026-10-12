<?php

namespace App\Http\Requests\ImportBatch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SelectImportSheetRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $headerRowNumber = $this->input('header_row_number', config('opsdeck.import.default_header_row_number'));
        $this->merge([
            'header_row_number' => $headerRowNumber,
            'header_end_row_number' => $this->input('header_end_row_number', $headerRowNumber),
        ]);
    }

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
            'sheet_name' => ['required', 'string', 'max:255'],
            'header_row_number' => ['required', 'integer', 'min:1', 'max:'.(int) config('opsdeck.import.max_header_row_number')],
            'header_end_row_number' => ['required', 'integer', 'gte:header_row_number', 'max:'.(int) config('opsdeck.import.max_header_row_number')],
            'data_start_row_number' => ['nullable', 'integer', 'gt:header_end_row_number', 'max:'.(int) config('opsdeck.import.max_source_row_number')],
        ];
    }
}
