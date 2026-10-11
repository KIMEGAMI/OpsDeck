<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRealizedSavingRequest extends FormRequest
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
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        abort_unless($organization instanceof Organization, 403);

        $periods = config('opsdeck.cost_savings.realized_periods', []);
        $currencyCodeLength = (int) config('opsdeck.cost_savings.currency_code_length');

        return [
            'contract_id' => [
                'nullable',
                'integer',
                Rule::exists('contracts', 'id')->where(fn (Builder $query) => $query->where('organization_id', $organization->id)),
            ],
            'evidence_id' => [
                'nullable',
                'integer',
                Rule::exists('evidence', 'id')->where(fn (Builder $query) => $query
                    ->where('organization_id', $organization->id)
                    ->where('storage_path', '<>', 'metadata-only')),
            ],
            'description' => ['required', 'string', 'max:'.config('opsdeck.cost_savings.realized_description_max_length')],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:'.config('opsdeck.cost_savings.realized_amount_max')],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{'.$currencyCodeLength.'}\z/'],
            'period' => ['required', Rule::in(array_keys($periods))],
            'realized_on' => ['required', 'date', 'before_or_equal:'.now($organization->timezone)->toDateString()],
            'evidence_reference' => ['required', 'string', 'max:'.config('opsdeck.cost_savings.evidence_reference_max_length')],
        ];
    }

    public function messages(): array
    {
        return [
            'contract_id.exists' => '選択した契約はこの組織に登録されていません。',
            'evidence_id.exists' => '選択した証憑はこの組織で利用できません。',
            'amount.gt' => '削減額は0より大きい値を入力してください。',
            'currency.regex' => '通貨コードは大文字の3英字で入力してください。',
            'period.in' => '削減額の期間を選択してください。',
            'realized_on.before_or_equal' => '実現日は今日以前の日付を指定してください。',
            'evidence_reference.required' => '削減を確認した請求書・稟議などの参照情報を入力してください。',
        ];
    }

    public function attributes(): array
    {
        return [
            'contract_id' => '対象契約',
            'evidence_id' => '保存済み証憑',
            'description' => '削減内容',
            'amount' => '削減額',
            'currency' => '通貨コード',
            'period' => '期間',
            'realized_on' => '実現日',
            'evidence_reference' => '根拠の参照情報',
        ];
    }
}
