<?php

namespace App\Http\Controllers;

use App\Http\Requests\SecurityControl\StoreSecurityControlRequest;
use App\Http\Requests\SecurityControl\UpdateSecurityControlRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\SecurityControl;
use App\Services\OnboardingProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SecurityControlController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $filters = $request->validate(['status' => ['nullable', Rule::in(config('opsdeck.security_control_statuses'))], 'attention' => ['nullable', 'boolean']]);
        $controls = SecurityControl::query()->with('owner')->where('organization_id', $organization->id)->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))->when($filters['attention'] ?? false, fn ($query) => $query->whereIn('status', ['partial', 'not_implemented', 'unknown']))->orderBy('control_code')->paginate(config('opsdeck.pagination.per_page'))->withQueryString();

        return view('security-controls.index', compact('controls', 'organization', 'filters'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        return view('security-controls.form', $this->formData($organization, new SecurityControl(['status' => config('opsdeck.security_control_status.unknown')]), route('security-controls.store'), 'POST'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSecurityControlRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $control = SecurityControl::query()->create($this->validatedForOrganization($request->validated(), $organization) + ['organization_id' => $organization->id]);
        app(OnboardingProgressService::class)->recordCompletionIfReady($organization);

        return to_route('security-controls.show', $control)->with('status', 'セキュリティ管理項目を登録しました。');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, SecurityControl $securityControl): View
    {
        $securityControl = $this->scopedControl($this->organization($request), $securityControl);
        $this->authorize('view', $securityControl);
        $securityControl->load('owner');

        return view('security-controls.show', ['control' => $securityControl]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, SecurityControl $securityControl): View
    {
        $organization = $this->organization($request);
        $securityControl = $this->scopedControl($organization, $securityControl);
        $this->authorize('update', $securityControl);

        return view('security-controls.form', $this->formData($organization, $securityControl, route('security-controls.update', $securityControl), 'PUT'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSecurityControlRequest $request, SecurityControl $securityControl): RedirectResponse
    {
        $organization = $this->organization($request);
        $securityControl = $this->scopedControl($organization, $securityControl);
        $this->authorize('update', $securityControl);
        $attributes = $this->validatedForOrganization($request->validated(), $organization);

        DB::transaction(function () use ($request, $organization, $securityControl, $attributes): void {
            $securityControl->fill($attributes);
            $changedFields = array_keys($securityControl->getDirty());
            $securityControl->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => SecurityControl::class,
                    'entity_id' => $securityControl->id,
                    'action' => AuditLog::SecurityControlUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
            }
        });

        return to_route('security-controls.show', $securityControl)->with('status', 'セキュリティ管理項目を更新しました。');
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

    private function scopedControl(Organization $organization, SecurityControl $securityControl): SecurityControl
    {
        abort_unless($securityControl->organization_id === $organization->id, 404);

        return $securityControl;
    }

    private function formData(Organization $organization, SecurityControl $control, string $formAction, string $formMethod): array
    {
        return ['control' => $control, 'employees' => Employee::query()->where('organization_id', $organization->id)->orderBy('name')->get(), 'formAction' => $formAction, 'formMethod' => $formMethod];
    }

    private function validatedForOrganization(array $attributes, Organization $organization): array
    {
        if (isset($attributes['owner_employee_id']) && ! Employee::query()->where('organization_id', $organization->id)->whereKey($attributes['owner_employee_id'])->exists()) {
            throw ValidationException::withMessages(['owner_employee_id' => '選択した担当社員を利用できません。']);
        }

        return $attributes;
    }
}
