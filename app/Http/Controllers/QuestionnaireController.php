<?php

namespace App\Http\Controllers;

use App\Http\Requests\Questionnaire\StoreQuestionnaireRequest;
use App\Http\Requests\Questionnaire\UpdateQuestionnaireRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Questionnaire;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class QuestionnaireController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $filters = $request->validate(['pending' => ['sometimes', 'boolean']]);
        $questionnaires = Questionnaire::query()
            ->where('organization_id', $organization->id)
            ->withCount('pendingAnswers')
            ->when(($filters['pending'] ?? false), fn ($query) => $query->has('pendingAnswers'))
            ->orderBy('due_at')
            ->orderBy('id')
            ->paginate(config('opsdeck.pagination.per_page'))
            ->withQueryString();

        return view('questionnaires.index', compact('questionnaires', 'organization', 'filters'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        return view('questionnaires.form', ['questionnaire' => new Questionnaire(['status' => config('opsdeck.questionnaire_status.draft')]), 'formAction' => route('questionnaires.store'), 'formMethod' => 'POST']);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreQuestionnaireRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $attributes = $request->validated();
        $questionnaire = DB::transaction(function () use ($request, $organization, $attributes): Questionnaire {
            $questionnaire = Questionnaire::query()->create($attributes + ['organization_id' => $organization->id, 'created_by' => $request->user()->id]);

            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => Questionnaire::class,
                'entity_id' => $questionnaire->id,
                'action' => AuditLog::QuestionnaireCreated,
                'after_json' => ['changed_fields' => array_keys($attributes)],
            ]);
            app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);

            return $questionnaire;
        });

        return to_route('questionnaires.show', $questionnaire)->with('status', '質問票を登録しました。');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Questionnaire $questionnaire): View
    {
        $questionnaire = $this->scopedQuestionnaire($this->organization($request), $questionnaire);
        $this->authorize('view', $questionnaire);
        $questionnaire->load(['questions' => fn ($query) => $query->with('answers')->orderBy('sort_order')]);

        return view('questionnaires.show', compact('questionnaire'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Questionnaire $questionnaire): View
    {
        $questionnaire = $this->scopedQuestionnaire($this->organization($request), $questionnaire);
        $this->authorize('update', $questionnaire);

        return view('questionnaires.form', ['questionnaire' => $questionnaire, 'formAction' => route('questionnaires.update', $questionnaire), 'formMethod' => 'PUT']);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateQuestionnaireRequest $request, Questionnaire $questionnaire): RedirectResponse
    {
        $organization = $this->organization($request);
        $questionnaire = $this->scopedQuestionnaire($organization, $questionnaire);
        $this->authorize('update', $questionnaire);
        $attributes = $request->validated();

        DB::transaction(function () use ($request, $organization, $questionnaire, $attributes): void {
            $questionnaire->fill($attributes);
            $changedFields = array_keys($questionnaire->getDirty());
            $questionnaire->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => Questionnaire::class,
                    'entity_id' => $questionnaire->id,
                    'action' => AuditLog::QuestionnaireUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
                app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
            }
        });

        return to_route('questionnaires.show', $questionnaire)->with('status', '質問票を更新しました。');
    }

    /**
     * Remove the specified resource from storage.
     */
    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }

    private function scopedQuestionnaire(Organization $organization, Questionnaire $questionnaire): Questionnaire
    {
        abort_unless($questionnaire->organization_id === $organization->id, 404);

        return $questionnaire;
    }
}
