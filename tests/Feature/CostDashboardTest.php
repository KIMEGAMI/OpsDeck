<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\User;
use App\Services\ContractMonthlyCostCalculator;
use App\Services\ContractRenewalWindow;
use App\Services\CostSavingsEstimator;
use App\Services\ImpactReportMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

class CostDashboardTest extends TestCase
{
    public function test_company_savings_assumptions_override_defaults_without_affecting_other_organizations(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->travelTo('2026-10-09 12:00:00');
        config([
            'opsdeck.cost_savings.renewal_negotiation_rate' => 0.10,
            'opsdeck.cost_savings.unused_asset_monthly_jpy' => 5000,
        ]);
        $suffix = Str::lower(Str::random(8));
        $customOrganization = Organization::query()->create([
            'name' => 'Custom Assumptions '.$suffix,
            'slug' => 'custom-assumptions-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'cost_savings_renewal_rate_percent' => 0,
            'cost_savings_unused_asset_monthly_jpy' => 1000,
        ]);
        $defaultOrganization = Organization::query()->create([
            'name' => 'Default Assumptions '.$suffix,
            'slug' => 'default-assumptions-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);
        $customContract = Contract::query()->create([
            'organization_id' => $customOrganization->id,
            'vendor_name' => 'Custom vendor',
            'contract_name' => 'Custom renewal',
            'contract_type' => 'service',
            'billing_cycle' => 'annual',
            'amount' => 100000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
            'renewal_date' => today()->addDays(10),
        ]);
        $defaultContract = Contract::query()->create([
            'organization_id' => $defaultOrganization->id,
            'vendor_name' => 'Default vendor',
            'contract_name' => 'Default renewal',
            'contract_type' => 'service',
            'billing_cycle' => 'annual',
            'amount' => 100000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
            'renewal_date' => today()->addDays(10),
        ]);
        Asset::query()->create(['organization_id' => $customOrganization->id, 'asset_number' => 'CUSTOM-'.$suffix, 'asset_type' => 'pc', 'status' => 'in_stock']);
        Asset::query()->create(['organization_id' => $defaultOrganization->id, 'asset_number' => 'DEFAULT-'.$suffix, 'asset_type' => 'pc', 'status' => 'in_stock']);

        $customEstimate = app(CostSavingsEstimator::class)->estimate($customOrganization, collect([$customContract]));
        $defaultEstimate = app(CostSavingsEstimator::class)->estimate($defaultOrganization, collect([$defaultContract]));

        $this->assertSame(0.0, $customEstimate['assumptions']['renewal_rate']);
        $this->assertSame('organization', $customEstimate['assumptions']['renewal_rate_source']);
        $this->assertSame(1000, $customEstimate['assumptions']['unused_asset_monthly_jpy']);
        $this->assertSame('organization', $customEstimate['assumptions']['unused_asset_monthly_jpy_source']);
        $this->assertSame(12000, $customEstimate['unused_assets_jpy']);
        $this->assertSame(0, $customEstimate['renewal_negotiation_jpy']);
        $this->assertSame(0.10, $defaultEstimate['assumptions']['renewal_rate']);
        $this->assertSame('default', $defaultEstimate['assumptions']['renewal_rate_source']);
        $this->assertSame(5000, $defaultEstimate['assumptions']['unused_asset_monthly_jpy']);
        $this->assertSame('default', $defaultEstimate['assumptions']['unused_asset_monthly_jpy_source']);
        $this->assertSame(60000, $defaultEstimate['unused_assets_jpy']);
        $this->assertSame(10000, $defaultEstimate['renewal_negotiation_jpy']);
    }

    public function test_renewal_savings_round_once_using_each_contracts_annual_amount(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->travelTo('2026-10-09 12:00:00');
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-rounding-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost Rounding '.$suffix, 'slug' => 'cost-rounding-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Monthly vendor', 'contract_name' => 'Monthly service', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 99, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Annual vendor', 'contract_name' => 'Annual service', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('<p><strong>219円</strong></p>', false)
            ->assertSee('更新時の見直し余地：219円')
            ->assertSee('共通初期値')
            ->assertSee('会社別前提を設定');
    }

    public function test_unused_saas_contract_is_not_counted_as_both_cancellation_and_renewal_savings(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->travelTo('2026-10-09 12:00:00');
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-overlap-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost Overlap '.$suffix, 'slug' => 'cost-overlap-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $saasService = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Unused SaaS '.$suffix, 'status' => 'active']);
        Contract::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $saasService->id, 'vendor_name' => 'SaaS vendor', 'contract_name' => 'Unused annual service', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);
        Contract::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $saasService->id, 'vendor_name' => 'Low-cost SaaS vendor', 'contract_name' => 'Unused low-cost service', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('<p><strong>121,000円</strong></p>', false)
            ->assertSee('未使用SaaSの見直し余地：121,000円')
            ->assertSee('更新時の見直し余地：0円');
    }

    public function test_invalid_savings_assumptions_are_excluded_and_explained(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.cost_savings.renewal_negotiation_rate' => 1.5, 'opsdeck.cost_savings.unused_asset_monthly_jpy' => -1000]);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-invalid-assumptions-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Invalid Savings Assumptions '.$suffix, 'slug' => 'invalid-savings-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Invalid assumption vendor', 'contract_name' => 'Renewal contract', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'INVALID-ASSUMPTION-'.$suffix, 'asset_type' => 'pc', 'status' => 'in_stock']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('更新時の見直し余地：0円')
            ->assertSee('未使用端末の見直し余地：0円')
            ->assertSee('更新見直し率の設定が0〜100%の範囲外', false)
            ->assertSee('未使用端末の月額設定が負数', false)
            ->assertSee('role="alert"', false);

        $estimate = app(CostSavingsEstimator::class)->estimate($organization, Contract::query()->where('organization_id', $organization->id)->get());
        $this->assertSame(0.0, $estimate['assumptions']['renewal_rate']);
        $this->assertSame(0, $estimate['assumptions']['unused_asset_monthly_jpy']);
        $this->assertSame(0, $estimate['annual_total_jpy']);
        $this->assertCount(2, $estimate['assumptions']['warnings']);
    }

