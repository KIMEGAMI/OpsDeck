<?php

namespace Tests\Feature\Feature\Department;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepartmentAuthorizationTest extends TestCase
{
    public function test_viewer_cannot_create_department_and_operator_cannot_edit_other_organization_department(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id])
            ->post('/departments', ['name' => '管理部', 'is_active' => true])
            ->assertForbidden();

        [$operator, $operatorOrganization] = $this->member(MembershipRole::Operator);
        $otherDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Other Department '.Str::random(config('opsdeck.slug_suffix_length')),
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->withSession(['current_organization_id' => $operatorOrganization->id])
            ->get(route('departments.edit', $otherDepartment))
            ->assertNotFound();
    }

    public function test_viewer_cannot_update_a_department(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Finance '.Str::random(config('opsdeck.slug_suffix_length')),
            'is_active' => true,
        ]);

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('departments.update', $department), [
                'name' => 'Changed Department',
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertNotSame('Changed Department', $department->refresh()->name);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::DepartmentUpdated]);
    }

    public function test_department_update_records_changed_field_names_without_department_values(): void
    {
        [$operator, $organization] = $this->member(MembershipRole::Operator);
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Original Department',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('departments.update', $department), [
                'name' => 'Changed Department',
                'is_active' => true,
            ])
            ->assertRedirect();

        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $department->id)->where('action', AuditLog::DepartmentUpdated)->sole();

        $this->assertSame($operator->id, $auditLog->actor_user_id);
        $this->assertSame($organization->id, $auditLog->organization_id);
        $this->assertSame($department->id, $auditLog->entity_id);
        $this->assertSame(['changed_fields' => ['name']], $auditLog->after_json);
        $this->assertStringNotContainsString('Changed Department', json_encode($auditLog->toArray()));
    }

    public function test_operator_cannot_update_a_department_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$operator, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $department = Department::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Department '.Str::random(config('opsdeck.slug_suffix_length')),
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('departments.update', $department), [
                'name' => 'Changed Department',
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->assertNotSame('Changed Department', $department->refresh()->name);
    }

    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'department-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Department Organization '.$suffix,
            'slug' => 'department-organization-'.$suffix,
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }
}
