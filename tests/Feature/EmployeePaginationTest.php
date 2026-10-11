<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeePaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_maximum_plan_size_is_paginated_with_stable_order_for_duplicate_names(): void
    {
        config(['opsdeck.pagination.per_page' => 100]);
        [$user, $organization] = $this->ownerOrganization();
        $employees = [];

        foreach (range(1, 200) as $number) {
            $employees[] = Employee::query()->create([
                'organization_id' => $organization->id,
                'employee_number' => sprintf('EMP-%03d', $number),
                'name' => '同姓同名',
                'employment_status' => config('opsdeck.employee_status.active'),
            ]);
        }

        $session = ['current_organization_id' => $organization->id];
        $firstPage = $this->actingAs($user)->withSession($session)->get(route('employees.index', ['page' => 1]));
        $secondPage = $this->actingAs($user)->withSession($session)->get(route('employees.index', ['page' => 2]));

        $firstPage->assertOk()
            ->assertSee('href="'.route('employees.show', $employees[0]).'"', false)
            ->assertSee('href="'.route('employees.show', $employees[99]).'"', false)
            ->assertDontSee('href="'.route('employees.show', $employees[100]).'"', false);
        $secondPage->assertOk()
            ->assertSee('href="'.route('employees.show', $employees[100]).'"', false)
            ->assertSee('href="'.route('employees.show', $employees[199]).'"', false)
            ->assertDontSee('href="'.route('employees.show', $employees[99]).'"', false);
    }

    /** @return array{0: User, 1: Organization} */
    private function ownerOrganization(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'employee-pagination-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Employee Pagination '.$suffix,
            'slug' => 'employee-pagination-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }
}
