<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class VoidRealizedSavingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization && $this->user()?->can('manageOperationalData', $organization) === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.config('opsdeck.cost_savings.realized_description_max_length')],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => '取消理由を入力してください。'];
    }
}
