<?php

namespace App\Http\Requests\Evidence;

use App\Models\Evidence;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEvidenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $evidence = $this->route('evidence');

        if (! $organization instanceof Organization || ! $evidence instanceof Evidence) {
            return false;
        }

        abort_unless($evidence->organization_id === $organization->id, 404);

        return $this->user()?->can('update', $evidence) === true;
    }

    public function rules(): array
    {
        $maximum = config('opsdeck.validation.name_max_length');

        return [
            'title' => ['required', 'string', 'max:'.$maximum],
            'evidence_type' => ['required', 'string', 'max:'.$maximum],
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
