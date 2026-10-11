<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\PlanUsageService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PlanUsageServiceTest extends TestCase
{
    public function test_usage_service_marks_warning_boundary_and_counts_only_active_or_invited_administrators(): void
    {
        Config::set('opsdeck.plans.free.limits.employees', 5);
        Config::set('opsdeck.plans.free.limits.administrators', 2);
        [$organization, $owner] = $this->organization();
        foreach (range(1, 4) as $number) {
            Employee::query()->create(['organization_id' => $organization->id, 'name' => '社員'.$number, 'employment_status' => 'active']);
        }
        $invitedAdmin = User::factory()->create(['email' => 'invited-admin-'.uniqid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $invitedAdmin->id, 'role' => MembershipRole::Admin, 'status' => MembershipStatus::Invited, 'invited_at' => now()]);
        $suspendedAdmin = User::factory()->create(['email' => 'suspended-admin-'.uniqid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $suspendedAdmin->id, 'role' => MembershipRole::Admin, 'status' => MembershipStatus::Suspended]);

        $usage = app(PlanUsageService::class)->forOrganization($organization);
        $employees = collect($usage)->firstWhere('key', 'employees');
        $administrators = collect($usage)->firstWhere('key', 'administrators');

        $this->assertSame(4, $employees['current']);
        $this->assertSame(80.0, $employees['percentage']);
        $this->assertSame('注意', $employees['status']);
        $this->assertSame(2, $administrators['current']);
        $this->assertSame('上限超過', $administrators['status']);
        $this->assertNotNull($owner->id);
    }

    public function test_zero_limit_is_treated_as_exceeded(): void
    {
        Config::set('opsdeck.plans.free.limits.employees', 0);
        [$organization] = $this->organization();

        $employees = collect(app(PlanUsageService::class)->forOrganization($organization))->firstWhere('key', 'employees');

        $this->assertSame(0, $employees['limit']);
        $this->assertSame(100.0, $employees['percentage']);
        $this->assertSame('上限超過', $employees['status']);
    }

    public function test_warning_percentage_is_clamped_to_valid_range(): void
    {
        Config::set('opsdeck.limits.warning_percentage', 150);
        Config::set('opsdeck.plans.free.limits.employees', 5);
        [$organization] = $this->organization();

        $employees = collect(app(PlanUsageService::class)->forOrganization($organization))->firstWhere('key', 'employees');

        $this->assertSame('通常', $employees['status']);
    }

    public function test_premium_organization_uses_premium_plan_limits(): void
    {
        [$organization] = $this->organization();
        $organization->update(['plan_code' => 'premium']);

        $employees = collect(app(PlanUsageService::class)->forOrganization($organization))->firstWhere('key', 'employees');

        $this->assertSame(200, $employees['limit']);
    }

    public function test_pending_administrator_invitation_reserves_and_displays_a_plan_seat(): void
    {
        [$organization, $owner] = $this->organization();
        $invitedAdmin = User::factory()->create(['email' => 'reserved-admin-'.uniqid().'@example.test']);
        OrganizationInvitation::query()->create(['organization_id' => $organization->id, 'invited_by' => $owner->id, 'email' => $invitedAdmin->email, 'role' => MembershipRole::Admin, 'token_hash' => hash('sha256', $invitedAdmin->email), 'expires_at' => now()->addDay()]);
        OrganizationInvitation::query()->create(['organization_id' => $organization->id, 'invited_by' => $owner->id, 'email' => 'expired-admin-'.uniqid().'@example.test', 'role' => MembershipRole::Admin, 'token_hash' => hash('sha256', 'expired-admin-'.$owner->id), 'expires_at' => now()->subDay()]);
        OrganizationInvitation::query()->create(['organization_id' => $organization->id, 'invited_by' => $owner->id, 'email' => 'revoked-admin-'.uniqid().'@example.test', 'role' => MembershipRole::Admin, 'token_hash' => hash('sha256', 'revoked-admin-'.$owner->id), 'expires_at' => now()->addDay(), 'revoked_at' => now()]);

        $administrators = collect(app(PlanUsageService::class)->forOrganization($organization))->firstWhere('key', 'administrators');

        $this->assertSame(2, $administrators['current']);
        $this->assertSame('上限超過', $administrators['status']);
    }

    public function test_legacy_plan_uses_legacy_limit_configuration(): void
    {
        Config::set('opsdeck.limits.employees', 7);
        [$organization] = $this->organization();
        $organization->update(['plan_code' => 'standard']);

        $employees = collect(app(PlanUsageService::class)->forOrganization($organization))->firstWhere('key', 'employees');

        $this->assertSame(7, $employees['limit']);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function organization(): array
    {
        $user = User::factory()->create(['email' => 'plan-owner-'.uniqid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Plan usage', 'slug' => 'plan-usage-'.uniqid(), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$organization, $user];
    }
}
