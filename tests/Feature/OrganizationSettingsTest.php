<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_owner_can_update_organization_settings(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('settings.organization'))
            ->assertOk()
            ->assertSee('削減見積の会社別前提')
            ->assertSee('未設定の場合は共通初期値')
            ->assertSee('購入価格や簿価は含めません。');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->put(route('settings.organization.update'), [
            'timezone' => 'UTC',
            'company_size' => 80,
            'ai_enabled' => true,
            'cost_savings_renewal_rate_percent' => 15,
            'cost_savings_unused_asset_monthly_jpy' => 3200,
        ])->assertRedirect(route('settings.organization'));
        $this->assertSame('UTC', $organization->refresh()->timezone);
        $this->assertSame(80, $organization->company_size);
        $this->assertSame(15, $organization->cost_savings_renewal_rate_percent);
        $this->assertSame(3200, $organization->cost_savings_unused_asset_monthly_jpy);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'actor_user_id' => $user->id, 'action' => 'organization.settings_updated']);
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('action', AuditLog::OrganizationSettingsUpdated)->firstOrFail();
        $this->assertEqualsCanonicalizing([
            'timezone',
            'company_size',
            'ai_enabled',
            'cost_savings_renewal_rate_percent',
            'cost_savings_unused_asset_monthly_jpy',
        ], $auditLog->after_json['changed_fields']);
        $this->assertNull($auditLog->before_json);
        $this->assertStringNotContainsString('3200', json_encode($auditLog->toArray()));
    }

    public function test_free_plan_cannot_enable_ai_mapping(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->put(route('settings.organization.update'), [
                'timezone' => $organization->timezone,
                'ai_enabled' => true,
            ])
            ->assertSessionHasErrors('ai_enabled');

        $this->assertFalse($organization->refresh()->ai_enabled);
    }

    public function test_viewer_cannot_update_organization_settings(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('settings.organization'))->assertForbidden();
    }

    public function test_viewer_cannot_update_company_savings_assumptions(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->put(route('settings.organization.update'), [
                'timezone' => $organization->timezone,
                'cost_savings_renewal_rate_percent' => 100,
                'cost_savings_unused_asset_monthly_jpy' => 10000,
            ])
            ->assertForbidden();

        $this->assertNull($organization->refresh()->cost_savings_renewal_rate_percent);
        $this->assertNull($organization->cost_savings_unused_asset_monthly_jpy);
    }

    public function test_owner_can_view_plan_usage_limits(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        Employee::query()->create(['organization_id' => $organization->id, 'employee_number' => 'E-001', 'name' => '利用者', 'employment_status' => 'active']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'A-001', 'asset_type' => 'PC', 'status' => 'active']);
        SaasService::query()->create(['organization_id' => $organization->id, 'name' => '利用SaaS', 'status' => 'active']);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('settings.organization'));

        $response->assertOk()->assertSee('プラン利用状況')->assertSee('利用率')->assertSee('20</td>', false);
    }

    public function test_plan_usage_shows_warning_when_limit_is_reached(): void
    {
        Config::set('opsdeck.plans.free.limits.employees', 1);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        Employee::query()->create(['organization_id' => $organization->id, 'employee_number' => 'E-001', 'name' => '利用者', 'employment_status' => 'active']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('settings.organization'))
            ->assertOk()
            ->assertSee('上限超過');
    }

    public function test_invalid_settings_are_rejected(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->from(route('settings.organization'))->put(route('settings.organization.update'), ['timezone' => 'Not/A_Timezone', 'company_size' => config('opsdeck.validation.company_size_maximum') + 1])->assertSessionHasErrors(['timezone', 'company_size']);
    }

    public function test_company_savings_assumptions_outside_supported_ranges_are_rejected(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->from(route('settings.organization'))
            ->put(route('settings.organization.update'), [
                'timezone' => $organization->timezone,
                'cost_savings_renewal_rate_percent' => config('opsdeck.cost_savings.organization_renewal_rate_percent_max') + 1,
                'cost_savings_unused_asset_monthly_jpy' => config('opsdeck.cost_savings.organization_unused_asset_monthly_jpy_max') + 1,
            ])
            ->assertSessionHasErrors(['cost_savings_renewal_rate_percent', 'cost_savings_unused_asset_monthly_jpy']);

        $this->assertNull($organization->refresh()->cost_savings_renewal_rate_percent);
        $this->assertNull($organization->cost_savings_unused_asset_monthly_jpy);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'settings-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Settings '.$suffix, 'slug' => 'settings-'.$suffix, 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
