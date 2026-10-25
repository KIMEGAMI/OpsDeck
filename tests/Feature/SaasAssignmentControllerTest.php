<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

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
            ->assertSessionHas('status', '利用者を登録しました。');

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
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
        $auditLog = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('action', AuditLog::SaasAssignmentCreated)
            ->sole();
        $this->assertSame(['changed_fields' => ['saas_service_id', 'employee_id', 'account_identifier', 'license_name', 'status', 'mfa_status', 'assigned_at']], $auditLog->after_json);
        $this->assertStringNotContainsString('user@example.test', json_encode($auditLog->toArray(), JSON_THROW_ON_ERROR));
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

    public function test_assignment_rejects_a_retired_employee(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $service = $this->service($organization);
        $employee = $this->employee($organization);
        $employee->update(['employment_status' => config('opsdeck.employee_status.retired')]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-assignments.store'), [
                'saas_service_id' => $service->id,
                'employee_id' => $employee->id,
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
            ])
            ->assertSessionHasErrors(['employee_id' => '在籍中の社員のみ新しく利用者に登録できます。']);

        $this->assertDatabaseMissing('saas_assignments', [
            'organization_id' => $organization->id,
            'saas_service_id' => $service->id,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_existing_retired_employee_assignment_can_be_ended_but_not_reassigned_to_another_retired_employee(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $assignment = $this->assignment($organization);
        $formerEmployee = $assignment->employee()->firstOrFail();
        $formerEmployee->update(['employment_status' => config('opsdeck.employee_status.retired')]);
        $otherFormerEmployee = $this->employee($organization);
        $otherFormerEmployee->update(['employment_status' => config('opsdeck.employee_status.retired')]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('saas-assignments.edit', $assignment))
            ->assertOk()
            ->assertSee($formerEmployee->name.'（退職済み・終了処理用）');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-assignments.update', $assignment), [
                'employee_id' => $formerEmployee->id,
                'status' => 'ended',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
                'ended_at' => '2026-10-24',
            ])
            ->assertRedirect();

        $this->assertSame('2026-10-24', $assignment->refresh()->ended_at?->toDateString());

        $this->put(route('saas-assignments.update', $assignment), [
            'employee_id' => $otherFormerEmployee->id,
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
        ])->assertSessionHasErrors(['employee_id' => '在籍中の社員のみ新しく利用者に登録できます。']);

        $this->assertSame($formerEmployee->id, $assignment->refresh()->employee_id);
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

    public function test_invalid_new_assignment_date_is_shown_next_to_field_and_preserved(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $service = $this->service($organization);

        $this->from(route('saas-services.show', $service))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-assignments.store'), [
                'saas_service_id' => $service->id,
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
                'assigned_at' => 'not-a-date',
            ])
            ->assertOk()
            ->assertSee('id="assigned_at-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="assigned_at-error"', false)
            ->assertSee('value="not-a-date"', false);

        $this->assertDatabaseMissing('saas_assignments', [
            'organization_id' => $organization->id,
            'saas_service_id' => $service->id,
        ]);
    }

    public function test_invalid_assignment_date_is_shown_next_to_field_and_preserved(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $assignment = $this->assignment($organization);

        $this->from(route('saas-assignments.edit', $assignment))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-assignments.update', $assignment), [
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
                'ended_at' => 'not-a-date',
            ])
            ->assertOk()
            ->assertSee('id="ended_at-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="ended_at-error"', false)
            ->assertSee('value="not-a-date"', false);

        $this->assertNull($assignment->refresh()->ended_at);
    }

    public function test_invalid_mfa_status_falls_back_to_unknown_instead_of_appearing_enabled(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $assignment = $this->assignment($organization);

        $this->from(route('saas-assignments.edit', $assignment))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-assignments.update', $assignment), [
                'status' => 'active',
                'mfa_status' => 'unverified',
            ])
            ->assertOk()
            ->assertSee('id="mfa_status-error" class="errors field-error"', false)
            ->assertSee('aria-describedby="mfa_status-error"', false)
            ->assertSee('<option value="unknown" selected>未確認</option>', false);

        $this->assertSame(config('opsdeck.saas_assignment_mfa_status.unknown'), $assignment->refresh()->mfa_status);
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
            ->assertSessionHas('status', '利用者の情報を更新しました。');

        $assignment->refresh();
        $this->assertSame('updated@example.test', $assignment->account_identifier);
        $this->assertSame('ended', $assignment->status);
        $this->assertSame('enabled', $assignment->mfa_status);
        $this->assertSame('2026-09-22', $assignment->ended_at?->toDateString());
        $auditLog = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('entity_type', SaasAssignment::class)
            ->where('entity_id', $assignment->id)
            ->where('action', AuditLog::SaasAssignmentUpdated)
            ->sole();
        $this->assertSame(['changed_fields' => ['account_identifier', 'license_name', 'status', 'mfa_status', 'assigned_at', 'ended_at']], $auditLog->after_json);
        $this->assertStringNotContainsString('updated@example.test', json_encode($auditLog->toArray(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
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
        $assignment = $this->assignment($organization, $service, $employee);
        $this->assignment($organization, $service, $employee)->update([
            'status' => 'ended',
            'mfa_status' => 'enabled',
            'ended_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('saas-services.show', $service))
            ->assertOk()
            ->assertSee('利用者を登録する')
            ->assertSee($employee->name)
            ->assertSee('利用中')
            ->assertSee('利用終了')
            ->assertSee('未確認')
            ->assertSee('有効');

        $this->get(route('saas-assignments.edit', $assignment))
            ->assertOk()
            ->assertSee('ログイン時の追加確認の状態')
            ->assertSee('分からない場合は「未確認」を選んでください。')
            ->assertSee('候補から「利用中」または「利用終了」を選べます。候補にない既存の状態も編集できます。')
            ->assertSee('対象外');
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
