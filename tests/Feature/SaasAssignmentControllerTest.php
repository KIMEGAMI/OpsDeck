<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasAssignmentControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_operator_can_assign_a_saas_account_to_an_employee(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $service = $this->service($organization);
        $employee = $this->employee($organization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-assignments.store'), [
                'saas_service_id' => $service->id,
                'employee_id' => $employee->id,
                'account_identifier' => 'user@example.test',
                'license_name' => 'Business',
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
                'assigned_at' => '2026-09-21',
            ])
            ->assertRedirectToRoute('saas-services.show', $service)
            ->assertSessionHas('status', 'SaaSアカウントを割り当てました。');

        $this->assertDatabaseHas('saas_assignments', [
            'organization_id' => $organization->id,
            'saas_service_id' => $service->id,
            'employee_id' => $employee->id,
            'account_identifier' => 'user@example.test',
            'license_name' => 'Business',
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
        ]);
        $this->assertSame(
            '2026-09-21',
            SaasAssignment::query()->where('saas_service_id', $service->id)->firstOrFail()->assigned_at?->toDateString(),
        );
    }

    public function test_viewer_cannot_create_a_saas_assignment(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $service = $this->service($organization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-assignments.store'), [
                'saas_service_id' => $service->id,
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('saas_assignments', [
            'organization_id' => $organization->id,
            'saas_service_id' => $service->id,
        ]);
    }

    public function test_assignment_rejects_an_undefined_mfa_status(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-assignments.store'), [
                'saas_service_id' => $this->service($organization)->id,
                'status' => 'active',
                'mfa_status' => 'unverified',
            ])
            ->assertSessionHasErrors(['mfa_status' => 'MFA状態が不正です。']);
    }

    public function test_assignment_rejects_an_end_date_before_the_assignment_date(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-assignments.store'), [
                'saas_service_id' => $this->service($organization)->id,
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
                'assigned_at' => '2026-09-21',
                'ended_at' => '2026-09-20',
            ])
            ->assertSessionHasErrors(['ended_at' => '終了日は割当日以降の日付を指定してください。']);
    }

    public function test_assignment_returns_404_for_a_service_in_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-assignments.store'), [
                'saas_service_id' => $this->service($otherOrganization)->id,
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
            ])
            ->assertNotFound();
    }

    public function test_operator_can_update_a_saas_assignment(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $assignment = $this->assignment($organization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-assignments.update', $assignment), [
                'employee_id' => $assignment->employee_id,
                'account_identifier' => 'updated@example.test',
                'license_name' => 'Business',
                'status' => 'ended',
                'mfa_status' => 'enabled',
                'assigned_at' => '2026-09-21',
                'ended_at' => '2026-09-22',
            ])
            ->assertRedirectToRoute('saas-services.show', ['saas_service' => $assignment->saas_service_id])
            ->assertSessionHas('status', 'SaaSアカウントの割当を更新しました。');

        $assignment->refresh();
        $this->assertSame('updated@example.test', $assignment->account_identifier);
        $this->assertSame('ended', $assignment->status);
        $this->assertSame('enabled', $assignment->mfa_status);
        $this->assertSame('2026-09-22', $assignment->ended_at?->toDateString());
    }

    public function test_viewer_cannot_update_a_saas_assignment(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $assignment = $this->assignment($organization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-assignments.update', $assignment), [
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
            ])
            ->assertForbidden();
    }

    public function test_assignment_edit_returns_404_for_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('saas-assignments.edit', $this->assignment($otherOrganization)))
            ->assertNotFound();
    }

    public function test_operator_cannot_update_an_assignment_in_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());
        $assignment = $this->assignment($otherOrganization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-assignments.update', $assignment), [
                'employee_id' => $assignment->employee_id,
                'account_identifier' => 'cross-organization-change@example.test',
                'license_name' => 'Business',
                'status' => 'ended',
                'mfa_status' => 'enabled',
                'assigned_at' => '2026-09-21',
                'ended_at' => '2026-09-22',
            ])
            ->assertNotFound();

        $this->assertNull($assignment->refresh()->account_identifier);
    }

    public function test_operator_sees_the_assignment_form_and_current_assignments(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $service = $this->service($organization);
        $employee = $this->employee($organization);
        $this->assignment($organization, $service, $employee);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('saas-services.show', $service))
            ->assertOk()
            ->assertSee('SaaSアカウントを割り当てる')
            ->assertSee($employee->name);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $user = User::factory()->create(['email' => 'saas-assignment-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create($this->organizationAttributes());
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }

    /** @return array{name: string, slug: string, timezone: string, plan_code: string, billing_status: string} */
    private function organizationAttributes(): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));

        return [
            'name' => 'Organization '.$suffix,
            'slug' => 'organization-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ];
    }

    private function service(Organization $organization): SaasService
    {
        return SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Service '.Str::random(config('opsdeck.slug_suffix_length')),
            'status' => config('opsdeck.saas_service_statuses')[0],
        ]);
    }

    private function employee(Organization $organization): Employee
    {
        return Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Employee '.Str::random(config('opsdeck.slug_suffix_length')),
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
    }

    private function assignment(Organization $organization, ?SaasService $service = null, ?Employee $employee = null): SaasAssignment
    {
        return SaasAssignment::query()->create([
            'organization_id' => $organization->id,
            'saas_service_id' => ($service ?? $this->service($organization))->id,
            'employee_id' => ($employee ?? $this->employee($organization))->id,
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
        ]);
    }
}
