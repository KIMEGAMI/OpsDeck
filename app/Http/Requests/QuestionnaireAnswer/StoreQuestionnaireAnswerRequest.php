<?php

namespace App\Http\Requests\QuestionnaireAnswer;

use App\Models\Organization;
use App\Models\QuestionnaireAnswer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionnaireAnswerRequest extends FormRequest
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
            'question_id' => ['required', 'integer'],
            'answer_text' => ['nullable', 'string', 'required_without:answer_choice'],
            'answer_choice' => ['nullable', 'string', 'max:'.$maximum, 'required_without:answer_text'],
            'answer_status' => ['required', Rule::in([QuestionnaireAnswer::DRAFT_STATUS])],
            'source_type' => ['required', Rule::in(config('opsdeck.questionnaire_answer_source_types'))],
        ];
    }
}
