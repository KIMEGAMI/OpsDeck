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

    protected function prepareForValidation(): void
    {
        $headerRowNumber = $this->input('header_row_number') ?? config('opsdeck.import.default_header_row_number');
        $this->merge([
            'header_row_number' => $headerRowNumber,
            'header_end_row_number' => $this->input('header_end_row_number') ?? $headerRowNumber,
        ]);
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
            'diff_source_batch_id' => ['nullable', 'integer', 'min:1'],
            'header_row_number' => ['nullable', 'integer', 'min:1', 'max:'.config('opsdeck.import.max_header_row_number')],
            'header_end_row_number' => ['nullable', 'integer', 'gte:header_row_number', 'max:'.config('opsdeck.import.max_header_row_number')],
            'data_start_row_number' => ['nullable', 'integer', 'max:'.min(config('opsdeck.import.max_source_row_number'), config('opsdeck.import.xlsx_max_rows')), 'gt:header_end_row_number'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maximumHeaderRow = number_format((int) config('opsdeck.import.max_header_row_number'));
        $maximumDataRow = number_format((int) min(config('opsdeck.import.max_source_row_number'), config('opsdeck.import.xlsx_max_rows')));
        $maximumFileSize = number_format((int) config('opsdeck.import.max_size_kilobytes'));

        return [
            'source_name.required' => '履歴から見つけられるよう、取込名を入力してください。',
            'source_name.string' => '取込名を文字で入力してください。',
            'source_name.max' => '取込名が長すぎます。短くしてから、もう一度お試しください。',
            'file.required' => '取り込むCSVファイルまたはExcelファイルを選んでください。',
            'file.file' => '選んだファイルを確認できません。もう一度選び直してください。',
            'file.uploaded' => 'ファイルを送信できませんでした。サイズを確認して、もう一度お試しください。',
            'file.mimes' => 'CSV形式、または対応するExcel（XLSX）形式のファイルを選んでください。',
            'file.max' => "ファイルが大きすぎます。最大{$maximumFileSize}KBまでです。表を分けてから、もう一度お試しください。",
            'target_type.string' => '取込対象の選択が正しくありません。画面から対象を選び直してください。',
            'target_type.in' => '取込対象を選び直してください。',
            'diff_source_batch_id.integer' => '比較する前回の取込を選び直してください。',
            'diff_source_batch_id.min' => '比較する前回の取込を選び直してください。',
            'header_row_number.integer' => '列名が始まる行を数字で入力してください。',
            'header_row_number.min' => '列名が始まる行は1行目以降を指定してください。',
            'header_row_number.max' => "列名が始まる行は{$maximumHeaderRow}行目までを指定してください。",
            'header_end_row_number.integer' => '列名が終わる行を数字で入力してください。',
            'header_end_row_number.gte' => '列名が終わる行は、始まる行と同じか後の行にしてください。',
            'header_end_row_number.max' => "列名が終わる行は{$maximumHeaderRow}行目までを指定してください。",
            'data_start_row_number.integer' => 'データが始まる行を数字で入力してください。',
            'data_start_row_number.gt' => 'データが始まる行は、列名が終わった次の行以降にしてください。',
            'data_start_row_number.max' => "データが始まる行は{$maximumDataRow}行目までを指定してください。",
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source_name' => '取込名',
            'file' => 'ファイル',
            'target_type' => '取込対象',
            'diff_source_batch_id' => '前回の取込',
            'header_row_number' => '列名が始まる行',
            'header_end_row_number' => '列名が終わる行',
            'data_start_row_number' => 'データが始まる行',
        ];
    }
}
