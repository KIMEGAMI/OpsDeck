<?php

namespace App\Http\Controllers;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Services\PlanLimitService;
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

            return Employee::query()->create($this->validatedForOrganization($request->validated(), $organization) + ['organization_id' => $organization->id]);
        });

        return to_route('employees.show', $employee)->with('status', '社員情報を登録しました。');
    }

    public function show(Request $request, Employee $employee): View
    {
        $employee = $this->scopedEmployee($request, $employee);
        $this->authorize('view', $employee);
        $employee->load('department');

        return view('employees.show', compact('employee'));
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
        $employee->update($this->validatedForOrganization($request->validated(), $organization));

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
