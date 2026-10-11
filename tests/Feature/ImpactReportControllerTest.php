<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\ImpactMetricSnapshot;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RealizedSaving;
use App\Models\SaasService;
use App\Models\User;
use App\Services\ContractRenewalWindow;
use App\Services\ImpactReportMetricsService;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImpactReportControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_member_can_view_impact_report_and_owner_can_download_csv(): void
    {
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])->get(route('impact-report.show'))->assertOk()->assertSee('導入効果レポート')->assertSee('社員')->assertSee('過去の状態を遡って推測することはありません。')->assertDontSee('印刷・PDF保存');
        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])->get(route('impact-report.download'))->assertForbidden();
        [$owner, $ownerOrganization] = $this->member(MembershipRole::Owner);
        Contract::query()->create(['organization_id' => $ownerOrganization->id, 'vendor_name' => 'Future', 'contract_name' => 'Renewal', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(10)]);
        Contract::query()->create(['organization_id' => $ownerOrganization->id, 'vendor_name' => 'Expired', 'contract_name' => 'Past', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->subDay()]);
        $this->actingAs($owner)->withSession(['current_organization_id' => $ownerOrganization->id])->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('印刷・PDF保存')
            ->assertSee('導入効果レポートを印刷またはPDF保存')
            ->assertSee('impact-report-actions')
            ->assertSee('@page { size: A4; margin: 14mm; }', false)
            ->assertSee('body > header, .impact-report-actions { display: none !important; }', false)
            ->assertSee(ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の更新期限')
            ->assertSee('1件');
        $response = $this->actingAs($owner)->withSession(['current_organization_id' => $ownerOrganization->id])->get(route('impact-report.download'));
        $response->assertOk()->assertHeader('content-disposition');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    public function test_import_snapshots_are_shown_as_an_actual_before_after_comparison_and_trend(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employee-import',
            'status' => 'ready',
        ]);
        $metricsService = app(ImpactReportMetricsService::class);
        $metricsService->capture($organization, $batch, 'import_before');
        Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Imported employee',
            'email' => 'impact-import-'.Str::uuid().'@example.test',
            'employment_status' => 'active',
        ]);
        $batch->update(['status' => 'completed']);
        $metricsService->capture($organization, $batch, 'import_after');

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('最新Importの前後比較')
            ->assertSee('Import後の推移')
            ->assertSee('社員数')
            ->assertSee('Import前')
            ->assertSee('Import後')
            ->assertSee('未確認Import実行失敗');

        $csv = $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.download'))
            ->streamedContent();
        $this->assertStringContainsString('latest_import_delta_employees,1,"Import batch '.$batch->id.'"', $csv);
        $this->assertStringContainsString('trend_import_after_unresolved_items,0,', $csv);
    }

    public function test_monthly_cost_metric_discloses_its_scope_and_does_not_claim_actual_savings(): void
    {
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('月次・年次のJPY契約のみを月額換算')
            ->assertSee('外貨・一時契約・その他の請求周期は含みません')
            ->assertSee('実現した経費削減額ではありません')
            ->assertSee(route('costs.dashboard'));
    }

    public function test_impact_report_shows_and_exports_only_non_voided_realized_savings_totals(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        RealizedSaving::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'amount' => '9876543.21',
            'currency' => 'CAD',
            'period' => 'annual',
        ]);
        RealizedSaving::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'amount' => '8765432.10',
            'currency' => 'EUR',
            'period' => 'one_time',
            'voided_by' => $owner->id,
            'voided_at' => now(),
            'void_reason' => '重複登録',
        ]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('記録された削減実績')
            ->assertSee('CAD（年額）：9,876,543.21')
            ->assertDontSee('8,765,432.10');

        $csv = $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.download'))
            ->streamedContent();

        $this->assertStringContainsString('realized_savings_CAD_annual,9876543.21,', $csv);
        $this->assertStringNotContainsString('realized_savings_EUR_one_time', $csv);
        $this->assertStringNotContainsString('重複登録', $csv);
    }

    public function test_renewal_metric_counts_either_date_within_thirty_days_once(): void
    {
        [, $organization] = $this->member(MembershipRole::Viewer);
        $contractAttributes = [
            'organization_id' => $organization->id,
            'vendor_name' => 'Renewal vendor',
            'contract_type' => 'service',
            'billing_cycle' => 'monthly',
            'amount' => 1000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
        ];

        Contract::query()->create($contractAttributes + [
            'contract_name' => 'Ending soon',
            'renewal_date' => today()->addDays(60),
            'ends_at' => today()->addDays(10),
        ]);
        Contract::query()->create($contractAttributes + [
            'contract_name' => 'Renewing soon',
            'renewal_date' => today()->addDays(10),
            'ends_at' => today()->addDays(60),
        ]);
        Contract::query()->create($contractAttributes + [
            'contract_name' => 'Both dates soon',
            'renewal_date' => today()->addDays(10),
            'ends_at' => today()->addDays(15),
        ]);
        Contract::query()->create($contractAttributes + [
            'contract_name' => 'No date soon',
            'renewal_date' => today()->addDays(60),
            'ends_at' => today()->addDays(90),
        ]);

        $metrics = app(ImpactReportMetricsService::class)->current($organization);

        $this->assertSame(3, $metrics['renewals_30_days']);
    }

    public function test_attention_metrics_exclude_retired_assets_and_inactive_saas_services(): void
    {
        [, $organization] = $this->member(MembershipRole::Viewer);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-IN-STOCK', 'asset_type' => 'laptop', 'status' => 'in_stock']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-RETIRED', 'asset_type' => 'laptop', 'status' => 'retired']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-DISPOSED', 'asset_type' => 'laptop', 'status' => 'disposed']);
        SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Unassigned active service', 'status' => config('opsdeck.saas_service_active_status')]);
        SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Unassigned inactive service', 'status' => 'inactive']);

        $metrics = app(ImpactReportMetricsService::class)->current($organization);

        $this->assertSame(1, $metrics['asset_attention']);
        $this->assertSame(1, $metrics['saas_attention']);
    }

    public function test_snapshot_factory_uses_the_same_organization_as_its_import_batch(): void
    {
        $snapshot = ImpactMetricSnapshot::factory()->create();

        $this->assertSame($snapshot->organization_id, $snapshot->importBatch->organization_id);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'impact-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Impact '.$suffix, 'slug' => 'impact-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
