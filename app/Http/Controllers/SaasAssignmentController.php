<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaasAssignment\StoreSaasAssignmentRequest;
use App\Http\Requests\SaasAssignment\UpdateSaasAssignmentRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Services\ProductEventRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SaasAssignmentController extends Controller
{
    public function store(StoreSaasAssignmentRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $attributes = $request->validated();
        $service = SaasService::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($attributes['saas_service_id']);

        DB::transaction(function () use ($request, $organization, $attributes): void {
            $this->lockEmployeeForAssignment($attributes['employee_id'] ?? null, $organization);
            $assignment = SaasAssignment::query()->create($attributes + ['organization_id' => $organization->id]);

            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => SaasAssignment::class,
                'entity_id' => $assignment->id,
                'action' => AuditLog::SaasAssignmentCreated,
                'after_json' => ['changed_fields' => array_keys($attributes)],
            ]);
        });

        app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);

        return to_route('saas-services.show', $service)->with('status', '利用者を登録しました。');
    }

    public function edit(Request $request, SaasAssignment $saasAssignment): View
    {
        $organization = $this->organization($request);
        $saasAssignment = $this->scopedAssignment($organization, $saasAssignment);
        $this->authorize('update', $saasAssignment);

        return view('saas-assignments.form', [
            'assignment' => $saasAssignment,
            'service' => $this->serviceForAssignment($organization, $saasAssignment),
            'employees' => $this->employeesFor($organization, $saasAssignment->employee_id),
            'formAction' => route('saas-assignments.update', $saasAssignment),
            'formMethod' => 'PUT',
        ]);
    }

    public function update(UpdateSaasAssignmentRequest $request, SaasAssignment $saasAssignment): RedirectResponse
    {
        $organization = $this->organization($request);
        $saasAssignment = $this->scopedAssignment($organization, $saasAssignment);
        $this->authorize('update', $saasAssignment);
        $attributes = $request->validated();

        $saasAssignment = DB::transaction(function () use ($request, $organization, $saasAssignment, $attributes): SaasAssignment {
            $saasAssignment = SaasAssignment::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($saasAssignment->id);
            $this->lockEmployeeForAssignment(
                $attributes['employee_id'] ?? null,
                $organization,
                $saasAssignment->employee_id,
            );
            $saasAssignment->fill($attributes);
            $changedFields = array_keys($saasAssignment->getDirty());
            $saasAssignment->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => SaasAssignment::class,
                    'entity_id' => $saasAssignment->id,
                    'action' => AuditLog::SaasAssignmentUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
                app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
            }

            return $saasAssignment;
        });

        return to_route('saas-services.show', $this->serviceForAssignment($organization, $saasAssignment))
            ->with('status', '利用者の情報を更新しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }

    private function scopedAssignment(Organization $organization, SaasAssignment $saasAssignment): SaasAssignment
    {
        abort_unless($saasAssignment->organization_id === $organization->id, 404);

        return $saasAssignment;
    }

    private function serviceForAssignment(Organization $organization, SaasAssignment $saasAssignment): SaasService
    {
        return SaasService::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($saasAssignment->saas_service_id);
    }

    /** @return Collection<int, Employee> */
    private function employeesFor(Organization $organization, ?int $currentEmployeeId = null): Collection
    {
        return Employee::query()
            ->where('organization_id', $organization->id)
            ->where(function ($query) use ($currentEmployeeId): void {
                $query->where('employment_status', config('opsdeck.employee_status.active'));
                if ($currentEmployeeId !== null) {
                    $query->orWhereKey($currentEmployeeId);
                }
            })
            ->orderBy('name')
            ->get();
    }

    private function lockEmployeeForAssignment(?int $employeeId, Organization $organization, ?int $existingEmployeeId = null): ?Employee
    {
        if ($employeeId === null) {
            return null;
        }

        $employee = Employee::query()
            ->where('organization_id', $organization->id)
            ->lockForUpdate()
            ->find($employeeId);

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => '選択した社員を利用できません。']);
        }

        if ($employee->employment_status !== config('opsdeck.employee_status.active')
            && $employee->id !== $existingEmployeeId) {
            throw ValidationException::withMessages(['employee_id' => '在籍中の社員のみ新しく利用者に登録できます。']);
        }

        return $employee;
    }
}
