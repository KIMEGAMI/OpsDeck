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
use App\Models\ProductEvent;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Models\User;
use App\Services\ContractRenewalWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_metrics_use_a_compact_mobile_grid_without_overflowing_values(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->ownerOrganization();
        $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($layout);
        $this->assertIsString($styles);
        $this->assertStringContainsString('font-size: clamp(1rem, 4vw, 1.4rem);', $layout);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $layout);
        $this->assertStringContainsString("@vite(['resources/css/app.css', 'resources/js/app.js'])", $layout);
        $this->assertStringContainsString('.skip-link:focus-visible', $styles);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('<a class="skip-link" href="#main-content">本文へスキップ</a>', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('クラウドサービス')
            ->assertSee('Excelなどの取込')
            ->assertSee('費用の見直し')
            ->assertSee('保存した一覧')
            ->assertSee(route('saved-views.index'))
            ->assertSee('使い方・よくある質問')
            ->assertSee('月額費用合計（円）')
            ->assertSee('前に保存した一覧を見る')
            ->assertSee('<summary>よく使う機能を開く</summary>', false)
            ->assertSee('<nav class="dashboard-links" aria-label="よく使う機能">', false)
            ->assertSee('直近7日間のまとめを見る')
            ->assertSee('メールが届かないとき')
            ->assertSee('無料公開について')
            ->assertSee('@media (max-width: 48rem)', false)
            ->assertSee('repeat(2, minmax(0, 1fr))', false);
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('dashboard'), '/').'"\s+class="is-active"\s+aria-current="page"/',
            $response->getContent(),
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="'.preg_quote(route('imports.index'), '/').'"\s+class="is-active"\s+aria-current="page"/',
            $response->getContent(),
        );
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

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('社員・パソコン・契約など、会社の情報をまとめて確認する画面です。')
            ->assertDontSee('更新期限の確認、書類の保管、セキュリティ確認、質問票は有料プラン（Premium）で利用できます。')
            ->assertSee('10,000')
            ->assertDontSee('10,000.00')
            ->assertSee('期限切れ・'.ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の更新アラート')
            ->assertSee('要対応セキュリティ項目')
            ->assertSee('取込で確認が必要な行')
            ->assertSee('取り込みに失敗した履歴')
            ->assertSee('今日やること')
            ->assertSee('<details class="dashboard-panel">', false)
            ->assertSee('<summary>すべての注意件数を見る</summary>', false)
            ->assertSee(route('costs.dashboard'))
            ->assertSee(route('security-controls.index', ['attention' => 1]))
            ->assertSee(route('evidence.index', ['expired' => 1]))
            ->assertSee(route('imports.index', ['has_errors' => 1, 'unreviewed' => 1]))
            ->assertSee(route('imports.index', ['status' => 'failed', 'unreviewed' => 1]))
            ->assertSee(route('employees.index'))
            ->assertSee(route('assets.index', ['needs_attention' => 1]))
            ->assertSee(route('saas-services.index', ['needs_attention' => 1]))
            ->assertSee(route('contracts.index'))
            ->assertSee(route('settings.members'))
            ->assertSee('未割当または退職者に紐づく資産を確認する')
            ->assertSee('利用者未登録・退職済み社員のサービスを確認する')
            ->assertSee('（1件）')
            ->assertSee('（2件）')
            ->assertSeeInOrder([
                '期限切れ証跡を確認する',
                '期限切れ・'.ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の契約期限を確認する',
                '取込で確認が必要な行を確認する',
                '取り込みに失敗した履歴を確認する',
                '未対応のセキュリティ項目を確認する',
                '未割当または退職者に紐づく資産を確認する',
                '利用者未登録・退職済み社員のサービスを確認する',
            ]);

        $this->assertDatabaseMissing('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_free_dashboard_shows_available_checks_without_paid_plan_upsell(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->ownerOrganization();
        $organization->update(['plan_code' => config('opsdeck.free_plan_code')]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('このプランで確認できる範囲では、優先対応が必要な項目はありません。')
            ->assertDontSee('Premium')
            ->assertDontSee('有料プラン')
            ->assertSee(route('billing.index'))
            ->assertSee('無料公開について');
    }

    public function test_free_dashboard_does_not_show_billing_link_to_non_owner_members(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [, $organization] = $this->ownerOrganization();
        $viewer = User::factory()->create(['email' => 'dashboard-viewer-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $viewer->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Premium')
            ->assertDontSee('有料プラン')
            ->assertDontSee(route('billing.index'));
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
            ->assertDontSee('利用上限について問い合わせる')
            ->assertDontSee('次に行うこと')
            ->assertDontSee('管理表のImportを始める');
    }

    public function test_owner_sees_a_compact_import_and_onboarding_next_step(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$owner, $organization] = $this->ownerOrganization();

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('初めて使う方へ')
            ->assertSee('今使っている管理表のコピーを選んでください。元のファイルは変更せず、登録前に内容を確認できます。')
            ->assertSee('管理表のコピーを選んで確認する')
            ->assertSee('確認しただけでは会社のデータは変わりません。内容を確かめてから、取込を実行できます。')
            ->assertDontSee('Migration Studio')
            ->assertDontSee('Preview')
            ->assertSee(route('imports.create'))
            ->assertSee(route('onboarding.index'));
    }

    public function test_owner_dashboard_hides_the_first_time_panel_after_onboarding_is_complete(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$owner, $organization] = $this->ownerOrganization();
        ProductEvent::query()->create([
            'organization_id' => $organization->id,
            'event_key' => 'onboarding_completed',
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('first-step-title', false)
            ->assertDontSee('初めて使う方へ')
            ->assertSee('導入チェックリストを確認');
    }

    public function test_dashboard_layout_keeps_high_visibility_keyboard_focus_indicators(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->ownerOrganization();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(':focus-visible { outline: 3px solid #005f73; outline-offset: 2px; }', false)
            ->assertSee('header :focus-visible { outline-color: #f6c945; }', false)
            ->assertSee('.app-navigation a:focus-visible { outline-color: #f6c945; }', false)
            ->assertSee('--interactive-target-min-height: 2.75rem;', false)
            ->assertSee('input:not([type="checkbox"]):not([type="radio"]), select, textarea', false)
            ->assertSee('input[type="checkbox"], input[type="radio"] { width: 1.25rem; height: 1.25rem;', false)
            ->assertSee('min-height: var(--interactive-target-min-height);', false)
            ->assertSee('<div class="desktop-navigation">', false)
            ->assertSee('<details class="mobile-navigation">', false)
            ->assertSee('メニューを開く', false)
            ->assertSee('メニューを閉じる', false)
            ->assertSee('.desktop-navigation { display: none; }', false)
            ->assertSee('.mobile-navigation[open] > .app-navigation { display: flex;', false)
            ->assertDontSee('outline: none', false);
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
            ->assertSee('期限切れ・'.ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の契約期限を確認する</a>（1件）', false);
    }

    public function test_dashboard_includes_cancellation_deadlines_in_its_alert_count(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->ownerOrganization();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Cancellation vendor', 'contract_name' => 'Cancellation cutoff soon', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'cancellation_deadline' => today()->addDays(10)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('期限切れ・'.ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の契約期限を確認する</a>（1件）', false);
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
            ->assertSee('期限切れ・'.ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の契約期限を確認する</a>（1件）', false);
    }

    public function test_dashboard_pending_answers_require_server_recorded_approval(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->ownerOrganization();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '承認状態の確認',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
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
            'approved_by' => $user->id,
            'approved_at' => now(),
            'source_type' => 'FACT',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('未承認の質問票回答を確認する</a>（1件）', false);
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
