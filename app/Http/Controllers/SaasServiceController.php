<?php

namespace App\Http\Controllers;

use App\Http\Requests\Saas\StoreSaasServiceRequest;
use App\Http\Requests\Saas\UpdateSaasServiceRequest;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\SaasService;
use App\Services\OnboardingProgressService;
use App\Services\PlanLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SaasServiceController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $services = SaasService::query()->where('organization_id', $organization->id)->orderBy('name')->orderBy('id')->paginate(config('opsdeck.pagination.per_page'));

        return view('saas-services.index', compact('services', 'organization'));
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        return view('saas-services.form', ['service' => new SaasService(['status' => config('opsdeck.saas_service_statuses')[0]]), 'formAction' => route('saas-services.store'), 'formMethod' => 'POST']);
    }

    public function store(StoreSaasServiceRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $service = DB::transaction(function () use ($request, $organization): SaasService {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            app(PlanLimitService::class)->assertCanAdd($organization, 'saas_services');

            return SaasService::query()->create($this->uniqueAttributes($request->validated(), $organization) + ['organization_id' => $organization->id]);
        });
        app(OnboardingProgressService::class)->recordCompletionIfReady($organization);

        return to_route('saas-services.show', $service)->with('status', 'SaaSを登録しました。');
    }

    public function show(Request $request, SaasService $saasService): View
    {
        $saasService = $this->scopedService($this->organization($request), $saasService);
        $this->authorize('view', $saasService);
        $saasService->load(['assignments' => fn ($query) => $query->with('employee')->latest('assigned_at')]);
        $employees = $request->user()->can('update', $saasService)
            ? Employee::query()
                ->where('organization_id', $saasService->organization_id)
                ->orderBy('name')
                ->get()
            : collect();

        return view('saas-services.show', ['service' => $saasService, 'employees' => $employees]);
    }

    public function edit(Request $request, SaasService $saasService): View
    {
        $organization = $this->organization($request);
        $saasService = $this->scopedService($organization, $saasService);
        $this->authorize('update', $saasService);

        return view('saas-services.form', ['service' => $saasService, 'formAction' => route('saas-services.update', $saasService), 'formMethod' => 'PUT']);
    }

    public function update(UpdateSaasServiceRequest $request, SaasService $saasService): RedirectResponse
    {
        $organization = $this->organization($request);
        $saasService = $this->scopedService($organization, $saasService);
        $this->authorize('update', $saasService);
        $saasService->update($this->uniqueAttributes($request->validated(), $organization, $saasService));

        return to_route('saas-services.show', $saasService)->with('status', 'SaaSを更新しました。');
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('currentOrganization');
    }

    private function scopedService(Organization $organization, SaasService $saasService): SaasService
    {
        abort_unless($saasService->organization_id === $organization->id, 404);

        return $saasService;
    }

    private function uniqueAttributes(array $attributes, Organization $organization, ?SaasService $saasService = null): array
    {
        if (SaasService::query()->where('organization_id', $organization->id)->where('name', $attributes['name'])->when($saasService, fn ($query) => $query->whereKeyNot($saasService->id))->exists()) {
            throw ValidationException::withMessages(['name' => '同じ名前のSaaSが既に存在します。']);
        }

        return $attributes;
    }
}
