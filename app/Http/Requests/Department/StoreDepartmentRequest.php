<?php

namespace App\Http\Requests\Department;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization && $this->user()?->can('manageOperationalData', $organization) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.config('opsdeck.validation.name_max_length')],
            'code' => ['nullable', 'string', 'max:'.config('opsdeck.validation.name_max_length')],
            'parent_id' => ['nullable', 'integer'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
