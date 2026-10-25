<?php

namespace App\Http\Requests\Evidence;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class StoreEvidenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization && $this->user()?->can('manageOperationalData', $organization) === true;
    }

    public function rules(): array
    {
        $maximum = config('opsdeck.validation.name_max_length');

        return [
            'title' => ['required', 'string', 'max:'.$maximum],
            'evidence_type' => ['required', 'string', 'max:'.$maximum],
            'file' => ['required', 'file', 'mimes:'.implode(',', config('opsdeck.evidence.allowed_extensions')), 'max:'.config('opsdeck.evidence.max_size_kilobytes')],
            'description' => ['nullable', 'string', 'max:'.config('opsdeck.evidence.description_max_length')],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $maximumDescriptionLength = number_format((int) config('opsdeck.evidence.description_max_length'));

        return [
            'description.max' => "説明は{$maximumDescriptionLength}文字以内で入力してください。",
        ];
    }
}
