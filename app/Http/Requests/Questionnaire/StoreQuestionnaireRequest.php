<?php

namespace App\Http\Requests\Questionnaire;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionnaireRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:'.$maximum],
            'requester_name' => ['nullable', 'string', 'max:'.$maximum],
            'requested_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(config('opsdeck.questionnaire_statuses'))],
        ];
    }
}
