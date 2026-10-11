<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $employee = $this->route('employee');

        if (! $organization instanceof Organization || ! $employee instanceof Employee) {
            return false;
        }

        abort_unless($employee->organization_id === $organization->id, 404);

        return $this->user()?->can('update', $employee) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $maximum = config('opsdeck.validation.name_max_length');

        return [
            'employee_number' => ['nullable', 'string', 'max:'.$maximum],
            'name' => ['required', 'string', 'max:'.$maximum],
            'email' => ['nullable', 'email:rfc', 'max:'.$maximum],
            'department_id' => ['nullable', 'integer'],
            'employment_status' => ['required', Rule::in(config('opsdeck.employee_statuses'))],
            'joined_at' => ['nullable', 'date'],
            'left_at' => ['nullable', 'date', 'after_or_equal:joined_at'],
            'job_title' => ['nullable', 'string', 'max:'.$maximum],
            'memo' => ['nullable', 'string'],
        ];
    }
}
