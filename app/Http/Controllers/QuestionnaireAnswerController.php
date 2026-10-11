<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuestionnaireAnswer\StoreQuestionnaireAnswerRequest;
use App\Http\Requests\QuestionnaireAnswer\UpdateQuestionnaireAnswerRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Services\QuestionnaireDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuestionnaireAnswerController extends Controller
{
    public function store(StoreQuestionnaireAnswerRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $attributes = $request->validated();
        $question = QuestionnaireQuestion::query()
            ->whereHas('questionnaire', fn ($query) => $query->where('organization_id', $organization->id))
            ->findOrFail($attributes['question_id']);
        QuestionnaireAnswer::query()->create($attributes);

        return to_route('questionnaires.show', $question->questionnaire_id)->with('status', '回答を登録しました。');
    }

    public function update(UpdateQuestionnaireAnswerRequest $request, QuestionnaireAnswer $questionnaireAnswer): RedirectResponse
    {
        $organization = $this->organization($request);
        $question = QuestionnaireQuestion::query()
            ->whereHas('questionnaire', fn ($query) => $query->where('organization_id', $organization->id))
            ->findOrFail($questionnaireAnswer->question_id);
        $this->authorize('update', $questionnaireAnswer);
        $questionnaireAnswer->update($request->validated());

        return to_route('questionnaires.show', $question->questionnaire_id)->with('status', '回答を更新しました。');
    }

    public function approve(Request $request, QuestionnaireAnswer $questionnaireAnswer): RedirectResponse
    {
        $organization = $this->organization($request);
        $question = QuestionnaireQuestion::query()
            ->whereHas('questionnaire', fn ($query) => $query->where('organization_id', $organization->id))
            ->findOrFail($questionnaireAnswer->question_id);
        $this->authorize('approve', $questionnaireAnswer);
        $questionnaireAnswer->update(['approved_by' => $request->user()->id, 'approved_at' => now()]);
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'entity_type' => QuestionnaireAnswer::class,
            'entity_id' => $questionnaireAnswer->id,
            'action' => AuditLog::QuestionnaireAnswerApproved,
        ]);

        return to_route('questionnaires.show', $question->questionnaire_id)->with('status', '回答を承認しました。');
    }

    public function draft(Request $request, Questionnaire $questionnaire): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        abort_unless($questionnaire->organization_id === $organization->id, 404);
        $validated = $request->validate(['question_id' => ['required', 'integer']]);
        $question = $questionnaire->questions()->findOrFail($validated['question_id']);
        app(QuestionnaireDraftService::class)->generate($organization, $question);

        return to_route('questionnaires.show', $questionnaire)->with('status', 'AI回答下書きを作成しました。内容を確認してから承認してください。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }
}
