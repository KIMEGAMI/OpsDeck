<?php

namespace App\Http\Requests\SaasAssignment;

use App\Models\Organization;
use App\Models\SaasAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSaasAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $saasAssignment = $this->route('saasAssignment');

        if (! $organization instanceof Organization || ! $saasAssignment instanceof SaasAssignment) {
            return false;
        }

        abort_unless($saasAssignment->organization_id === $organization->id, 404);

        return $this->user()?->can('update', $saasAssignment) === true;
    }

    public function rules(): array
    {
        $maximum = config('opsdeck.validation.name_max_length');

        return [
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
