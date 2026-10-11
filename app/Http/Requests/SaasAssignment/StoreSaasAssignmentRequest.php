<?php

namespace App\Http\Requests\SaasAssignment;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaasAssignmentRequest extends FormRequest
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
            'saas_service_id' => ['required', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'account_identifier' => ['nullable', 'string', 'max:'.$maximum],
            'license_name' => ['nullable', 'string', 'max:'.$maximum],
            'status' => ['required', 'string', 'max:'.$maximum],
            'mfa_status' => ['required', Rule::in(config('opsdeck.saas_assignment_mfa_statuses'))],
            'last_reviewed_at' => ['nullable', 'date'],
            'assigned_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
            'memo' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['assigned_at', 'ended_at'])) {
                return;
            }

            if ($this->filled('assigned_at') && $this->filled('ended_at') && $this->date('ended_at')->isBefore($this->date('assigned_at'))) {
                $validator->errors()->add('ended_at', '終了日は割当日以降の日付を指定してください。');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'mfa_status.in' => 'MFA状態が不正です。',
        ];
    }
}
