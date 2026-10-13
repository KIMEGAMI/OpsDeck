<?php

namespace App\Http\Requests\QuestionnaireAnswer;

use App\Models\Organization;
use App\Models\QuestionnaireAnswer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionnaireAnswerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $questionnaireAnswer = $this->route('questionnaireAnswer');

        if (! $organization instanceof Organization || ! $questionnaireAnswer instanceof QuestionnaireAnswer) {
            return false;
        }

        $organizationId = $questionnaireAnswer->question()->value('questionnaire_id')
            ? $questionnaireAnswer->question->questionnaire()->value('organization_id')
            : null;

        abort_unless($organizationId === $organization->id, 404);

        return $this->user()?->can('update', $questionnaireAnswer) === true;
    }

    public function rules(): array
    {
        $maximum = config('opsdeck.validation.name_max_length');

        return [
            'answer_text' => ['nullable', 'string'],
            'answer_choice' => ['nullable', 'string', 'max:'.$maximum],
            'answer_status' => ['required', Rule::in([QuestionnaireAnswer::DRAFT_STATUS])],
            'source_type' => ['required', Rule::in(config('opsdeck.questionnaire_answer_source_types'))],
        ];
    }
}
