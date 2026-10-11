<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaasAssignment\StoreSaasAssignmentRequest;
use App\Http\Requests\SaasAssignment\UpdateSaasAssignmentRequest;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $this->validateEmployee($attributes, $organization);

        SaasAssignment::query()->create($attributes + ['organization_id' => $organization->id]);

        return to_route('saas-services.show', $service)->with('status', 'SaaSアカウントを割り当てました。');
    }

    public function edit(Request $request, SaasAssignment $saasAssignment): View
    {
        $organization = $this->organization($request);
        $saasAssignment = $this->scopedAssignment($organization, $saasAssignment);
        $this->authorize('update', $saasAssignment);

        return view('saas-assignments.form', [
            'assignment' => $saasAssignment,
            'service' => $this->serviceForAssignment($organization, $saasAssignment),
            'employees' => $this->employeesFor($organization),
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

        $this->validateEmployee($attributes, $organization);
        $saasAssignment->update($attributes);

        return to_route('saas-services.show', $this->serviceForAssignment($organization, $saasAssignment))
            ->with('status', 'SaaSアカウントの割当を更新しました。');
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
    private function employeesFor(Organization $organization): Collection
    {
        return Employee::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get();
    }

    private function validateEmployee(array $attributes, Organization $organization): void
    {
        if (isset($attributes['employee_id']) && ! Employee::query()
            ->where('organization_id', $organization->id)
            ->whereKey($attributes['employee_id'])
            ->exists()) {
            throw ValidationException::withMessages(['employee_id' => '選択した社員を利用できません。']);
        }
    }
}
