<?php

namespace App\Http\Requests\SecurityControl;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSecurityControlRequest extends FormRequest
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
