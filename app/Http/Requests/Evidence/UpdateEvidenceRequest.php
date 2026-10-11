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
            'description' => ['nullable', 'string'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ];
    }
}
