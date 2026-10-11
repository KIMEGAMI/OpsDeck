<?php

namespace App\Http\Controllers;

use App\Http\Requests\Questionnaire\StoreQuestionnaireRequest;
use App\Http\Requests\Questionnaire\UpdateQuestionnaireRequest;
use App\Models\Organization;
use App\Models\Questionnaire;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $questionnaires = Questionnaire::query()->where('organization_id', $organization->id)->orderBy('due_at')->orderBy('id')->paginate(config('opsdeck.pagination.per_page'));

        return view('questionnaires.index', compact('questionnaires', 'organization'));
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
        $questionnaire = Questionnaire::query()->create($request->validated() + ['organization_id' => $organization->id, 'created_by' => $request->user()->id]);

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
        $questionnaire = $this->scopedQuestionnaire($this->organization($request), $questionnaire);
        $this->authorize('update', $questionnaire);
        $questionnaire->update($request->validated());

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