    public function test_cost_dashboard_excludes_another_organizations_contract_linked_to_a_local_saas_service(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-tenant-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost Tenant '.$suffix, 'slug' => 'cost-tenant-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $otherOrganization = Organization::query()->create(['name' => 'Other Cost Tenant '.$suffix, 'slug' => 'other-cost-tenant-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $saasService = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Local SaaS '.$suffix, 'status' => 'active']);
        Contract::query()->create(['organization_id' => $otherOrganization->id, 'saas_service_id' => $saasService->id, 'vendor_name' => 'Other tenant vendor', 'contract_name' => 'Other tenant contract', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('<p><strong>0円</strong></p>', false)
            ->assertSee('未使用SaaSの見直し余地：0円');
    }

    public function test_operator_can_view_monthly_costs_and_upcoming_renewals(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost '.$suffix, 'slug' => 'cost-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Acme', 'contract_name' => 'Support', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => now()->addDays(10)]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Foreign SaaS vendor', 'contract_name' => 'Foreign SaaS', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 500, 'currency' => 'USD', 'auto_renew' => true, 'status' => 'active']);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'One time vendor', 'contract_name' => 'One-time setup', 'contract_type' => 'service', 'billing_cycle' => 'one_time', 'amount' => 1000000, 'currency' => 'JPY', 'auto_renew' => false, 'status' => 'active', 'renewal_date' => now()->addDays(10)]);
        $saasService = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Unassigned one-time SaaS', 'status' => 'active']);
        Contract::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $saasService->id, 'vendor_name' => 'Other cycle vendor', 'contract_name' => 'Unspecified billing cycle', 'contract_type' => 'service', 'billing_cycle' => 'other', 'amount' => 50000, 'currency' => 'JPY', 'auto_renew' => false, 'status' => 'active']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'AVAILABLE-'.$suffix, 'asset_type' => 'pc', 'status' => 'in_stock']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'LOST-'.$suffix, 'asset_type' => 'pc', 'status' => 'lost']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'RETIRED-'.$suffix, 'asset_type' => 'pc', 'status' => 'retired']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'DISPOSED-'.$suffix, 'asset_type' => 'pc', 'status' => 'disposed']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('costs.dashboard'))->assertOk()->assertSee('10,000.00')->assertSee('USD')->assertSee('Support')->assertSee('Acme')->assertSee('削減可能額の概算')->assertSee('12,000円')->assertSee('<p><strong>72,000円</strong></p>', false)->assertSee('未使用SaaSの見直し余地：0円')->assertSee('未使用端末の見直し余地：60,000円')->assertSee('対象端末（在庫・修理中）')->assertSee('為替レート未設定のため概算に含めていない外貨継続契約：USD：1件');
    }

    public function test_free_plan_keeps_basic_costs_but_hides_premium_savings_and_renewal_opportunities(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-free-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost Free '.$suffix, 'slug' => 'cost-free-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => 'inactive']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Free vendor', 'contract_name' => 'Renewal soon', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('月次ランニングコスト')
            ->assertSee('<dt>JPY</dt><dd>10,000.00</dd>', false)
            ->assertSee('削減可能額の概算、削減実績の記録、更新アラートはPremiumプランで利用できます。')
            ->assertSee('Premium契約の相談は組織Ownerにご依頼ください。')
            ->assertDontSee('削減可能額の概算（年間）')
            ->assertDontSee(ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の更新・終了予定')
            ->assertDontSee('<td>Renewal soon</td>', false);
    }

    public function test_empty_renewal_alert_state_uses_the_same_window_as_the_heading(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-empty-renewals-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost Empty Renewals '.$suffix, 'slug' => 'cost-empty-renewals-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee(ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の更新・終了予定')
            ->assertSee(ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の予定はありません。');
    }

    public function test_monthly_cost_uses_one_exact_calculation_across_member_views(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-consistency-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost Consistency '.$suffix, 'slug' => 'cost-consistency-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Monthly vendor', 'contract_name' => 'Monthly service', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => '0.99', 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Annual vendor', 'contract_name' => 'Annual service', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => '1000.01', 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Foreign vendor', 'contract_name' => 'Foreign service', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => '500.00', 'currency' => 'USD', 'auto_renew' => true, 'status' => 'active']);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'One-time vendor', 'contract_name' => 'One-time service', 'contract_type' => 'service', 'billing_cycle' => 'one_time', 'amount' => '500.00', 'currency' => 'JPY', 'auto_renew' => false, 'status' => 'active']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('<dt>JPY</dt><dd>84.32</dd>', false)
            ->assertSee('更新時の見直し余地：101円');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('84.32');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('JPY: 84.32')
            ->assertSee('USD: 500.00');

        $metrics = app(ImpactReportMetricsService::class)->current($organization);
        $this->assertSame(84.32, $metrics['monthly_jpy']);
        $this->assertSame(['JPY' => 84.32, 'USD' => 500.0], $metrics['monthly_costs_by_currency']);

        $monthlyCalculator = app(ContractMonthlyCostCalculator::class);
        $monthlyContract = Contract::query()->where('organization_id', $organization->id)->where('contract_name', 'Monthly service')->firstOrFail();
        $annualContract = Contract::query()->where('organization_id', $organization->id)->where('contract_name', 'Annual service')->firstOrFail();
        $this->assertSame(1188, $monthlyCalculator->annualAmountInCents($monthlyContract));
        $this->assertSame(100001, $monthlyCalculator->annualAmountInCents($annualContract));
    }

    public function test_cost_dashboard_uses_the_nearest_date_that_is_within_the_alert_window(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->travelTo('2026-10-09 12:00:00');
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-date-window-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost Date Window '.$suffix, 'slug' => 'cost-date-window-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $contractAttributes = [
            'organization_id' => $organization->id,
            'vendor_name' => 'Date Window Vendor',
            'contract_type' => 'service',
            'billing_cycle' => 'monthly',
            'amount' => 1000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
        ];
        Contract::query()->create($contractAttributes + ['contract_name' => 'Ends soon', 'renewal_date' => today()->addDays(60), 'ends_at' => today()->addDays(10)]);
        Contract::query()->create($contractAttributes + ['contract_name' => 'Renews soon', 'renewal_date' => today()->addDays(10), 'ends_at' => today()->addDays(60)]);
        Contract::query()->create($contractAttributes + ['contract_name' => 'Both dates soon', 'renewal_date' => today()->addDays(10), 'ends_at' => today()->addDays(20)]);
        Contract::query()->create($contractAttributes + ['contract_name' => 'Neither date soon', 'renewal_date' => today()->addDays(60), 'ends_at' => today()->addDays(90)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('Ends soon')
            ->assertSee('Renews soon')
            ->assertSee('Both dates soon')
            ->assertDontSee('Neither date soon')
            ->assertSee(today()->addDays(10)->toDateString());

        $metrics = app(ImpactReportMetricsService::class)->current($organization);
        $this->assertSame(3, $metrics['renewals_30_days']);
    }

    public function test_renewal_savings_window_uses_the_organizations_local_date(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->travelTo('2026-10-09 12:30:00');

        try {
            $suffix = Str::lower(Str::random(8));
            $user = User::factory()->create(['email' => 'cost-timezone-'.$suffix.'@example.test']);
            $organization = Organization::query()->create(['name' => 'Cost Timezone '.$suffix, 'slug' => 'cost-timezone-'.$suffix, 'timezone' => 'Pacific/Kiritimati', 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
            OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
            $localToday = CarbonImmutable::today($organization->timezone);
            Contract::query()->create([
                'organization_id' => $organization->id,
                'vendor_name' => 'Timezone vendor',
                'contract_name' => 'Timezone renewal',
                'contract_type' => 'service',
                'billing_cycle' => 'annual',
                'amount' => 100000,
                'currency' => 'JPY',
                'auto_renew' => true,
                'status' => 'active',
                'renewal_date' => $localToday->addDays(ContractRenewalWindow::SAVINGS_WINDOW_DAYS),
            ]);

            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
                ->get(route('costs.dashboard'))
                ->assertOk()
                ->assertSee('更新時の見直し余地：10,000円');

            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
                ->get(route('reminders.renewals'))
                ->assertOk()
                ->assertSee((ContractRenewalWindow::ALERT_WINDOW_DAYS + 1).'〜'.ContractRenewalWindow::SAVINGS_WINDOW_DAYS.'日以内')
                ->assertSee('Timezone renewal');
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}
