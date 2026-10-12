<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRead;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportReviewCandidate;
use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\ProductEvent;
use App\Models\ReferralCode;
use App\Models\ReferralConversion;
use App\Models\User;
use App\Services\ImportFailureMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProductMetricsControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_view_event_counts_without_pii(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-admin-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        ProductEvent::query()->create(['event_key' => 'registered', 'occurred_at' => now()]);
        $response = $this->actingAs($admin)->get(route('admin.product-metrics'));

        $response->assertOk()->assertSee('registered')->assertSee('製品利用状況')->assertSee('プラン上限に近い組織数')->assertSee('利用率'.config('opsdeck.limits.warning_percentage').'%以上')->assertDontSee($admin->email);
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
            ->assertSee('登録組織数（全期間）</dt><dd>4', false)
            ->assertSee('登録後7日経過</dt><dd>3', false)
            ->assertSee('7日以内に初回Preview到達した組織数</dt><dd>3', false)
            ->assertSee('7日以内に初回Import完了した組織数</dt><dd>2', false)
            ->assertSee('Activation率</dt><dd>66.67%', false)
            ->assertSee('Previewから完了への転換率</dt><dd>66.67%', false)
            ->assertSee('平均Time to Value</dt><dd>108時間', false);
    }

    public function test_plan_limit_metrics_count_organizations_at_the_limit(): void
    {
        $admin = User::factory()->create(['email' => 'metrics-limits-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email], 'opsdeck.plans.free.limits.employees' => 1, 'opsdeck.plans.premium.limits.employees' => 2, 'opsdeck.pagination.per_page' => 1]);
        $organization = Organization::query()->create(['name' => 'Metrics Limits '.Str::random(8), 'slug' => 'metrics-limits-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
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
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $organization = Organization::query()->create(['name' => 'Metrics Admin Seat '.Str::random(8), 'slug' => 'metrics-admin-seat-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
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
        ImportReviewCandidate::query()->create(['import_batch_id' => $batch->id, 'candidate_type' => 'duplicate', 'source_row_numbers_json' => [1], 'confidence' => 0.9, 'details_json' => [], 'status' => 'accepted', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        ImportReviewCandidate::query()->create(['import_batch_id' => $batch->id, 'candidate_type' => 'duplicate', 'source_row_numbers_json' => [2], 'confidence' => 0.8, 'details_json' => [], 'status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        OnboardingStep::query()->create(['organization_id' => $organization->id, 'step_key' => 'renewal_checked', 'completed_at' => now(), 'completed_by' => $admin->id]);
        Organization::query()->create(['name' => 'Metrics Cancelled '.Str::random(8), 'slug' => 'metrics-cancelled-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => 'cancelled']);
        $reviewed = ImportReviewCandidate::query()->whereNotNull('reviewed_at')->count();
        $approved = ImportReviewCandidate::query()->where('status', 'accepted')->whereNotNull('reviewed_at')->count();
        $approvalRate = round(($approved / $reviewed) * 100, 2);
        $renewalChecks = OnboardingStep::query()->where('step_key', 'renewal_checked')->count();
        $totalBillable = ProductEvent::query()->where('event_key', 'billing_active')->whereNotNull('organization_id')->distinct('organization_id')->count('organization_id');
        $cancelled = ProductEvent::query()->where('event_key', 'billing_cancelled')->whereNotNull('organization_id')->distinct('organization_id')->count('organization_id');
        $cancellationRate = $totalBillable > 0 ? round(($cancelled / $totalBillable) * 100, 2) : 0.0;

        $this->actingAs($admin)->get(route('admin.product-metrics'))->assertOk()->assertSee('AI候補の人手承認率：'.$approvalRate.'%')->assertSee('更新期限確認数：'.$renewalChecks.'件')->assertSee('累計解約率：'.$cancellationRate.'%');
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
        $this->assertStringContainsString('announcement_delivery_manual_review', $response->streamedContent());
        $this->assertStringContainsString('weekly_summary_delivery_manual_review', $response->streamedContent());
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

        $this->actingAs($admin)->get(route('admin.product-metrics'))->assertOk()->assertSee('招待参加転換率：'.$rate.'%');
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
}
