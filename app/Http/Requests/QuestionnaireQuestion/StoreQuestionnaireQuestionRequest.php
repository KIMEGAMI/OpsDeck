<?php

namespace App\Http\Requests\QuestionnaireQuestion;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionnaireQuestionRequest extends FormRequest
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
            'questionnaire_id' => ['required', 'integer'],
            'section' => ['nullable', 'string', 'max:'.$maximum],
            'question_number' => ['nullable', 'string', 'max:'.$maximum],
            'question_text' => ['required', 'string'],
            'answer_type' => ['required', 'string', 'max:'.$maximum],
            'sort_order' => ['required', 'integer', 'min:1'],
        ];
    }
}
