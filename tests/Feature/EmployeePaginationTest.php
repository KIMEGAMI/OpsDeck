<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeePaginationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_employee_screens_show_human_readable_japanese_status_labels(): void
    {
        [$user, $organization] = $this->ownerOrganization();
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'テスト社員',
            'employment_status' => 'leave',
        ]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)
            ->withSession($session)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('休職中');

        $this->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('休職中');

        $this->get(route('employees.create'))
            ->assertOk()
            ->assertSee('在籍中')
            ->assertSee('入社予定')
            ->assertSee('退職済み');
    }

    public function test_invalid_employee_status_and_department_values_fall_back_safely(): void
    {
        [$user, $organization] = $this->ownerOrganization();
        $employeeName = '社員状態確認';
        $defaultStatus = config('opsdeck.employee_statuses')[0];

        $this->from(route('employees.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('employees.store'), [
                'name' => $employeeName,
                'employment_status' => 'not-a-configured-status',
                'department_id' => ['malformed'],
            ])
            ->assertOk()
            ->assertSee('id="employment_status-error" class="errors field-error"', false)
            ->assertSee('id="department_id-error" class="errors field-error"', false)
            ->assertSee('<option value="'.$defaultStatus.'" selected>'.config('opsdeck.employee_status_labels.'.$defaultStatus).'</option>', false)
            ->assertSee('<option value="" selected>未設定</option>', false);

        $this->assertDatabaseMissing('employees', [
            'organization_id' => $organization->id,
            'name' => $employeeName,
        ]);
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
