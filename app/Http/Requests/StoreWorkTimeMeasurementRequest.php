<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkTimeMeasurementRequest extends FormRequest
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

        $currencyCodeLength = (int) config('opsdeck.work_time.currency_code_length');

        return [
            'process_name' => ['required', 'string', 'regex:/[^\p{Z}\s]/u', 'max:'.config('opsdeck.work_time.process_name_max_length')],
            'before_minutes' => ['required', 'integer', 'min:'.config('opsdeck.work_time.minutes_per_run_min'), 'max:'.config('opsdeck.work_time.minutes_per_run_max')],
            'after_minutes' => ['required', 'integer', 'min:'.config('opsdeck.work_time.after_minutes_min'), 'max:'.config('opsdeck.work_time.minutes_per_run_max')],
            'monthly_occurrences' => ['required', 'integer', 'min:'.config('opsdeck.work_time.monthly_occurrences_min'), 'max:'.config('opsdeck.work_time.monthly_occurrences_max')],
            'hourly_rate' => ['nullable', 'numeric', 'decimal:0,'.config('opsdeck.work_time.decimal_places'), 'min:'.config('opsdeck.work_time.hourly_rate_min'), 'max:'.config('opsdeck.work_time.hourly_rate_max')],
            'currency' => ['nullable', 'required_with:hourly_rate', 'string', 'regex:/\A[A-Z]{'.$currencyCodeLength.'}\z/'],
            'measured_on' => ['required', 'date', 'before_or_equal:'.now($organization->timezone)->toDateString()],
            'evidence_reference' => ['required', 'string', 'max:'.config('opsdeck.work_time.evidence_reference_max_length')],
        ];
    }

    public function messages(): array
    {
        return [
            'process_name.required' => '作業名を入力してください。空白だけでは登録できません。',
            'before_minutes.min' => '改善前の作業時間は1分以上を入力してください。',
            'after_minutes.min' => '改善後の作業時間は0分以上を入力してください。',
            'monthly_occurrences.min' => '月あたりの回数は1回以上を入力してください。',
            'hourly_rate.min' => '時間単価は0より大きい金額を入力してください。',
            'process_name.regex' => '作業名には文字または数字を入力してください。空白だけでは登録できません。',
            'currency.required_with' => '時間単価を入力した場合は通貨も選んでください。',
            'currency.regex' => '通貨コードは大文字の3英字で入力してください。',
            'measured_on.before_or_equal' => '計測日は今日以前の日付を指定してください。',
            'evidence_reference.required' => '計測方法や確認した資料を入力してください。',
        ];
    }

    public function attributes(): array
    {
        return [
            'process_name' => '作業名',
            'before_minutes' => '改善前の時間',
            'after_minutes' => '改善後の時間',
            'monthly_occurrences' => '月あたりの回数',
            'hourly_rate' => '時間単価',
            'currency' => '通貨',
            'measured_on' => '計測日',
            'evidence_reference' => '計測方法・確認資料',
        ];
    }
}
