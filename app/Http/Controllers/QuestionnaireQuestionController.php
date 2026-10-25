<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuestionnaireQuestion\StoreQuestionnaireQuestionRequest;
use App\Http\Requests\QuestionnaireQuestion\UpdateQuestionnaireQuestionRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Questionnaire;
use App\Models\QuestionnaireQuestion;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionnaireQuestionController extends Controller
{
    public function store(StoreQuestionnaireQuestionRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $attributes = $request->validated();
        $questionnaire = Questionnaire::query()->where('organization_id', $organization->id)->findOrFail($attributes['questionnaire_id']);
        DB::transaction(function () use ($request, $organization, $attributes): void {
            $question = QuestionnaireQuestion::query()->create($attributes);

            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => QuestionnaireQuestion::class,
                'entity_id' => $question->id,
                'action' => AuditLog::QuestionnaireQuestionCreated,
                'after_json' => ['changed_fields' => array_keys($attributes)],
            ]);
            app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
        });

        return to_route('questionnaires.show', $questionnaire)->with('status', '質問を追加しました。');
    }

    public function update(UpdateQuestionnaireQuestionRequest $request, QuestionnaireQuestion $questionnaireQuestion): RedirectResponse
    {
        $organization = $this->organization($request);
        $questionnaire = Questionnaire::query()->where('organization_id', $organization->id)->findOrFail($questionnaireQuestion->questionnaire_id);
        $this->authorize('update', $questionnaireQuestion);
        $attributes = $request->validated();

        DB::transaction(function () use ($request, $organization, $questionnaireQuestion, $attributes): void {
            $questionnaireQuestion->fill($attributes);
            $changedFields = array_keys($questionnaireQuestion->getDirty());
            $questionnaireQuestion->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => QuestionnaireQuestion::class,
                    'entity_id' => $questionnaireQuestion->id,
                    'action' => AuditLog::QuestionnaireQuestionUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
                app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
            }
        });

        return to_route('questionnaires.show', $questionnaire)->with('status', '質問を更新しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }
}
