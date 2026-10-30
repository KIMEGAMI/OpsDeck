<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\User;
use App\Services\ContractMonthlyCostCalculator;
use App\Services\ContractRenewalWindow;
use App\Services\CostSavingsEstimator;
use App\Services\ImpactReportMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CostDashboardTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertSee('契約更新時に金額を見直す候補：219円')
            ->assertSee('共通の初期設定値')
            ->assertSee('会社別の計算条件を設定');
    }

    public function test_savings_candidates_exclude_contracts_already_pending_cancellation_or_not_set_to_auto_renew(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->travelTo('2026-10-09 12:00:00');
        $suffix = Str::lower(Str::random(8));
        $organization = Organization::query()->create([
            'name' => 'Savings Eligibility '.$suffix,
            'slug' => 'savings-eligibility-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);
        $baseAttributes = [
            'organization_id' => $organization->id,
            'vendor_name' => 'Savings eligibility vendor',
            'contract_type' => 'service',
            'billing_cycle' => 'annual',
            'amount' => 120000,
            'currency' => 'JPY',
            'renewal_date' => today()->addDays(10),
        ];
        $unusedSaasService = SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Cancellation in progress',
            'status' => 'active',
        ]);
        Contract::query()->create($baseAttributes + [
            'contract_name' => 'Automatic renewal',
            'auto_renew' => true,
            'status' => 'active',
        ]);
        Contract::query()->create($baseAttributes + [
            'contract_name' => 'Manual renewal',
            'auto_renew' => false,
            'status' => 'active',
        ]);
        Contract::query()->create($baseAttributes + [
            'contract_name' => 'Cancellation already requested',
            'saas_service_id' => $unusedSaasService->id,
            'auto_renew' => true,
            'status' => 'pending_cancel',
        ]);
        Contract::query()->create(array_merge($baseAttributes, [
            'contract_name' => 'Foreign cancellation already requested',
            'billing_cycle' => 'monthly',
            'amount' => 100,
            'currency' => 'USD',
            'auto_renew' => true,
            'status' => 'pending_cancel',
        ]));

        $estimate = app(CostSavingsEstimator::class)->estimate(
            $organization,
            Contract::query()->where('organization_id', $organization->id)->whereIn('status', ['active', 'pending_cancel'])->get(),
        );

        $this->assertSame(1, $estimate['renewal_candidate_count']);
        $this->assertSame(['Automatic renewal'], array_column($estimate['renewal_candidates'], 'contract_name'));
        $this->assertSame(12000, $estimate['renewal_negotiation_jpy']);
        $this->assertSame(0, $estimate['unused_saas_jpy']);
        $this->assertSame([], $estimate['unused_saas_candidates']);
        $this->assertSame([], $estimate['assumptions']['unconverted_foreign_contracts_by_currency']);
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
        $premiumAnnualFeeJpy = (int) config('opsdeck.plans.'.config('opsdeck.premium_plan_code').'.monthly_price_jpy') * ContractMonthlyCostCalculator::MONTHS_PER_YEAR;

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('<p><strong>121,000円</strong></p>', false)
            ->assertSee('利用者の割当状況を確認するクラウドサービス：121,000円')
            ->assertSee(route('saas-services.show', $saasService), false)
            ->assertSee('利用者の割当状況を確認するクラウドサービス')
            ->assertSee('Unused annual service')
            ->assertSee('Unused low-cost service')
            ->assertSee('121,000.00')
            ->assertSee('契約更新時に金額を見直す候補：0円')
            ->assertSee('有料プラン（Premium）料金との比較（年額・税別）')
            ->assertSee('有料プランの年間料金：'.number_format($premiumAnnualFeeJpy).'円')
            ->assertSee('現在の削減候補額は年間料金を'.number_format(121000 - $premiumAnnualFeeJpy).'円上回る概算です。')
            ->assertSee('実現済みの削減額や料金回収を保証するものではありません。');
    }

    public function test_unused_saas_estimate_includes_retired_only_assignments_but_excludes_active_and_shared_accounts(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $organization = Organization::query()->create([
            'name' => 'SaaS assignment savings '.$suffix,
            'slug' => 'saas-assignment-savings-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);
        $activeEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '在籍社員',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $retiredEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '退職済み社員',
            'employment_status' => config('opsdeck.employee_status.retired'),
        ]);

        $createServiceWithContract = function (string $name) use ($organization, $suffix): SaasService {
            $service = SaasService::query()->create([
                'organization_id' => $organization->id,
                'name' => $name.' '.$suffix,
                'status' => 'active',
            ]);
            Contract::query()->create([
                'organization_id' => $organization->id,
                'saas_service_id' => $service->id,
                'vendor_name' => 'Savings test vendor',
                'contract_name' => $name.' contract',
                'contract_type' => 'service',
                'billing_cycle' => 'annual',
                'amount' => 10000,
                'currency' => 'JPY',
                'auto_renew' => false,
                'status' => 'active',
            ]);

            return $service;
        };

        $retiredOnlyService = $createServiceWithContract('Retired only');
        $activeAndRetiredService = $createServiceWithContract('Active and retired');
        $sharedAccountService = $createServiceWithContract('Shared account');
        foreach ([
            [$retiredOnlyService, $retiredEmployee],
            [$activeAndRetiredService, $activeEmployee],
            [$activeAndRetiredService, $retiredEmployee],
            [$sharedAccountService, null],
        ] as [$service, $employee]) {
            SaasAssignment::query()->create([
                'organization_id' => $organization->id,
                'saas_service_id' => $service->id,
                'employee_id' => $employee?->id,
                'account_identifier' => 'account-'.$service->id,
                'status' => 'active',
                'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
            ]);
        }

        $estimate = app(CostSavingsEstimator::class)->estimate(
            $organization,
            Contract::query()->where('organization_id', $organization->id)->get(),
        );

        $this->assertSame(10000, $estimate['unused_saas_jpy']);
        $this->assertSame(['Retired only contract'], $estimate['unused_saas_candidates'][0]['contract_names']);
        $this->assertSame('Retired only '.$suffix, $estimate['unused_saas_candidates'][0]['service_name']);
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
        $premiumAnnualFeeJpy = (int) config('opsdeck.plans.'.config('opsdeck.premium_plan_code').'.monthly_price_jpy') * ContractMonthlyCostCalculator::MONTHS_PER_YEAR;

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('契約更新時に金額を見直す候補：0円')
            ->assertSee('利用状況を確認する端末：0円')
            ->assertSee('現在の削減候補額は年間料金に'.number_format($premiumAnnualFeeJpy).'円届かない概算です。')
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
            ->assertSee('利用者の割当状況を確認するクラウドサービス：0円');
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
        $availableAsset = Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'AVAILABLE-'.$suffix, 'asset_type' => 'pc', 'model' => 'Candidate model', 'status' => 'in_stock']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'LOST-'.$suffix, 'asset_type' => 'pc', 'status' => 'lost']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'RETIRED-'.$suffix, 'asset_type' => 'pc', 'status' => 'retired']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'DISPOSED-'.$suffix, 'asset_type' => 'pc', 'status' => 'disposed']);

        $session = ['current_organization_id' => $organization->id];
        $this->actingAs($user)->withSession($session)->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('10,000.00')
            ->assertSee('USD')
            ->assertSee('Support')
            ->assertSee('Acme')
            ->assertSee('削減候補の年間金額（概算）')
            ->assertSee('12,000円')
            ->assertSee('<p><strong>72,000円</strong></p>', false)
            ->assertSee('利用者の割当状況を確認するクラウドサービス：0円')
            ->assertSee('利用状況を確認する端末：60,000円')
            ->assertSee('対象端末（在庫・修理中）')
            ->assertSee(route('assets.show', $availableAsset), false)
            ->assertSee('Candidate model')
            ->assertSee('5,000円')
            ->assertDontSee('LOST-'.$suffix)
            ->assertDontSee('RETIRED-'.$suffix)
            ->assertDontSee('DISPOSED-'.$suffix)
            ->assertSee('為替レート未設定のため概算に含めていない外貨継続契約：USD：1件');

        $this->actingAs($user)->withSession($session)
            ->get(route('assets.index', ['statuses' => array_keys(config('opsdeck.cost_savings.unused_asset_statuses')), 'unassigned' => 1]))
            ->assertOk()
            ->assertSee('AVAILABLE-'.$suffix)
            ->assertDontSee('LOST-'.$suffix)
            ->assertDontSee('RETIRED-'.$suffix)
            ->assertDontSee('DISPOSED-'.$suffix);
    }

    public function test_cancellation_deadline_is_included_in_contract_deadline_reminders_but_not_savings_estimates(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cost-cancellation-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cost Cancellation '.$suffix, 'slug' => 'cost-cancellation-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create([
            'organization_id' => $organization->id,
            'vendor_name' => 'Cancellation vendor',
            'contract_name' => 'Cancellation deadline only',
            'contract_type' => 'service',
            'billing_cycle' => 'annual',
            'amount' => 120000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
            'cancellation_deadline' => today()->addDays(10),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('Cancellation deadline only')
            ->assertSee('解約申込期限')
            ->assertDontSee('更新前に確認する契約（1件）');
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
            ->assertSee('費用の確認・見直し')
            ->assertSee('契約の月額費用')
            ->assertSee('JPYは日本円、USDは米ドルです。異なる通貨は合算していません。')
            ->assertSee('<dt>JPY</dt><dd>10,000.00</dd>', false)
            ->assertSee('削減候補の概算、削減実績の記録、契約更新のお知らせは有料プラン（Premium）で利用できます。')
            ->assertDontSee('作業時間短縮の参考値')
            ->assertSee('有料プランの相談は、組織の契約担当者にご依頼ください。')
            ->assertDontSee('削減候補の年間金額（概算）')
            ->assertDontSee(ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内に確認が必要な契約期限')
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
            ->assertSee(ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内に確認が必要な契約期限')
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
            ->assertSee('契約更新時に金額を見直す候補：101円');
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
            ->assertSee('更新前に確認する契約（4件）')
            ->assertSee('90日以内に更新・終了予定で、自動更新が有効な日本円（JPY）の継続契約です。')
            ->assertSee('Neither date soon')
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
            $renewalContract = Contract::query()->create([
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
                ->assertSee('契約更新時に金額を見直す候補：10,000円')
                ->assertSee('更新前に確認する契約（1件）')
                ->assertSee(route('contracts.show', $renewalContract), false)
                ->assertSee('Timezone renewal')
                ->assertSee('100,000.00')
                ->assertSee('10,000円');

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
