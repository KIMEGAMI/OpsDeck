<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization !== null && $this->user()?->can('update', $organization) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'timezone'],
            'company_size' => ['nullable', 'integer', 'min:1', 'max:'.config('opsdeck.validation.company_size_maximum')],
            'ai_enabled' => ['sometimes', 'boolean'],
            'cost_savings_renewal_rate_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.config('opsdeck.cost_savings.organization_renewal_rate_percent_max')],
            'cost_savings_unused_asset_monthly_jpy' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.config('opsdeck.cost_savings.organization_unused_asset_monthly_jpy_max')],
        ];
    }
}
