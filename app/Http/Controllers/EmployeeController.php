<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Services\PlanFeatureService;
use App\Services\PlanLimitService;
use App\Services\ProductEventRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:'.config('opsdeck.validation.name_max_length')]]);

        $employees = Employee::query()
            ->with('department')
            ->where('organization_id', $organization->id)
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('employee_number', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(config('opsdeck.pagination.per_page'))
            ->withQueryString();

        return view('employees.index', compact('employees', 'filters', 'organization'));
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        return view('employees.form', [
            'employee' => new Employee(['employment_status' => config('opsdeck.employee_statuses')[0]]),
            'departments' => $this->departmentsFor($organization),
            'formAction' => route('employees.store'),
            'formMethod' => 'POST',
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $employee = DB::transaction(function () use ($request, $organization): Employee {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            app(PlanLimitService::class)->assertCanAdd($organization, 'employees');
            $attributes = $this->validatedForOrganization($request->validated(), $organization);
            $employee = Employee::query()->create($attributes + ['organization_id' => $organization->id]);
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => Employee::class,
                'entity_id' => $employee->id,
                'action' => AuditLog::EmployeeCreated,
                'after_json' => ['created_fields' => array_keys($attributes)],
            ]);

            return $employee;
        });
        app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);

        return to_route('employees.show', $employee)->with('status', '社員情報を登録しました。');
    }

    public function show(Request $request, Employee $employee): View
    {
        $organization = $this->organization($request);
        $employee = $this->scopedEmployee($request, $employee);
        $this->authorize('view', $employee);
        $employee->load('department');
        $departureChecklist = [];
        $onboardingChecklist = [];

        if ($employee->employment_status === config('opsdeck.employee_status.active') && $employee->left_at === null) {
            $organizationId = $organization->id;
            $assignedAssets = AssetAssignment::query()
                ->with('asset')
                ->where('organization_id', $organizationId)
                ->where('employee_id', $employee->id)
                ->whereNull('returned_at')
                ->get();
            $activeServices = SaasService::query()
                ->with(['assignments' => fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('employee_id', $employee->id)
                    ->whereNull('ended_at')])
                ->where('organization_id', $organizationId)
                ->where('status', config('opsdeck.saas_service_active_status'))
                ->orderBy('name')
                ->get();

            $onboardingChecklist = [
                'assets' => $assignedAssets,
                'availableAssetsUrl' => route('assets.index', [
                    'statuses' => [config('opsdeck.asset_status.in_stock')],
                    'unassigned' => true,
                    'employee_id' => $employee->id,
                ]),
                'services' => $activeServices,
            ];
        }

        if ($employee->employment_status === config('opsdeck.employee_status.retired') || $employee->left_at !== null) {
            $organizationId = $organization->id;
            $openAssetAssignments = AssetAssignment::query()
                ->with('asset')
                ->where('organization_id', $organizationId)
                ->where('employee_id', $employee->id)
                ->whereNull('returned_at')
                ->get();
            $openSaasAssignments = SaasAssignment::query()
                ->with('service')
                ->where('organization_id', $organizationId)
                ->where('employee_id', $employee->id)
                ->whereNull('ended_at')
                ->get();
            $ownedContracts = Contract::query()
                ->with('service')
                ->where('organization_id', $organizationId)
                ->where('owner_employee_id', $employee->id)
                ->whereIn('status', [config('opsdeck.contract_status.active'), config('opsdeck.contract_status.pending_cancel')])
                ->orderBy('contract_name')
                ->get();
            $normalizedEmployeeEmail = is_string($employee->email) ? mb_strtolower(trim($employee->email)) : '';
            $organizationMemberships = $normalizedEmployeeEmail === ''
                ? collect()
                : OrganizationMembership::query()
                    ->with('user')
                    ->where('organization_id', $organizationId)
                    ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])
                    ->whereHas('user', function (Builder $query) use ($normalizedEmployeeEmail): void {
                        $query->whereRaw('LOWER(email) = ?', [$normalizedEmployeeEmail]);
                    })
                    ->orderBy('id')
                    ->get();
            $pendingOrganizationInvitations = $normalizedEmployeeEmail === ''
                ? collect()
                : OrganizationInvitation::query()
                    ->where('organization_id', $organizationId)
                    ->whereRaw('LOWER(email) = ?', [$normalizedEmployeeEmail])
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->orderBy('id')
                    ->get();

            $departureChecklist = [
                ['title' => '貸与端末の返却', 'type' => 'assets', 'records' => $openAssetAssignments],
                ['title' => 'クラウドサービスの利用終了', 'type' => 'saas', 'records' => $openSaasAssignments],
                ['title' => '有効な契約の担当者引き継ぎ', 'type' => 'contracts', 'records' => $ownedContracts],
            ];

            if (app(PlanFeatureService::class)->isAvailable($organization, 'security_controls')) {
                $ownedSecurityControls = SecurityControl::query()
                    ->where('organization_id', $organizationId)
                    ->where('owner_employee_id', $employee->id)
                    ->where('status', '!=', 'not_applicable')
                    ->orderBy('control_code')
                    ->get();
                $departureChecklist[] = ['title' => 'セキュリティ管理項目の担当者引き継ぎ', 'type' => 'security_controls', 'records' => $ownedSecurityControls];
            }

            $departureChecklist[] = ['title' => 'OpsDeck利用者の権限確認', 'type' => 'memberships', 'records' => $organizationMemberships];
            $departureChecklist[] = ['title' => '未承諾のOpsDeck招待の取り消し', 'type' => 'invitations', 'records' => $pendingOrganizationInvitations];
        }

        return view('employees.show', compact('organization', 'employee', 'departureChecklist', 'onboardingChecklist'));
    }

    public function edit(Request $request, Employee $employee): View
    {
        $organization = $this->organization($request);
        $employee = $this->scopedEmployee($request, $employee);
        $this->authorize('update', $employee);

        return view('employees.form', [
            'employee' => $employee,
            'departments' => $this->departmentsFor($organization),
            'formAction' => route('employees.update', $employee),
            'formMethod' => 'PUT',
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $organization = $this->organization($request);
        $employee = $this->scopedEmployee($request, $employee);
        $this->authorize('update', $employee);
        $attributes = $this->validatedForOrganization($request->validated(), $organization);

        $employee = DB::transaction(function () use ($request, $organization, $employee, $attributes): Employee {
            $employee = Employee::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($employee->id);
            $this->authorize('update', $employee);
            $employee->fill($attributes);
            $changedFields = array_keys($employee->getDirty());
            $employee->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => Employee::class,
                    'entity_id' => $employee->id,
                    'action' => AuditLog::EmployeeUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
                app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
            }

            return $employee;
        });

        return to_route('employees.show', $employee)->with('status', '社員情報を更新しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }

    private function scopedEmployee(Request $request, Employee $employee): Employee
    {
        if ($employee->organization_id !== $this->organization($request)->id) {
            abort(404);
        }

        return $employee;
    }

    private function departmentsFor(Organization $organization)
    {
        return Department::query()->where('organization_id', $organization->id)->where('is_active', true)->orderBy('name')->get();
    }

    private function validatedForOrganization(array $attributes, Organization $organization): array
    {
        if (isset($attributes['department_id']) && ! Department::query()->where('organization_id', $organization->id)->whereKey($attributes['department_id'])->exists()) {
            throw ValidationException::withMessages(['department_id' => '選択した部署を利用できません。']);
        }

        return $attributes;
    }
}
