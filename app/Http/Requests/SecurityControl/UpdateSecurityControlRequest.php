<?php

namespace App\Http\Requests\SecurityControl;

use App\Models\Organization;
use App\Models\SecurityControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSecurityControlRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $securityControl = $this->route('security_control');

        if (! $organization instanceof Organization || ! $securityControl instanceof SecurityControl) {
            return false;
        }

        abort_unless($securityControl->organization_id === $organization->id, 404);

        return $this->user()?->can('update', $securityControl) === true;
    }

    public function rules(): array
    {
        $maximum = config('opsdeck.validation.name_max_length');

        return [
            'control_code' => ['required', 'string', 'max:'.$maximum],
            'title' => ['required', 'string', 'max:'.$maximum],
            'category' => ['required', 'string', 'max:'.$maximum],
            'description' => ['required', 'string'],
            'status' => ['required', Rule::in(config('opsdeck.security_control_statuses'))],
            'owner_employee_id' => ['nullable', 'integer'],
            'review_frequency' => ['nullable', 'string', 'max:'.$maximum],
            'last_reviewed_at' => ['nullable', 'date'],
            'next_review_at' => ['nullable', 'date'],
            'memo' => ['nullable', 'string'],
        ];
    }
}
