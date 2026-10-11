<?php

namespace App\Http\Requests\Contract;

use App\Models\Organization;
use App\Services\ContractAmountValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContractRequest extends FormRequest
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
            'saas_service_id' => ['nullable', 'integer'],
            'vendor_name' => ['required', 'string', 'max:'.$maximum],
            'contract_name' => ['required', 'string', 'max:'.$maximum],
            'contract_type' => ['required', 'string', 'max:'.$maximum],
            'billing_cycle' => ['required', Rule::in(config('opsdeck.contract_billing_cycles'))],
            'amount' => app(ContractAmountValidator::class)->rules(),
            'currency' => ['required', 'string', 'max:'.$maximum],
            'started_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'auto_renew' => ['required', 'boolean'],
            'cancellation_deadline' => ['nullable', 'date'],
            'renewal_date' => ['nullable', 'date'],
            'license_quantity' => ['nullable', 'integer', 'min:1'],
            'owner_employee_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(config('opsdeck.contract_statuses'))],
            'memo' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['started_at', 'ends_at'])) {
                return;
            }

            if ($this->filled('started_at') && $this->filled('ends_at') && $this->date('ends_at')->isBefore($this->date('started_at'))) {
                $validator->errors()->add('ends_at', '終了日は開始日以降の日付を指定してください。');
            }
        }];
    }
}
