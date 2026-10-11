<?php

namespace App\Http\Controllers;

use App\Http\Requests\Contract\StoreContractRequest;
use App\Http\Requests\Contract\UpdateContractRequest;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\SaasService;
use App\Services\OnboardingProgressService;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContractController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $contracts = Contract::query()->with(['service', 'owner'])->where('organization_id', $organization->id)->orderBy('ends_at')->orderBy('id')->paginate(config('opsdeck.pagination.per_page'));

        return view('contracts.index', compact('contracts', 'organization'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        return view('contracts.form', $this->formData($organization, new Contract(['status' => config('opsdeck.contract_status.draft')]), route('contracts.store'), 'POST'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreContractRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $contract = Contract::query()->create($this->validatedForOrganization($request->validated(), $organization) + ['organization_id' => $organization->id]);
        app(ProductEventRecorder::class)->recordOnce('first_contract_registered', $organization);
        app(OnboardingProgressService::class)->recordCompletionIfReady($organization);

        return to_route('contracts.show', $contract)->with('status', '契約を登録しました。');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Contract $contract): View
    {
        $contract = $this->scopedContract($this->organization($request), $contract);
        $this->authorize('view', $contract);
        $contract->load(['service', 'owner']);

        return view('contracts.show', compact('contract'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Contract $contract): View
    {
        $organization = $this->organization($request);
        $contract = $this->scopedContract($organization, $contract);
        $this->authorize('update', $contract);

        return view('contracts.form', $this->formData($organization, $contract, route('contracts.update', $contract), 'PUT'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateContractRequest $request, Contract $contract): RedirectResponse
    {
        $organization = $this->organization($request);
        $contract = $this->scopedContract($organization, $contract);
        $this->authorize('update', $contract);
        $contract->update($this->validatedForOrganization($request->validated(), $organization));

        return to_route('contracts.show', $contract)->with('status', '契約を更新しました。');
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

    private function scopedContract(Organization $organization, Contract $contract): Contract
    {
        abort_unless($contract->organization_id === $organization->id, 404);

        return $contract;
    }

    private function formData(Organization $organization, Contract $contract, string $formAction, string $formMethod): array
    {
        return [
            'contract' => $contract,
            'services' => SaasService::query()->where('organization_id', $organization->id)->orderBy('name')->get(),
            'employees' => Employee::query()->where('organization_id', $organization->id)->orderBy('name')->get(),
            'formAction' => $formAction,
            'formMethod' => $formMethod,
        ];
    }

    private function validatedForOrganization(array $attributes, Organization $organization): array
    {
        if (isset($attributes['saas_service_id']) && ! SaasService::query()->where('organization_id', $organization->id)->whereKey($attributes['saas_service_id'])->exists()) {
            throw ValidationException::withMessages(['saas_service_id' => '選択したSaaSを利用できません。']);
        }

        if (isset($attributes['owner_employee_id']) && ! Employee::query()->where('organization_id', $organization->id)->whereKey($attributes['owner_employee_id'])->exists()) {
            throw ValidationException::withMessages(['owner_employee_id' => '選択した担当社員を利用できません。']);
        }

        return $attributes;
    }
}
