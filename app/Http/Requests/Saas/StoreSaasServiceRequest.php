<?php

namespace App\Http\Requests\Saas;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaasServiceRequest extends FormRequest
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
        $maximum = config('opsdeck.validation.name_max_length');

        return [
            'name' => ['required', 'string', 'max:'.$maximum],
            'vendor' => ['nullable', 'string', 'max:'.$maximum],
            'category' => ['nullable', 'string', 'max:'.$maximum],
            'website_url' => ['nullable', 'url', 'max:'.$maximum],
            'status' => ['required', Rule::in(config('opsdeck.saas_service_statuses'))],
            'memo' => ['nullable', 'string'],
        ];
    }
}
