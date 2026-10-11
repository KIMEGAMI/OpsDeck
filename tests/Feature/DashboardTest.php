<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Models\User;
use App\Services\ContractRenewalWindow;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_dashboard_metrics_use_a_compact_mobile_grid_without_overflowing_values(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->ownerOrganization();
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertIsString($layout);
        $this->assertStringContainsString('font-size: clamp(1rem, 4vw, 1.4rem);', $layout);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $layout);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('@media (max-width: 48rem)', false)
            ->assertSee('repeat(2, minmax(0, 1fr))', false);
    }

    public function test_dashboard_shows_contract_metrics_and_links(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'dashboard-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Dashboard '.$suffix, 'slug' => 'dashboard-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Acme', 'contract_name' => 'Annual', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => now()->addDays(5)]);
        SecurityControl::query()->create(['organization_id' => $organization->id, 'control_code' => 'AC-1', 'title' => 'Access', 'category' => 'access', 'description' => 'Access control', 'status' => 'partial']);
        Evidence::query()->create(['organization_id' => $organization->id, 'title' => 'Expired', 'evidence_type' => 'policy', 'storage_disk' => 'local', 'storage_path' => 'metadata-only', 'mime_type' => 'application/x-opsdeck-metadata', 'file_size' => 0, 'valid_until' => now()->subDay(), 'uploaded_by' => $user->id]);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'partial-errors', 'status' => 'completed', 'error_rows' => 3]);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed', 'status' => 'failed']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-001', 'asset_type' => 'laptop', 'status' => 'in_stock']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-RETIRED', 'asset_type' => 'laptop', 'status' => 'retired']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-DISPOSED', 'asset_type' => 'laptop', 'status' => 'disposed']);
        $historicalAsset = Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-002', 'asset_type' => 'laptop', 'status' => 'assigned']);
        SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Unassigned SaaS', 'status' => 'active']);
        SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Retired SaaS', 'status' => 'retired']);
        $formerEmployee = Employee::query()->create(['organization_id' => $organization->id, 'name' => '退職者', 'employment_status' => 'left']);
        $activeEmployee = Employee::query()->create(['organization_id' => $organization->id, 'name' => '在籍者', 'employment_status' => 'active']);
        AssetAssignment::query()->create(['organization_id' => $organization->id, 'asset_id' => $historicalAsset->id, 'employee_id' => $formerEmployee->id, 'assigned_at' => now()->subMonth(), 'returned_at' => now()->subWeek()]);
        AssetAssignment::query()->create(['organization_id' => $organization->id, 'asset_id' => $historicalAsset->id, 'employee_id' => $activeEmployee->id, 'assigned_at' => now()->subWeek()]);
        $formerEmployeeService = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Former Employee SaaS', 'status' => 'active']);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $formerEmployeeService->id, 'employee_id' => $formerEmployee->id, 'status' => 'active', 'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown')]);
        $historicalService = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Historical SaaS', 'status' => 'active']);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $historicalService->id, 'employee_id' => $formerEmployee->id, 'status' => 'ended', 'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'), 'ended_at' => now()->subWeek()]);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $historicalService->id, 'employee_id' => $activeEmployee->id, 'status' => 'active', 'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown')]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('dashboard'))->assertOk()->assertSee('現在の組織データと、優先して確認すべき項目を表示しています。')->assertSee('10,000.00')->assertSee('期限切れ・'.ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の更新アラート')->assertSee('要対応セキュリティ項目')->assertSee('未確認Importエラー行数')->assertSee('未確認Import実行失敗')->assertSee('今日やること')->assertSee(route('costs.dashboard'))->assertSee(route('security-controls.index', ['attention' => 1]))->assertSee(route('evidence.index', ['expired' => 1]))->assertSee(route('imports.index', ['has_errors' => 1, 'unreviewed' => 1]))->assertSee(route('imports.index', ['status' => 'failed', 'unreviewed' => 1]))->assertSee(route('employees.index'))->assertSee(route('assets.index'))->assertSee(route('saas-services.index'))->assertSee(route('contracts.index'))->assertSee(route('settings.members'))->assertSee('未割当または退職者に紐づく資産を確認する')->assertSee('未割当または退職者に紐づくSaaSを確認する')->assertSee('（1件）')->assertSee('（2件）');

        $this->assertDatabaseMissing('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_owner_sees_plan_limit_warning_on_dashboard(): void
    {
        Config::set('opsdeck.plans.free.limits.employees', 1);
        [$user, $organization] = $this->ownerOrganization();
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '利用者', 'employment_status' => 'active']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('プラン上限の注意')
            ->assertSee('社員：1/1')
            ->assertSee(route('settings.organization'))
            ->assertSee('mailto:'.config('opsdeck.support_email'));
    }

    public function test_owner_sees_administrator_limit_warning_on_dashboard(): void
    {
        Config::set('opsdeck.plans.free.limits.administrators', 1);
        [$user, $organization] = $this->ownerOrganization();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('管理者：1/1');
    }

    public function test_admin_sees_owner_guidance_instead_of_settings_link_for_limit_warning(): void
    {
        Config::set('opsdeck.plans.free.limits.employees', 1);
        [, $organization] = $this->ownerOrganization();
        $admin = User::factory()->create(['email' => 'dashboard-admin-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $admin->id, 'role' => MembershipRole::Admin, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '利用者', 'employment_status' => 'active']);

        $this->actingAs($admin)->withSession(['current_organization_id' => $organization->id])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('組織Ownerにご依頼ください。')
            ->assertDontSee(route('settings.organization'));
    }

    public function test_viewer_does_not_see_plan_limit_warning(): void
    {
        Config::set('opsdeck.plans.free.limits.employees', 1);
        $suffix = Str::lower(Str::random(8));
        $viewer = User::factory()->create(['email' => 'dashboard-viewer-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Dashboard viewer '.$suffix, 'slug' => 'dashboard-viewer-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $viewer->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '利用者', 'employment_status' => 'active']);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('プラン上限の注意')
            ->assertDontSee('利用上限について問い合わせる');
    }

    public function test_dashboard_includes_contracts_with_an_ending_date_inside_the_alert_window(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->ownerOrganization();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Ending vendor', 'contract_name' => 'End date soon', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(60), 'ends_at' => today()->addDays(10)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('期限切れ・'.ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の契約更新を確認する</a>（1件）', false);
    }

    public function test_dashboard_labels_overdue_contracts_in_its_renewal_alert_count(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->ownerOrganization();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Overdue vendor', 'contract_name' => 'Past renewal', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->subDay()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('期限切れ・'.ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の契約更新を確認する</a>（1件）', false);
    }

    /** @return array{0: User, 1: Organization} */
    private function ownerOrganization(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'dashboard-owner-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Dashboard owner '.$suffix, 'slug' => 'dashboard-owner-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
