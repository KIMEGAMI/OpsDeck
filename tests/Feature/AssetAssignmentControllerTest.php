<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetAssignmentControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_operator_cannot_create_asset_beyond_plan_limit(): void
    {
        config(['opsdeck.plans.free.limits.assets' => 1]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $this->asset($organization);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('assets.store'), [
                'asset_number' => 'over-limit-asset',
                'asset_type' => config('opsdeck.asset_types')[0],
                'status' => config('opsdeck.asset_status.in_stock'),
            ])
            ->assertSessionHasErrors('plan_limit');

        $this->assertSame(1, Asset::query()->where('organization_id', $organization->id)->count());
    }

    public function test_operator_can_assign_an_available_asset_to_an_active_employee(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $asset = $this->asset($organization);
        $employee = $this->employee($organization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('asset-assignments.store'), [
                'asset_id' => $asset->id,
                'employee_id' => $employee->id,
                'assigned_at' => '2026-09-21',
                'note' => '貸出メモ',
            ])
            ->assertRedirectToRoute('assets.show', $asset)
            ->assertSessionHas('status', '端末を割り当てました。');

        $this->assertDatabaseHas('asset_assignments', [
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'returned_at' => null,
            'note' => '貸出メモ',
        ]);
        $assignment = AssetAssignment::query()->where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame('2026-09-21', $assignment->assigned_at->toDateString());
        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'status' => config('opsdeck.asset_status.assigned'),
        ]);
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $assignment->id)->where('action', AuditLog::AssetAssignmentCreated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['changed_fields' => ['asset_id', 'employee_id', 'assigned_at']], $auditLog->after_json);
        $this->assertStringNotContainsString($employee->name, json_encode($auditLog->toArray()));
        $this->assertStringNotContainsString('貸出メモ', json_encode($auditLog->toArray()));
    }

    public function test_assignment_rejects_an_asset_that_is_already_assigned(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $asset = $this->asset($organization);
        $employee = $this->employee($organization);
        AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'assigned_at' => '2026-09-20',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('asset-assignments.store'), [
                'asset_id' => $asset->id,
                'employee_id' => $employee->id,
                'assigned_at' => '2026-09-21',
            ])
            ->assertSessionHasErrors(['asset_id' => 'この端末は既に割り当て済みです。']);
    }

    public function test_return_rejects_a_date_before_the_assignment_date(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $asset = $this->asset($organization, config('opsdeck.asset_status.assigned'));
        $assignment = AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $this->employee($organization)->id,
            'assigned_at' => '2026-09-21',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('asset-assignments.update', $assignment), ['returned_at' => '2026-09-20'])
            ->assertSessionHasErrors(['returned_at' => '返却日は割当日以降の日付を指定してください。']);
    }

    public function test_assignment_rejects_an_employee_who_is_not_active(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('asset-assignments.store'), [
                'asset_id' => $this->asset($organization)->id,
                'employee_id' => $this->employee($organization, config('opsdeck.employee_status.scheduled'))->id,
                'assigned_at' => '2026-09-21',
            ])
            ->assertSessionHasErrors(['employee_id' => '在籍中の社員のみ端末を割り当てられます。']);
    }

    public function test_operator_sees_the_assignment_form_with_active_employees(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $asset = $this->asset($organization);
        $employee = $this->employee($organization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('assets.show', $asset))
            ->assertOk()
            ->assertSee('端末を貸し出す')
            ->assertSee($employee->name);
    }

    public function test_operator_can_record_a_return_and_restore_asset_availability(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $asset = $this->asset($organization, config('opsdeck.asset_status.assigned'));
        $assignment = AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $this->employee($organization)->id,
            'assigned_at' => '2026-09-21',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('asset-assignments.update', $assignment), [
                'returned_at' => '2026-09-22',
                'note' => '返却済み',
            ])
            ->assertRedirectToRoute('assets.show', $asset)
            ->assertSessionHas('status', '端末の返却を記録しました。');

        $assignment->refresh();
        $this->assertSame('2026-09-22', $assignment->returned_at?->toDateString());
        $this->assertSame('返却済み', $assignment->note);
        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'status' => config('opsdeck.asset_status.in_stock'),
        ]);
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $assignment->id)->where('action', AuditLog::AssetAssignmentReturned)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['changed_fields' => ['returned_at', 'note']], $auditLog->after_json);
        $this->assertStringNotContainsString('返却済み', json_encode($auditLog->toArray()));
    }

    public function test_assignment_returns_404_for_an_asset_in_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());
        $otherAsset = $this->asset($otherOrganization);
        $otherEmployee = $this->employee($otherOrganization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('asset-assignments.store'), [
                'asset_id' => $otherAsset->id,
                'employee_id' => $otherEmployee->id,
                'assigned_at' => '2026-09-21',
            ])
            ->assertNotFound();
    }

    public function test_return_returns_404_for_an_assignment_in_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());
        $assignment = AssetAssignment::query()->create([
            'organization_id' => $otherOrganization->id,
            'asset_id' => $this->asset($otherOrganization, config('opsdeck.asset_status.assigned'))->id,
            'employee_id' => $this->employee($otherOrganization)->id,
            'assigned_at' => '2026-09-21',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('asset-assignments.update', $assignment), ['returned_at' => '2026-09-22'])
            ->assertNotFound();
    }

    public function test_viewer_cannot_assign_an_asset(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('asset-assignments.store'), [
                'asset_id' => $this->asset($organization)->id,
                'employee_id' => $this->employee($organization)->id,
                'assigned_at' => '2026-09-21',
            ])
            ->assertForbidden();
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $user = User::factory()->create(['email' => 'asset-assignment-'.Str::uuid().'@example.test']);
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

    private function asset(Organization $organization, ?string $status = null): Asset
    {
        return Asset::query()->create([
            'organization_id' => $organization->id,
            'asset_number' => 'asset-'.Str::lower(Str::random(config('opsdeck.slug_suffix_length'))),
            'asset_type' => config('opsdeck.asset_types')[0],
            'status' => $status ?? config('opsdeck.asset_status.in_stock'),
        ]);
    }

    private function employee(Organization $organization, ?string $employmentStatus = null): Employee
    {
        return Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Employee '.Str::random(config('opsdeck.slug_suffix_length')),
            'employment_status' => $employmentStatus ?? config('opsdeck.employee_status.active'),
        ]);
    }
}
