<?php

namespace App\Http\Requests\QuestionnaireQuestion;

use App\Models\Organization;
use App\Models\QuestionnaireQuestion;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionnaireQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $questionnaireQuestion = $this->route('questionnaireQuestion');

        if (! $organization instanceof Organization || ! $questionnaireQuestion instanceof QuestionnaireQuestion) {
            return false;
        }

        abort_unless($questionnaireQuestion->questionnaire()->value('organization_id') === $organization->id, 404);

        return $this->user()?->can('update', $questionnaireQuestion) === true;
    }

    public function rules(): array
    {
        $maximum = config('opsdeck.validation.name_max_length');

        return [
            'section' => ['nullable', 'string', 'max:'.$maximum],
            'question_number' => ['nullable', 'string', 'max:'.$maximum],
            'question_text' => ['required', 'string'],
            'answer_type' => ['required', 'string', 'max:'.$maximum],
            'sort_order' => ['required', 'integer', 'min:1'],
        ];
    }
}
