<?php

namespace Tests\Feature\Feature\Employee;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeAuthorizationTest extends TestCase
{
    public function test_operator_cannot_create_employee_beyond_plan_limit(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Config::set('opsdeck.plans.free.limits.employees', 1);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '既存社員', 'employment_status' => 'active']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post('/employees', ['name' => '上限超過社員', 'employment_status' => 'active'])
            ->assertSessionHasErrors('plan_limit');

        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
    }

    public function test_operator_can_create_employee_only_in_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post('/employees', [
                'name' => '田中 太郎',
                'employment_status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'organization_id' => $organization->id,
            'name' => '田中 太郎',
        ]);
    }

    public function test_viewer_cannot_create_an_employee(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('employees.store'), ['name' => '閲覧者からの登録', 'employment_status' => 'active'])
            ->assertForbidden();

        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'name' => '閲覧者からの登録']);
    }

    public function test_viewer_cannot_update_an_employee(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '変更前の社員',
            'employment_status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('employees.update', $employee), ['name' => '変更後の社員', 'employment_status' => 'active'])
            ->assertForbidden();

        $this->assertSame('変更前の社員', $employee->refresh()->name);
    }

    public function test_operator_cannot_update_an_employee_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $employee = Employee::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => '別組織の社員',
            'employment_status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('employees.update', $employee), ['name' => '改ざん試行', 'employment_status' => 'active'])
            ->assertNotFound();

        $this->assertSame('別組織の社員', $employee->refresh()->name);
    }

    public function test_user_cannot_view_employee_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $otherEmployee = Employee::query()->create([
            'organization_id' => Organization::query()->create([
                'name' => 'Other Organization',
                'slug' => 'other-'.Str::lower(Str::random(config('opsdeck.slug_suffix_length'))),
                'timezone' => 'Asia/Tokyo',
                'plan_code' => config('opsdeck.default_plan_code'),
                'billing_status' => config('opsdeck.default_billing_status'),
            ])->id,
            'name' => 'Other Employee',
            'employment_status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $otherEmployee))
            ->assertNotFound();
    }

    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'user-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Organization '.$suffix,
            'slug' => 'organization-'.$suffix,
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
