<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuestionnaireQuestion\StoreQuestionnaireQuestionRequest;
use App\Http\Requests\QuestionnaireQuestion\UpdateQuestionnaireQuestionRequest;
use App\Models\Organization;
use App\Models\Questionnaire;
use App\Models\QuestionnaireQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuestionnaireQuestionController extends Controller
{
    public function store(StoreQuestionnaireQuestionRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $attributes = $request->validated();
        $questionnaire = Questionnaire::query()->where('organization_id', $organization->id)->findOrFail($attributes['questionnaire_id']);
        QuestionnaireQuestion::query()->create($attributes);

        return to_route('questionnaires.show', $questionnaire)->with('status', '質問を追加しました。');
    }

    public function update(UpdateQuestionnaireQuestionRequest $request, QuestionnaireQuestion $questionnaireQuestion): RedirectResponse
    {
        $organization = $this->organization($request);
        $questionnaire = Questionnaire::query()->where('organization_id', $organization->id)->findOrFail($questionnaireQuestion->questionnaire_id);
        $this->authorize('update', $questionnaireQuestion);
        $questionnaireQuestion->update($request->validated());

        return to_route('questionnaires.show', $questionnaire)->with('status', '質問を更新しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }
}
