<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VoidWorkTimeMeasurementRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'max:'.config('opsdeck.work_time.void_reason_max_length')]];
    }

    public function messages(): array
    {
        return ['reason.required' => '取り消す理由を入力してください。'];
    }
}
