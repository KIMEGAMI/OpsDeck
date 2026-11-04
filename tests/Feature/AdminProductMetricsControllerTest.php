<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRead;
use App\Models\CancellationFeedback;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportReviewCandidate;
use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\ProductEvent;
use App\Models\ReferralCode;
use App\Models\ReferralConversion;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\ImportFailureMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProductMetricsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_view_event_counts_without_pii(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-admin-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        ProductEvent::query()->create(['event_key' => 'registered', 'occurred_at' => now()]);
        $response = $this->actingAs($admin)->get(route('admin.product-metrics'));

        $response->assertOk()->assertSee('新規登録')->assertSee('製品利用状況')->assertSee('AI候補の人手採用率：計測データなし（確認対象0件）')->assertSee('プラン上限に近い組織数')->assertSee('利用率'.config('opsdeck.limits.warning_percentage').'%以上')->assertDontSee('<td>registered</td>', false)->assertDontSee($admin->email);
    }

    public function test_privacy_support_category_is_displayed_as_a_human_readable_metric(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-privacy-support-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        SupportRequest::query()->create([
            'category' => 'privacy',
            'message' => '個人情報に関する問い合わせ本文',
            'reference_code' => 'SUP-00000000-0000-4000-8000-000000000099',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('個人情報・データの取扱い')
            ->assertDontSee('個人情報に関する問い合わせ本文');
    }

    public function test_support_status_metrics_use_japanese_labels_and_hide_unknown_status_codes(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-support-status-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        foreach (['open', 'in_progress', 'resolved', 'legacy_status'] as $status) {
            SupportRequest::query()->create([
                'category' => 'usage',
                'message' => '問い合わせ内容',
                'status' => $status,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('admin.product-metrics'))->assertOk();

        foreach (['受付', '対応中', '解決済み', '状態を確認してください'] as $label) {
            $response->assertSee('<td>'.$label.'</td>', false);
        }

        foreach (['open', 'in_progress', 'resolved', 'legacy_status'] as $status) {
            $response->assertDontSee('<td>'.$status.'</td>', false);
        }
    }

    public function test_cancellation_feedback_reasons_are_human_readable_and_scoped_as_self_reported(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-cancellation-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $organization = Organization::query()->create([
            'name' => 'Cancellation feedback organization',
            'slug' => 'cancellation-feedback-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);
        CancellationFeedback::query()->create([
            'organization_id' => $organization->id,
            'reason' => 'missing_feature',
            'details' => '社内状況がわかる補足文は画面に表示しない',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('解約検討・実施理由（自己申告）')
            ->assertSee('必要な機能がない')
            ->assertSee('実際の解約組織数とは一致しません')
            ->assertDontSee('missing_feature')
            ->assertDontSee('社内状況がわかる補足文は画面に表示しない');
    }

    public function test_activation_metrics_use_only_matured_seven_day_registration_cohorts(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $admin = User::factory()->create(['email' => 'metrics-activation-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        $activatedOrganization = $this->organizationForActivationMetrics('activated');
        $previewedOrganization = $this->organizationForActivationMetrics('previewed');
        $boundaryOrganization = $this->organizationForActivationMetrics('boundary');
        $recentOrganization = $this->organizationForActivationMetrics('recent');

        $this->recordActivationEvent($activatedOrganization, 'registered', '2026-10-01 12:00:00');
        $this->recordActivationEvent($activatedOrganization, 'first_import_previewed', '2026-10-02 12:00:00');
        $this->recordActivationEvent($activatedOrganization, 'first_import_completed', '2026-10-03 12:00:00');
        $this->recordActivationEvent($previewedOrganization, 'registered', '2026-10-01 12:00:00');
        $this->recordActivationEvent($previewedOrganization, 'first_import_previewed', '2026-10-02 12:00:00');
        $this->recordActivationEvent($boundaryOrganization, 'registered', '2026-10-03 12:00:00');
        $this->recordActivationEvent($boundaryOrganization, 'first_import_previewed', '2026-10-04 12:00:00');
        $this->recordActivationEvent($boundaryOrganization, 'first_import_completed', '2026-10-10 12:00:00');
        $this->recordActivationEvent($recentOrganization, 'registered', '2026-10-08 12:00:00');
        $this->recordActivationEvent($recentOrganization, 'first_import_previewed', '2026-10-09 12:00:00');

        $this->actingAs($admin)->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('Activationは「登録」イベントが記録されている組織を対象にします。イベント計測開始前に登録された組織は含まれません。', false)
            ->assertSee('登録イベント記録済み組織数</dt><dd>4', false)
            ->assertSee('登録後7日経過</dt><dd>3', false)
            ->assertSee('7日以内に初回Preview到達した組織数</dt><dd>3', false)
            ->assertSee('7日以内のPreview到達率</dt><dd>100%', false)
            ->assertSee('7日以内に初回Import完了した組織数</dt><dd>2', false)
            ->assertSee('Activation率</dt><dd>66.67%', false)
            ->assertSee('Previewから完了への転換率</dt><dd>66.67%', false)
            ->assertSee('平均Time to Value</dt><dd>108時間', false);
    }

    public function test_plan_limit_metrics_count_organizations_at_the_limit(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-limits-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email], 'opsdeck.plans.free.limits.employees' => 1, 'opsdeck.plans.premium.limits.employees' => 2, 'opsdeck.pagination.per_page' => 1]);
        $organization = Organization::query()->create(['name' => 'Metrics Limits '.Str::random(8), 'slug' => 'metrics-limits-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => 'inactive']);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '上限社員', 'employment_status' => 'active']);
        $premiumOrganization = Organization::query()->create(['name' => 'Metrics Premium '.Str::random(8), 'slug' => 'metrics-premium-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => config('opsdeck.default_billing_status')]);
        Employee::query()->create(['organization_id' => $premiumOrganization->id, 'name' => 'Premium社員', 'employment_status' => 'active']);

        $this->actingAs($admin)->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('プラン上限に近い組織数：')
            ->assertSee('上限到達 1組織');
    }

    public function test_plan_limit_metrics_include_pending_administrator_invitations(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-admin-invite-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email], 'opsdeck.plans.free.limits.administrators' => 2]);
        $organization = Organization::query()->create(['name' => 'Metrics Admin Seat '.Str::random(8), 'slug' => 'metrics-admin-seat-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => 'inactive']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $admin->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        OrganizationInvitation::query()->create(['organization_id' => $organization->id, 'invited_by' => $admin->id, 'email' => 'pending-admin-'.Str::uuid().'@example.test', 'role' => MembershipRole::Admin, 'token_hash' => hash('sha256', Str::uuid()->toString()), 'expires_at' => now()->addDay()]);

        $this->actingAs($admin)->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('上限到達 1組織');
    }

    public function test_platform_admin_can_view_import_failure_rate(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-import-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $organization = Organization::query()->create(['name' => 'Metrics Import '.Str::random(8), 'slug' => 'metrics-import-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'completed', 'status' => 'completed']);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed', 'status' => 'failed']);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'preview-only', 'status' => 'preview']);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'cancelled', 'status' => 'cancelled']);
        $metrics = app(ImportFailureMetrics::class)->summarize();

        $this->actingAs($admin)->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('Import処理失敗率（完了・失敗した処理）：'.$metrics['rate'].'%')
            ->assertSee('対象'.$metrics['total'].'件 / 失敗'.$metrics['failed'].'件');
    }

    public function test_platform_admin_can_view_ai_approval_and_renewal_confirmation_metrics(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-ai-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $organization = Organization::query()->create(['name' => 'Metrics AI '.Str::random(8), 'slug' => 'metrics-ai-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'candidates', 'status' => 'preview']);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['氏名' => 'name', '社員番号' => 'department_name'],
            'normalization_rules' => [],
            'ai_suggestion_count' => 2,
            'ai_suggestion_accepted_count' => 1,
            'status' => 'ready',
            'created_by' => $admin->id,
        ]);
        ImportReviewCandidate::query()->create(['import_batch_id' => $batch->id, 'candidate_type' => 'duplicate', 'source_row_numbers_json' => [1], 'confidence' => 0.9, 'details_json' => [], 'status' => 'accepted', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        ImportReviewCandidate::query()->create(['import_batch_id' => $batch->id, 'candidate_type' => 'duplicate', 'source_row_numbers_json' => [2], 'confidence' => 0.8, 'details_json' => [], 'status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        OnboardingStep::query()->create(['organization_id' => $organization->id, 'step_key' => 'renewal_checked', 'completed_at' => now(), 'completed_by' => $admin->id]);
        Organization::query()->create(['name' => 'Metrics Cancelled '.Str::random(8), 'slug' => 'metrics-cancelled-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => 'cancelled']);
        $reviewed = (int) ImportMapping::query()->whereNotNull('ai_suggestion_count')->sum('ai_suggestion_count');
        $approved = (int) ImportMapping::query()->whereNotNull('ai_suggestion_count')->sum('ai_suggestion_accepted_count');
        $approvalRate = round(($approved / $reviewed) * 100, 2);
        $renewalChecks = OnboardingStep::query()->where('step_key', 'renewal_checked')->count();
        $totalBillable = ProductEvent::query()->where('event_key', 'billing_active')->whereNotNull('organization_id')->distinct('organization_id')->count('organization_id');
        $cancelled = ProductEvent::query()->where('event_key', 'billing_cancelled')->whereNotNull('organization_id')->distinct('organization_id')->count('organization_id');
        $cancellationRate = $totalBillable > 0 ? round(($cancelled / $totalBillable) * 100, 2) : 0.0;

        $this->actingAs($admin)->get(route('admin.product-metrics'))->assertOk()->assertSee('AI候補の人手採用率：'.$approvalRate.'%')->assertSee('更新期限確認数：'.$renewalChecks.'件')->assertSee('累計解約率：'.$cancellationRate.'%');
    }

    public function test_platform_admin_sees_business_change_label_for_legacy_event_key(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-business-change-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        ProductEvent::query()->create([
            'event_key' => 'first_dashboard_action_completed',
            'occurred_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('初回業務変更成功')
            ->assertSee('画面を開いただけでは記録されません。')
            ->assertDontSee('first_dashboard_action_completed');
    }

    public function test_product_event_metrics_use_japanese_labels_and_hide_unknown_keys(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-event-labels-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $eventLabels = config('opsdeck.product_event_labels');
        $eventKeys = [...array_keys($eventLabels), 'legacy_unknown_event'];

        foreach ($eventKeys as $eventKey) {
            ProductEvent::query()->create(['event_key' => $eventKey, 'occurred_at' => now()]);
        }

        $response = $this->actingAs($admin)->get(route('admin.product-metrics'))->assertOk();

        foreach ($eventLabels as $label) {
            $response->assertSee('<td>'.$label.'</td>', false);
        }

        $response->assertSee('<td>その他の計測イベント</td>', false);

        foreach ($eventKeys as $eventKey) {
            $response->assertDontSee('<td>'.$eventKey.'</td>', false);
        }
    }

    public function test_platform_admin_can_view_referral_conversion_metrics(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-referral-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $organization = Organization::query()->create(['name' => 'Metrics Referral '.Str::random(8), 'slug' => 'metrics-referral-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $referral = ReferralCode::query()->create(['organization_id' => $organization->id, 'code' => 'metrics-'.Str::lower(Str::random(8)), 'click_count' => 4, 'active' => true]);
        ReferralConversion::query()->create(['referral_code_id' => $referral->id, 'organization_id' => $organization->id]);

        $clicks = (int) ReferralCode::query()->sum('click_count');
        $registrations = ReferralConversion::query()->count();
        $rate = round(($registrations / $clicks) * 100, 2);
        $this->actingAs($admin)->get(route('admin.product-metrics'))->assertOk()->assertSee('紹介転換率：'.$rate.'%');
    }

    public function test_platform_admin_can_view_announcement_read_rate(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-read-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $announcement = Announcement::query()->create(['title' => '既読率', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()->subYears(10), 'recipients_count' => 4]);
        AnnouncementRead::query()->create(['announcement_id' => $announcement->id, 'user_id' => $admin->id, 'read_at' => now()]);
        $recipients = (int) Announcement::query()->sum('recipients_count');
        $reads = AnnouncementRead::query()->count();
        $rate = $recipients > 0 ? round(($reads / $recipients) * 100, 2) : 0.0;

        $this->actingAs($admin)->get(route('admin.product-metrics'))->assertOk()->assertSee('お知らせ既読率：'.$rate.'%');
    }

    public function test_product_metrics_csv_contains_recent_event_counts(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-csv-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        ProductEvent::query()->create(['event_key' => 'csv_recent', 'occurred_at' => now()->subDays(2)]);

        $response = $this->actingAs($admin)->get(route('admin.product-metrics.export'));

        $response->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
        $this->assertStringContainsString('event_30d:csv_recent', $response->streamedContent());
        $this->assertStringContainsString('organizations_near_plan_limit', $response->streamedContent());
        $this->assertStringContainsString('organizations_at_plan_limit', $response->streamedContent());
        $this->assertStringContainsString('plan_limit_warning_percentage', $response->streamedContent());
        $this->assertStringContainsString('activation_eligible_organizations', $response->streamedContent());
        $this->assertStringContainsString('activation_tracking_scope', $response->streamedContent());
        $this->assertStringContainsString('イベント計測開始前の登録は含まれない。', $response->streamedContent());
        $this->assertStringContainsString('preview_activation_rate', $response->streamedContent());
        $this->assertStringContainsString('announcement_delivery_manual_review', $response->streamedContent());
        $this->assertStringContainsString('weekly_summary_delivery_manual_review', $response->streamedContent());
        $this->assertStringContainsString('invitation_created', $response->streamedContent());
        $this->assertStringContainsString('invitation_email_delivery_success_rate', $response->streamedContent());
        $this->assertStringContainsString('invitation_email_manual_review', $response->streamedContent());
    }

    public function test_product_metrics_csv_exports_only_aggregated_cancellation_feedback_reasons(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-cancellation-csv-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $organization = Organization::query()->create([
            'name' => 'Cancellation CSV '.Str::random(8),
            'slug' => 'cancellation-csv-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);
        CancellationFeedback::query()->create([
            'organization_id' => $organization->id,
            'reason' => 'price',
            'details' => 'この自由記述を集計CSVへ含めてはいけない',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.product-metrics.export'));
        $csv = $response->streamedContent();

        $response->assertOk();
        $this->assertStringContainsString('cancellation_feedback_scope,', $csv);
        $this->assertStringContainsString('cancellation_reason:price,1', $csv);
        $this->assertStringNotContainsString('この自由記述を集計CSVへ含めてはいけない', $csv);
        $this->assertStringNotContainsString($organization->name, $csv);
    }

    public function test_product_metrics_csv_exports_first_business_change_by_organization_and_preserves_legacy_event_count(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-business-change-csv-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $organizations = collect(range(1, 2))->map(fn (int $number): Organization => Organization::query()->create([
            'name' => 'Metrics Business Change '.$number.' '.Str::random(8),
            'slug' => 'metrics-business-change-'.$number.'-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]));

        foreach ($organizations as $organization) {
            ProductEvent::query()->create([
                'event_key' => 'first_dashboard_action_completed',
                'organization_id' => $organization->id,
                'occurred_at' => now(),
            ]);
        }

        ProductEvent::query()->create([
            'event_key' => 'first_dashboard_action_completed',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.product-metrics.export'));

        $response->assertOk();
        $this->assertStringContainsString('first_business_change_completed_organizations,2', $response->streamedContent());
        $this->assertStringContainsString('event:first_dashboard_action_completed,3', $response->streamedContent());
    }

    public function test_platform_admin_can_view_invitation_conversion_metrics(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-invite-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        ProductEvent::query()->create(['event_key' => 'member_invited', 'occurred_at' => now()]);
        ProductEvent::query()->create(['event_key' => 'member_joined', 'occurred_at' => now()]);
        $sent = ProductEvent::query()->where('event_key', 'member_invited')->count();
        $joined = ProductEvent::query()->where('event_key', 'member_joined')->count();
        $rate = round(($joined / $sent) * 100, 2);

        $this->actingAs($admin)->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('招待登録から参加への転換率：'.$rate.'%')
            ->assertSee('メール事業者による配信成功数とは異なります。');
    }

    public function test_invitation_delivery_metrics_include_only_tracked_outcomes(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-invitation-delivery-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $organization = Organization::query()->create([
            'name' => 'Metrics Invitation Delivery',
            'slug' => 'metrics-invitation-delivery-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $this->createInvitationDelivery($admin, $organization, 'sent', ['invitation_email_sent_at' => now()]);
        $this->createInvitationDelivery($admin, $organization, 'failed', ['invitation_email_failed_at' => now()]);
        $this->createInvitationDelivery($admin, $organization, 'review', [
            'invitation_email_failed_at' => now(),
            'invitation_email_manual_review_at' => now(),
        ]);
        $this->createInvitationDelivery($admin, $organization, 'unattempted', []);
        $trackedDeliveries = OrganizationInvitation::query()
            ->whereNotNull('invitation_email_sent_at')
            ->orWhereNotNull('invitation_email_failed_at')
            ->orWhereNotNull('invitation_email_manual_review_at')
            ->count();
        $sentDeliveries = OrganizationInvitation::query()->whereNotNull('invitation_email_sent_at')->count();
        $deliverySuccessRate = round(($sentDeliveries / $trackedDeliveries) * 100, 2);
        $failedDeliveries = OrganizationInvitation::query()
            ->whereNotNull('invitation_email_failed_at')
            ->whereNull('invitation_email_sent_at')
            ->whereNull('invitation_email_manual_review_at')
            ->count();
        $manualReviews = OrganizationInvitation::query()
            ->whereNotNull('invitation_email_manual_review_at')
            ->whereNull('invitation_email_sent_at')
            ->count();

        $this->actingAs($admin)->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('招待メール成功率（追跡済み結果）</dt><dd>'.$deliverySuccessRate.'%', false)
            ->assertSee('招待メール失敗</dt><dd>'.$failedDeliveries, false)
            ->assertSee('招待メール手動確認</dt><dd>'.$manualReviews, false)
            ->assertDontSee('metrics-invitation-delivery-');
    }

    public function test_platform_admin_can_view_delivery_success_rates(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-delivery-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $announcement = Announcement::query()->create(['title' => '配信停止を除外した成功率', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()]);
        AnnouncementDelivery::query()->create(['announcement_id' => $announcement->id, 'user_id' => $admin->id, 'failed_at' => now(), 'suppressed_at' => now()]);
        $total = AnnouncementDelivery::query()->whereNull('suppressed_at')->count();
        $sent = AnnouncementDelivery::query()->whereNotNull('sent_at')->count();
        $rate = $total > 0 ? round(($sent / $total) * 100, 2) : 0.0;

        $this->actingAs($admin)->get(route('admin.product-metrics'))->assertOk()->assertSee('お知らせ成功率')->assertSee($rate.'%');
    }

    private function organizationForActivationMetrics(string $suffix): Organization
    {
        return Organization::query()->create([
            'name' => 'Metrics Activation '.$suffix,
            'slug' => 'metrics-activation-'.$suffix.'-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
    }

    private function recordActivationEvent(Organization $organization, string $eventKey, string $occurredAt): void
    {
        ProductEvent::query()->create([
            'organization_id' => $organization->id,
            'event_key' => $eventKey,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @param array<string, mixed> $deliveryState */
    private function createInvitationDelivery(User $admin, Organization $organization, string $suffix, array $deliveryState): OrganizationInvitation
    {
        return OrganizationInvitation::query()->create(array_merge([
            'organization_id' => $organization->id,
            'invited_by' => $admin->id,
            'email' => 'metrics-invitation-'.$suffix.'-'.Str::uuid().'@example.test',
            'role' => MembershipRole::Viewer,
            'token_hash' => hash('sha256', Str::uuid()->toString()),
            'expires_at' => now()->addDays(config('opsdeck.invitation_expiry_days')),
        ], $deliveryState));
    }
}
