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
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Models\RealizedSaving;
use App\Models\SaasService;
use App\Models\User;
use App\Models\WorkTimeMeasurement;
use App\Services\ContractRenewalWindow;
use App\Services\ImpactReportMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImpactReportControllerTest extends TestCase
{
    use RefreshDatabase;

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
        Contract::query()->create(['organization_id' => $ownerOrganization->id, 'vendor_name' => 'Foreign', 'contract_name' => 'USD plan', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => '250.00', 'currency' => 'USD', 'auto_renew' => true, 'status' => 'active']);
        Contract::query()->create(['organization_id' => $ownerOrganization->id, 'vendor_name' => 'Expired', 'contract_name' => 'Past', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->subDay()]);
        $this->actingAs($owner)->withSession(['current_organization_id' => $ownerOrganization->id])->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('印刷・PDF保存')
            ->assertSee('PDFにする場合は、このボタンを押した後の印刷画面で「PDFに保存」を選びます。')
            ->assertSee('導入効果レポートを印刷またはPDF保存')
            ->assertSee('impact-report-actions')
            ->assertSee('@page { size: A4; margin: 14mm; }', false)
            ->assertSee('body > header, .impact-report-actions { display: none !important; }', false)
            ->assertSee(ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の更新期限')
            ->assertSee('USD: 250.00')
            ->assertSee('1件');
        $response = $this->actingAs($owner)->withSession(['current_organization_id' => $ownerOrganization->id])->get(route('impact-report.download'));
        $response->assertOk()->assertHeader('content-disposition');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
        $csv = $response->streamedContent();
        $this->assertStringContainsString('項目,項目ID,値,情報源・補足', $csv);
        $this->assertStringContainsString('月額契約費用（USD）,monthly_cost_USD,250.00,', $csv);
        $this->assertStringNotContainsString('monthly_jpy', $csv);
    }

    public function test_impact_report_screen_and_csv_are_unavailable_outside_the_release_scope(): void
    {
        config(['opsdeck.release_features.impact_report' => false]);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($viewer)->withSession($session)
            ->get(route('impact-report.show'))
            ->assertNotFound();
        $this->actingAs($viewer)->withSession($session)
            ->get(route('impact-report.download'))
            ->assertNotFound();
    }

    public function test_report_exports_company_and_creation_time_and_protects_the_company_name_from_csv_formulas(): void
    {
        $this->travelTo('2026-10-16 10:30:00');
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $formulaLikeName = '=HYPERLINK("https://example.test","会社名")';
        $organization->update(['name' => $formulaLikeName]);
        $generatedAt = now($organization->timezone)->format('Y-m-d H:i');

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('対象組織')
            ->assertSee(e($formulaLikeName), false)
            ->assertSee($generatedAt)
            ->assertSee($organization->timezone);

        $csv = $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.download'))
            ->streamedContent();

        $this->assertStringContainsString("対象組織,organization_name,\"\t=HYPERLINK", $csv);
        $this->assertStringContainsString('レポート作成日時,report_generated_at,"'.$generatedAt.'"', $csv);
        $this->assertStringContainsString('"組織のタイムゾーン: '.$organization->timezone.'"', $csv);
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
        $jpyContract = Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Local', 'contract_name' => 'JPY license', 'contract_type' => 'software', 'billing_cycle' => 'monthly', 'amount' => '200.00', 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active']);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Foreign', 'contract_name' => 'USD license', 'contract_type' => 'software', 'billing_cycle' => 'annual', 'amount' => '120.00', 'currency' => 'USD', 'auto_renew' => true, 'status' => 'active']);
        $metricsService->capture($organization, $batch, 'import_before');
        Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Imported employee',
            'email' => 'impact-import-'.Str::uuid().'@example.test',
            'employment_status' => 'active',
        ]);
        $jpyContract->update(['amount' => '250.00']);
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
            ->assertSee('未確認Import実行失敗')
            ->assertSee('月額コスト（JPY）')
            ->assertSee('月額コスト（USD）');

        $csv = $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.download'))
            ->streamedContent();
        $this->assertStringContainsString('最新Importの増減（社員数）,latest_import_delta_employees,1,"取込履歴ID '.$batch->id.'"', $csv);
        $this->assertStringContainsString('月額契約費用（JPY）の取込前,latest_import_monthly_cost_JPY_before,200.00,"取込履歴ID '.$batch->id.'"', $csv);
        $this->assertStringContainsString('月額契約費用（JPY）の取込後,latest_import_monthly_cost_JPY_after,250.00,"取込履歴ID '.$batch->id.'"', $csv);
        $this->assertStringContainsString('月額契約費用（JPY）の増減,latest_import_monthly_cost_JPY_delta,50.00,"取込履歴ID '.$batch->id.'"', $csv);
        $this->assertStringContainsString('月額契約費用（USD）の取込前,latest_import_monthly_cost_USD_before,10.00,"取込履歴ID '.$batch->id.'"', $csv);
        $this->assertStringContainsString('月額契約費用（USD）の増減,latest_import_monthly_cost_USD_delta,0.00,"取込履歴ID '.$batch->id.'"', $csv);
        $this->assertStringContainsString('確認項目数の推移（取込後）,trend_import_after_unresolved_items,0,', $csv);
    }

    public function test_import_comparison_and_trend_have_stable_order_when_snapshots_share_a_timestamp(): void
    {
        [, $organization] = $this->member(MembershipRole::Viewer);
        config(['opsdeck.pagination.per_page' => 2]);
        $capturedAt = now()->startOfSecond();
        $firstBatch = ImportBatch::factory()->create(['organization_id' => $organization->id, 'status' => 'completed']);
        $secondBatch = ImportBatch::factory()->create(['organization_id' => $organization->id, 'status' => 'completed']);
        foreach ([$firstBatch, $secondBatch] as $batch) {
            ImpactMetricSnapshot::factory()->create([
                'organization_id' => $organization->id,
                'import_batch_id' => $batch->id,
                'stage' => 'import_before',
                'captured_at' => $capturedAt->copy()->subMinute(),
            ]);
        }
        $firstAfter = ImpactMetricSnapshot::factory()->create([
            'organization_id' => $organization->id,
            'import_batch_id' => $firstBatch->id,
            'stage' => 'import_after',
            'captured_at' => $capturedAt,
        ]);
        $secondAfter = ImpactMetricSnapshot::factory()->create([
            'organization_id' => $organization->id,
            'import_batch_id' => $secondBatch->id,
            'stage' => 'import_after',
            'captured_at' => $capturedAt,
        ]);
        $metricsService = app(ImpactReportMetricsService::class);

        $comparison = $metricsService->importComparison($organization);
        $trend = $metricsService->trend($organization);

        $this->assertNotNull($comparison);
        $this->assertSame($secondBatch->id, $comparison['after']->import_batch_id);
        $this->assertSame([$firstAfter->id, $secondAfter->id], $trend->modelKeys());
    }

    public function test_monthly_cost_metric_discloses_its_scope_and_does_not_claim_actual_savings(): void
    {
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('月次・年次の契約を通貨別に月額換算')
            ->assertSee('通貨間の換算はせず')
            ->assertSee('一時契約・その他の請求周期は含みません')
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

        $this->assertStringContainsString('記録された削減実績（CAD・年額）,realized_savings_CAD_annual,9876543.21,', $csv);
        $this->assertStringNotContainsString('realized_savings_EUR_one_time', $csv);
        $this->assertStringNotContainsString('重複登録', $csv);
    }

    public function test_impact_report_shows_time_estimates_separately_from_cash_savings(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        WorkTimeMeasurement::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'before_minutes' => 60,
            'after_minutes' => 30,
            'monthly_occurrences' => 4,
            'hourly_rate' => '3000.00',
            'currency' => 'JPY',
        ]);
        WorkTimeMeasurement::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'before_minutes' => 50,
            'after_minutes' => 10,
            'monthly_occurrences' => 8,
            'hourly_rate' => '2500.00',
            'currency' => 'EUR',
            'voided_by' => $owner->id,
            'voided_at' => now(),
            'void_reason' => '重複計測',
        ]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('作業時間の変化（担当者入力の参考値）')
            ->assertSee('2.00時間短縮')
            ->assertSee('JPY 6,000.00')
            ->assertSee('実際の経費削減ではありません')
            ->assertDontSee('EUR 13,333.33');

        $csv = $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.download'))
            ->streamedContent();

        $this->assertStringContainsString('月あたり作業時間短縮（分）,work_time_monthly_minutes,120,', $csv);
        $this->assertStringContainsString('月あたり作業時間短縮（時間）,work_time_monthly_hours,2,', $csv);
        $this->assertStringContainsString('作業時間の金額換算参考値（月あたり・JPY）,work_time_labor_equivalent_JPY,6000.00,', $csv);
        $this->assertStringNotContainsString('work_time_labor_equivalent_EUR', $csv);
        $this->assertStringNotContainsString('重複計測', $csv);
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

    public function test_impact_report_pending_answers_require_server_recorded_approval(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '承認状態の確認',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $owner->id,
        ]);
        $unapprovedQuestion = QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '未承認の回答',
            'answer_type' => 'text',
            'sort_order' => 1,
        ]);
        $approvedQuestion = QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '承認済みの回答',
            'answer_type' => 'text',
            'sort_order' => 2,
        ]);
        QuestionnaireAnswer::query()->create([
            'question_id' => $unapprovedQuestion->id,
            'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
            'source_type' => 'FACT',
        ]);
        QuestionnaireAnswer::query()->create([
            'question_id' => $approvedQuestion->id,
            'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'source_type' => 'FACT',
        ]);

        $metrics = app(ImpactReportMetricsService::class)->current($organization);

        $this->assertSame(1, $metrics['questionnaire_pending']);
        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('impact-report.show'))
            ->assertOk()
            ->assertSee('未承認の質問票回答</dt><dd>1件</dd>', false);
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
