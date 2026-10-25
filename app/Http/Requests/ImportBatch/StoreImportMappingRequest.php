<?php

namespace App\Http\Requests\ImportBatch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $targetType = $this->input('target_type');
        $allowedFields = is_string($targetType)
            ? array_values(array_unique(array_merge(
                config('opsdeck.import_mapping_fields.'.$targetType, []),
                array_values(config('opsdeck.import_column_aliases.'.$targetType, [])),
            )))
            : [];

        return [
            'target_type' => ['required', 'string', 'in:'.implode(',', config('opsdeck.import_target_types'))],
            'column_map' => ['required', 'array', 'min:1'],
            'column_map.*' => ['nullable', 'string', 'max:100', Rule::in($allowedFields)],
            'normalization_rules' => ['nullable', 'array'],
            'normalization_rules.*' => ['nullable', 'string', Rule::in(array_keys(config('opsdeck.import_normalization_rule_labels', [])))],
            'value_mappings' => ['nullable', 'array'],
            'value_mappings.*' => ['array', 'max:'.config('opsdeck.import.value_mapping_max_entries')],
            'value_mappings.*.*' => ['array'],
            'value_mappings.*.*.source' => ['nullable', 'string', 'max:'.config('opsdeck.import.value_mapping_max_value_length')],
            'value_mappings.*.*.target' => ['nullable', 'string', 'max:'.config('opsdeck.import.value_mapping_max_value_length')],
            'profile_id' => ['nullable', 'integer'],
            'ai_mapping_feedback_token' => ['nullable', 'string', 'alpha_num', 'size:64'],
            'save_profile' => ['nullable', 'boolean'],
            'profile_name' => [
                'nullable', 'string', 'max:120',
                Rule::requiredIf(fn (): bool => $this->boolean('save_profile') && ! $this->filled('profile_id')),
            ],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $columnMap = $this->input('column_map');
            if (! is_array($columnMap)) {
                return;
            }

            $targetFields = array_values(array_filter(
                $columnMap,
                static fn (mixed $targetField): bool => is_string($targetField) && trim($targetField) !== '',
            ));
            if (count($targetFields) !== count(array_unique($targetFields, SORT_STRING))) {
                $validator->errors()->add('column_map', '同じ保存項目を複数の列に割り当てることはできません。使わない列は「この列は使いません」にしてください。');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'column_map.*.in' => 'この一覧には使えない項目が選ばれています。保存する項目の選択肢から選び直してください。',
        ];
    }
}
