<?php

namespace App\Http\Controllers;

use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $departments = Department::query()->with('parent')->where('organization_id', $organization->id)->orderBy('name')->paginate(config('opsdeck.pagination.per_page'));

        return view('departments.index', compact('departments', 'organization'));
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        return view('departments.form', $this->formData($organization, new Department(['is_active' => true]), route('departments.store'), 'POST'));
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        Department::query()->create($this->validatedForOrganization($request->validated(), $organization) + ['organization_id' => $organization->id]);

        return to_route('departments.index')->with('status', '部署を登録しました。');
    }

    public function edit(Request $request, Department $department): View
    {
        $organization = $this->organization($request);
        $department = $this->scopedDepartment($organization, $department);
        $this->authorize('update', $department);

        return view('departments.form', $this->formData($organization, $department, route('departments.update', $department), 'PUT'));
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $organization = $this->organization($request);
        $department = $this->scopedDepartment($organization, $department);
        $this->authorize('update', $department);
        $attributes = $this->validatedForOrganization($request->validated(), $organization, $department);

        DB::transaction(function () use ($request, $organization, $department, $attributes): void {
            $department->fill($attributes);
            $changedFields = array_keys($department->getDirty());
            $department->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => Department::class,
                    'entity_id' => $department->id,
                    'action' => AuditLog::DepartmentUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
            }
        });

        return to_route('departments.index')->with('status', '部署を更新しました。');
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('currentOrganization');
    }

    private function scopedDepartment(Organization $organization, Department $department): Department
    {
        abort_unless($department->organization_id === $organization->id, 404);

        return $department;
    }

    private function formData(Organization $organization, Department $department, string $action, string $method): array
    {
        return [
            'department' => $department,
            'parents' => Department::query()->where('organization_id', $organization->id)->when($department->exists, fn ($query) => $query->whereKeyNot($department->id))->orderBy('name')->get(),
            'formAction' => $action,
            'formMethod' => $method,
        ];
    }

    private function validatedForOrganization(array $attributes, Organization $organization, ?Department $department = null): array
    {
        if (isset($attributes['parent_id']) && ! Department::query()->where('organization_id', $organization->id)->whereKey($attributes['parent_id'])->exists()) {
            throw ValidationException::withMessages(['parent_id' => '選択した親部署を利用できません。']);
        }

        if (Department::query()->where('organization_id', $organization->id)->where('name', $attributes['name'])->when($department, fn ($query) => $query->whereKeyNot($department->id))->exists()) {
            throw ValidationException::withMessages(['name' => '同じ名前の部署が既に存在します。']);
        }

        return $attributes;
    }
}
