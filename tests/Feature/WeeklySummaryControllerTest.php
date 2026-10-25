<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\SendWeeklySummaryEmail;
use App\Mail\WeeklySummaryMail;
use App\Models\Contract;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\WeeklySummaryDelivery;
use App\Services\ContractRenewalWindow;
use App\Services\WeeklySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WeeklySummaryControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_weekly_summary_command_is_scheduled_for_monday_morning(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(static fn (Event $event): bool => str_contains((string) $event->command, 'opsdeck:weekly-summaries'));

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * 1', $event->expression);
    }

    public function test_member_can_view_summary_and_disable_delivery(): void
    {
        [$user, $organization] = $this->member();
        $session = ['current_organization_id' => $organization->id];
        $this->actingAs($user)->withSession($session)->get(route('weekly-summary.show'))
            ->assertOk()
            ->assertSee('直近7日間のまとめとメール設定')
            ->assertSee('週1回のまとめメールを受け取る')
            ->assertSee('管理者のお知らせメールを受け取る')
            ->assertSee('メールが届かないときの確認方法')
            ->assertSee(route('help.recovery', 'email'))
            ->assertSee('直近7日間に新しく起きたこと')
            ->assertSee('今も確認が必要な項目')
            ->assertDontSee('ログイン時の追加確認が済んでいないクラウドサービスのアカウント')
            ->assertSee('集計期間：')
            ->assertSee('取込に失敗した回数')
            ->assertSee(route('saas-services.index'))
            ->assertDontSee(ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内の契約更新')
            ->assertDontSee('未対応のセキュリティ管理項目')
            ->assertDontSee('MFA未確認SaaSアカウント')
            ->assertSee('無料プランでは、契約の更新期限やセキュリティ項目は表示していません。');

        $summary = app(WeeklySummaryBuilder::class)->build($organization->id);
        $this->actingAs($user)->withSession($session)
            ->get(route('imports.index', ['has_errors' => 1, 'created_after' => $summary['period_start'], 'created_before' => $summary['period_end']]))
            ->assertOk();
        $this->actingAs($user)->withSession($session)->get(route('reminders.renewals'))->assertForbidden();
        $this->actingAs($user)->withSession($session)->get(route('security-controls.index', ['attention' => 1]))->assertForbidden();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->put(route('weekly-summary.preference'), ['weekly_summary_enabled' => '0'])->assertRedirect(route('weekly-summary.show'));
        $this->assertDatabaseHas('user_notification_preferences', ['user_id' => $user->id, 'organization_id' => $organization->id, 'weekly_summary_enabled' => 0]);
    }

    public function test_member_can_opt_out_of_announcement_emails_while_keeping_weekly_summaries(): void
    {
        [$user, $organization] = $this->member();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('weekly-summary.preference'), [
                'weekly_summary_enabled' => '1',
                'announcement_enabled' => '0',
            ])
            ->assertRedirect(route('weekly-summary.show'));

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'weekly_summary_enabled' => 1,
            'announcement_enabled' => 0,
        ]);
    }

    public function test_premium_summary_links_open_their_protected_pages(): void
    {
        [$user, $organization] = $this->member();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)->get(route('weekly-summary.show'))
            ->assertOk()
            ->assertSee(route('reminders.renewals'))
            ->assertSee(route('security-controls.index', ['attention' => 1]))
            ->assertSee('ログイン時の追加確認が済んでいないクラウドサービスのアカウント');
        $this->actingAs($user)->withSession($session)->get(route('reminders.renewals'))->assertOk();
        $this->actingAs($user)->withSession($session)->get(route('security-controls.index', ['attention' => 1]))->assertOk();
    }

    public function test_weekly_summary_job_sends_only_when_handled_and_deduplicates(): void
    {
        Mail::fake();
        [$user, $organization] = $this->member();
        $job = new SendWeeklySummaryEmail(
            $user->id,
            $organization->id,
            app(WeeklySummaryBuilder::class)->build($organization->id),
            now()->startOfWeek()->toDateString(),
        );
        $this->assertDatabaseMissing('weekly_summary_deliveries', ['user_id' => $user->id, 'organization_id' => $organization->id]);
        $job->handle();
        $job->handle();

        Mail::assertSentTimes(WeeklySummaryMail::class, 1);
        Mail::assertSent(WeeklySummaryMail::class, function (WeeklySummaryMail $mail) use ($organization): bool {
            $this->assertStringContainsString(
                route('weekly-summary.show', ['organization_id' => $organization->id]),
                $mail->render(),
            );

            return true;
        });
        $this->assertDatabaseHas('weekly_summary_deliveries', ['user_id' => $user->id, 'organization_id' => $organization->id]);
    }

    public function test_database_queue_encrypts_weekly_summary_metrics_before_persistence(): void
    {
        config(['queue.default' => 'database']);
        $summary = ['period_end' => '2026-10-15', 'import_errors' => 3, 'private_metric' => 'WEEKLY_SUMMARY_SECRET_SENTINEL'];
        $job = new SendWeeklySummaryEmail(987654321, 987654322, $summary, '2026-10-12');

        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $job::dispatch($job->userId, $job->organizationId, $job->summary, $job->weekStart);

        $queuedJob = DB::table('jobs')->sole();
        try {
            $this->assertStringNotContainsString('WEEKLY_SUMMARY_SECRET_SENTINEL', $queuedJob->payload);
            $this->assertStringNotContainsString('987654321', $queuedJob->payload);
            $this->assertStringNotContainsString('987654322', $queuedJob->payload);

            $payload = json_decode($queuedJob->payload, true, 512, JSON_THROW_ON_ERROR);
            $decryptedCommand = Crypt::decryptString($payload['data']['command']);
            $this->assertStringContainsString('WEEKLY_SUMMARY_SECRET_SENTINEL', $decryptedCommand);
            $this->assertStringContainsString('987654321', $decryptedCommand);
            $this->assertStringContainsString('987654322', $decryptedCommand);
        } finally {
            DB::table('jobs')->where('id', $queuedJob->id)->delete();
        }
    }

    public function test_weekly_summary_settings_link_selects_only_an_active_member_organization(): void
    {
        [$user, $linkedOrganization] = $this->member();
        [, $otherOrganization] = $this->member();
        $secondOrganization = Organization::query()->create([
            'name' => 'Summary second organization',
            'slug' => 'summary-second-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $secondOrganization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $secondOrganization->id])
            ->get(route('weekly-summary.show', ['organization_id' => $linkedOrganization->id]))
            ->assertOk();

        $this->assertSame($linkedOrganization->id, session('current_organization_id'));

        $this->actingAs($user)->withSession(['current_organization_id' => $linkedOrganization->id])
            ->get(route('weekly-summary.show', ['organization_id' => $otherOrganization->id]))
            ->assertForbidden();

        $this->actingAs($user)->withSession(['current_organization_id' => $linkedOrganization->id])
            ->get('/weekly-summary?organization_id%5B%5D=1')
            ->assertNotFound();
    }

    public function test_weekly_summary_email_job_uses_configured_retry_backoff(): void
    {
        config(['mail.delivery_job_backoff_seconds' => [19, 89]]);

        $job = new SendWeeklySummaryEmail(1, 1, [], now()->startOfWeek()->toDateString());

        $this->assertSame([19, 89], $job->backoff());
    }

    public function test_weekly_summary_uses_delivery_id_as_resend_idempotency_key(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'resend-weekly-id'])]);
        config([
            'mail.mailers.resend_idempotent.enabled' => true,
            'mail.mailers.resend_idempotent.key' => Str::random(48),
        ]);
        [$user, $organization] = $this->member();
        $job = new SendWeeklySummaryEmail(
            $user->id,
            $organization->id,
            app(WeeklySummaryBuilder::class)->build($organization->id),
            now()->startOfWeek()->toDateString(),
        );

        $job->handle();

        $delivery = WeeklySummaryDelivery::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->firstOrFail();
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'weekly-summary-delivery/'.$delivery->id));
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_resend_payload_conflict_sends_weekly_summary_to_manual_review_without_automatic_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response([
            'name' => 'invalid_idempotent_request',
            'message' => 'Payload changed for weekly-summary@example.test',
        ], 409)]);
        config([
            'mail.mailers.resend_idempotent.enabled' => true,
            'mail.mailers.resend_idempotent.key' => Str::random(48),
        ]);
        [$user, $organization] = $this->member();
        $job = new SendWeeklySummaryEmail(
            $user->id,
            $organization->id,
            app(WeeklySummaryBuilder::class)->build($organization->id),
            now()->startOfWeek()->toDateString(),
        );

        $job->handle();

        $delivery = WeeklySummaryDelivery::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->firstOrFail();
        $this->assertNotNull($delivery->failed_at);
        $this->assertNotNull($delivery->manual_review_at);
        $this->assertNull($delivery->sent_at);
        Queue::fake();
        $this->artisan('opsdeck:retry-weekly-summary-emails')->assertExitCode(0);
        Queue::assertNotPushed(SendWeeklySummaryEmail::class, fn (SendWeeklySummaryEmail $job): bool => $job->userId === $user->id && $job->organizationId === $organization->id && $job->weekStart === now()->startOfWeek()->toDateString());
        Http::assertSentCount(1);
    }

    public function test_weekly_summary_command_builds_one_aggregate_per_organization(): void
    {
        [$firstUser, $organization] = $this->member();
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 00:30:00', 'UTC'));
        $organization->update(['timezone' => 'America/Los_Angeles', 'plan_code' => config('opsdeck.premium_plan_code')]);
        [, $secondOrganization] = $this->member();
        $secondUser = User::factory()->create(['email' => 'summary-second-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $secondUser->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $defaultPreferenceUser = User::factory()->create(['email' => 'summary-default-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $defaultPreferenceUser->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $unverifiedUser = User::factory()->unverified()->create([
            'email' => 'summary-unverified-'.Str::uuid().'@example.test',
            'email_verification_required_at' => now(),
        ]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $unverifiedUser->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        UserNotificationPreference::query()->create(['user_id' => $firstUser->id, 'organization_id' => $organization->id, 'weekly_summary_enabled' => true]);
        UserNotificationPreference::query()->create(['user_id' => $secondUser->id, 'organization_id' => $organization->id, 'weekly_summary_enabled' => true]);
        UserNotificationPreference::query()->create(['user_id' => $unverifiedUser->id, 'organization_id' => $organization->id, 'weekly_summary_enabled' => true]);
        $summary = ['period_end' => '2026-10-11', 'import_errors' => 0, 'renewals' => 0, 'security_open' => 0, 'mfa_unknown' => 0];
        $summaryBuilder = $this->mock(WeeklySummaryBuilder::class);
        $builtOrganizationIds = [];
        $summaryBuilder->shouldReceive('build')->zeroOrMoreTimes()->andReturnUsing(function (int $organizationId) use (&$builtOrganizationIds, $summary): array {
            $builtOrganizationIds[] = $organizationId;

            return $summary;
        });
        Queue::fake();

        $this->artisan('opsdeck:weekly-summaries')
            ->assertExitCode(0);

        $organizationJobs = Queue::pushed(SendWeeklySummaryEmail::class)
            ->filter(fn (SendWeeklySummaryEmail $job): bool => $job->organizationId === $organization->id);
        $secondOrganizationJobs = Queue::pushed(SendWeeklySummaryEmail::class)
            ->filter(fn (SendWeeklySummaryEmail $job): bool => $job->organizationId === $secondOrganization->id);
        $this->assertCount(3, $organizationJobs);
        $this->assertCount(1, $secondOrganizationJobs);
        $this->assertTrue($organizationJobs->contains(fn (SendWeeklySummaryEmail $job): bool => $job->userId === $defaultPreferenceUser->id));
        $this->assertFalse($organizationJobs->contains(fn (SendWeeklySummaryEmail $job): bool => $job->userId === $unverifiedUser->id));
        $this->assertSame([$organization->id], array_values(array_filter($builtOrganizationIds, fn (int $organizationId): bool => $organizationId === $organization->id)));
        $this->assertSame([$secondOrganization->id], array_values(array_filter($builtOrganizationIds, fn (int $organizationId): bool => $organizationId === $secondOrganization->id)));
        $this->assertTrue($organizationJobs->every(fn (SendWeeklySummaryEmail $job): bool => $job->summary === $summary));
        $this->assertSame('2026-10-11', $organizationJobs->first()->weekStart);
    }

    public function test_weekly_summary_job_does_not_send_to_a_new_unverified_email_address(): void
    {
        Mail::fake();
        [$user, $organization] = $this->member();
        $user->forceFill(['email_verified_at' => null, 'email_verification_required_at' => now()])->save();

        $job = new SendWeeklySummaryEmail(
            $user->id,
            $organization->id,
            app(WeeklySummaryBuilder::class)->build($organization->id),
            now()->startOfWeek()->toDateString(),
        );
        $job->handle();

        Mail::assertNothingOutgoing();
        $this->assertDatabaseMissing('weekly_summary_deliveries', ['user_id' => $user->id, 'organization_id' => $organization->id]);
    }

    public function test_weekly_summary_command_dry_run_counts_recipients_without_building_or_dispatching_jobs(): void
    {
        $eligibleMemberships = OrganizationMembership::query()
            ->where('status', MembershipStatus::Active)
            ->whereHas('user', fn ($query) => $query->canReceiveNonTransactionalEmail())
            ->where(function ($query): void {
                $query->whereDoesntHave('user.notificationPreferences', function ($preferenceQuery): void {
                    $preferenceQuery->whereColumn('user_notification_preferences.organization_id', 'organization_memberships.organization_id');
                })->orWhereHas('user.notificationPreferences', function ($preferenceQuery): void {
                    $preferenceQuery->whereColumn('user_notification_preferences.organization_id', 'organization_memberships.organization_id')
                        ->where('weekly_summary_enabled', true);
                });
            });
        $eligibleOrganizationCount = (clone $eligibleMemberships)->distinct('organization_id')->count('organization_id');
        $eligibleRecipientCount = (clone $eligibleMemberships)->count();

        [$firstUser, $firstOrganization] = $this->member();
        UserNotificationPreference::query()->create([
            'user_id' => $firstUser->id,
            'organization_id' => $firstOrganization->id,
            'weekly_summary_enabled' => true,
        ]);
        [$secondUser, $secondOrganization] = $this->member();
        UserNotificationPreference::query()->create([
            'user_id' => $secondUser->id,
            'organization_id' => $secondOrganization->id,
            'weekly_summary_enabled' => true,
        ]);
        [$optedOutUser, $optedOutOrganization] = $this->member();
        UserNotificationPreference::query()->create([
            'user_id' => $optedOutUser->id,
            'organization_id' => $optedOutOrganization->id,
            'weekly_summary_enabled' => false,
        ]);
        [$suspendedUser, $suspendedOrganization] = $this->member();
        OrganizationMembership::query()
            ->where('organization_id', $suspendedOrganization->id)
            ->where('user_id', $suspendedUser->id)
            ->firstOrFail()
            ->update(['status' => MembershipStatus::Suspended]);
        UserNotificationPreference::query()->create([
            'user_id' => $suspendedUser->id,
            'organization_id' => $suspendedOrganization->id,
            'weekly_summary_enabled' => true,
        ]);

        $summaryBuilder = $this->mock(WeeklySummaryBuilder::class);
        $summaryBuilder->shouldNotReceive('build');
        Mail::fake();
        Queue::fake();

        $this->assertSame(0, Artisan::call('opsdeck:weekly-summaries', ['--dry-run' => true]));
        $this->assertStringContainsString(
            sprintf('Eligible organizations: %d; recipients: %d.', $eligibleOrganizationCount + 2, $eligibleRecipientCount + 2),
            Artisan::output(),
        );

        Queue::assertNotPushed(SendWeeklySummaryEmail::class);
        Mail::assertNothingOutgoing();
    }

    public function test_weekly_summary_uses_organization_local_calendar_dates_and_matching_import_filters(): void
    {
        config(['app.timezone' => 'UTC', 'opsdeck.weekly_summary_period_days' => 1]);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 00:30:00', 'UTC'));
        [$user, $organization] = $this->member();
        $organization->update(['timezone' => 'America/Los_Angeles', 'plan_code' => config('opsdeck.premium_plan_code')]);
        $includedBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'la-local-included',
            'status' => 'completed',
            'error_rows' => 2,
        ]);
        $includedBatch->forceFill(['created_at' => '2026-10-11 00:15:00'])->save();
        $excludedBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'la-local-excluded',
            'status' => 'completed',
            'error_rows' => 5,
        ]);
        $excludedBatch->forceFill(['created_at' => '2026-10-10 06:59:00'])->save();
        Contract::query()->create([
            'organization_id' => $organization->id,
            'vendor_name' => 'Local renewal vendor',
            'contract_name' => 'Local renewal today',
            'contract_type' => 'service',
            'billing_cycle' => 'monthly',
            'amount' => 1000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
            'renewal_date' => '2026-10-10',
        ]);

        $summary = app(WeeklySummaryBuilder::class)->build($organization->id);

        $this->assertSame('2026-10-10', $summary['period_start']);
        $this->assertSame('2026-10-10', $summary['period_end']);
        $this->assertSame(2, $summary['import_errors']);
        $this->assertSame(1, $summary['renewals']);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.index', ['has_errors' => 1, 'created_after' => $summary['period_start'], 'created_before' => $summary['period_end']]))
            ->assertOk()
            ->assertSee('la-local-included')
            ->assertDontSee('la-local-excluded');
    }

    public function test_weekly_summary_counts_renewals_through_the_organization_local_thirtieth_day_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 00:30:00', 'UTC'));
        [, $organization] = $this->member();
        $organization->update(['timezone' => 'America/Los_Angeles', 'plan_code' => config('opsdeck.premium_plan_code')]);
        $today = CarbonImmutable::today($organization->timezone);
        $boundary = $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS)->toDateString();
        $createContract = function (string $name, string $status, ?string $renewalDate, ?string $endsAt, ?string $cancellationDeadline = null) use ($organization): void {
            Contract::query()->create([
                'organization_id' => $organization->id,
                'vendor_name' => 'Summary boundary vendor',
                'contract_name' => $name,
                'contract_type' => 'service',
                'billing_cycle' => 'monthly',
                'amount' => 1000,
                'currency' => 'JPY',
                'auto_renew' => true,
                'status' => $status,
                'renewal_date' => $renewalDate,
                'ends_at' => $endsAt,
                'cancellation_deadline' => $cancellationDeadline,
            ]);
        };

        $createContract('Renewal today', 'active', $today->toDateString(), null);
        $createContract('Renewal and end date in window', 'active', $boundary, $today->addDays(10)->toDateString());
        $createContract('End date on boundary', 'active', null, $boundary);
        $createContract('Pending cancellation', 'pending_cancel', $today->addDays(5)->toDateString(), null);
        $createContract('Cancellation deadline only', 'active', null, null, $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS)->toDateString());
        $createContract('Renewal after window', 'active', $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS + 1)->toDateString(), null);
        $createContract('Overdue renewal', 'active', $today->subDay()->toDateString(), null);
        $createContract('Cancelled contract', 'cancelled', $today->addDays(5)->toDateString(), null);

        $summary = app(WeeklySummaryBuilder::class)->build($organization->id);

        $this->assertSame($today->toDateString(), $summary['period_end']);
        $this->assertSame(5, $summary['renewals']);
    }

    public function test_weekly_summary_job_rechecks_opt_out_before_sending(): void
    {
        Mail::fake();
        [$user, $organization] = $this->member();
        $user->notificationPreferences()->create(['organization_id' => $organization->id, 'weekly_summary_enabled' => false, 'announcement_enabled' => true]);

        (new SendWeeklySummaryEmail($user->id, $organization->id, ['renewals' => 1], now()->startOfWeek()->toDateString()))->handle();

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('weekly_summary_deliveries', ['user_id' => $user->id, 'organization_id' => $organization->id]);
    }

    public function test_weekly_summary_delivery_failure_does_not_rethrow_email_address_or_transport_details(): void
    {
        [$user, $organization] = $this->member();
        Mail::shouldReceive('to')
            ->once()
            ->with($user->email)
            ->andThrow(new RuntimeException('Transport rejected '.$user->email));

        $caughtException = null;
        try {
            (new SendWeeklySummaryEmail($user->id, $organization->id, ['renewals' => 1], now()->startOfWeek()->toDateString()))->handle();
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caughtException);
        $this->assertSame('Weekly summary email delivery failed: RuntimeException', $caughtException->getMessage());
        $this->assertNull($caughtException->getPrevious());
        $this->assertStringNotContainsString($user->email, $caughtException->getMessage());
        $delivery = WeeklySummaryDelivery::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->firstOrFail();
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->sent_at);
        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'weekly_summary_enabled' => false,
            'announcement_enabled' => true,
        ]);
    }

    public function test_weekly_summary_queue_rejects_duplicate_jobs_for_the_same_recipient_and_week(): void
    {
        [$user, $organization] = $this->member();
        $queueName = 'weekly-dedup-'.Str::lower(Str::random(8));
        $weekStart = now()->startOfWeek()->toDateString();
        config([
            'cache.default' => 'database',
            'cache.stores.database.connection' => null,
            'cache.stores.database.lock_connection' => null,
            'queue.default' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.connection' => null,
        ]);
        $job = new SendWeeklySummaryEmail($user->id, $organization->id, ['renewals' => 1], $weekStart);

        try {
            dispatch($job)->onQueue($queueName);
            SendWeeklySummaryEmail::dispatch($user->id, $organization->id, ['renewals' => 2], $weekStart)->onQueue($queueName);

            $this->assertSame(1, DB::table('jobs')->where('queue', $queueName)->count());
        } finally {
            (new UniqueLock(Cache::store('database')))->release($job);
            DB::table('jobs')->where('queue', $queueName)->delete();
        }
    }

    public function test_failed_weekly_summary_is_not_retried_after_the_member_opts_out(): void
    {
        Queue::fake();
        [$user, $organization] = $this->member();
        UserNotificationPreference::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'weekly_summary_enabled' => false, 'announcement_enabled' => true]);
        $delivery = WeeklySummaryDelivery::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'week_start' => now()->startOfWeek()->toDateString(), 'failed_at' => now()]);

        $this->artisan('opsdeck:retry-weekly-summary-emails')->expectsOutput('Queued weekly summary email retries: 0')->assertExitCode(0);

        $this->assertNotNull($delivery->fresh()->failed_at);
        Queue::assertNotPushed(SendWeeklySummaryEmail::class);
    }

    public function test_weekly_summary_retry_keeps_failure_visible_until_delivery_succeeds(): void
    {
        Queue::fake();
        [$user, $organization] = $this->member();
        $delivery = WeeklySummaryDelivery::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'week_start' => now()->startOfWeek()->toDateString(), 'failed_at' => now()]);

        $this->artisan('opsdeck:retry-weekly-summary-emails')->expectsOutput('Queued weekly summary email retries: 1')->assertExitCode(0);

        $this->assertNotNull($delivery->fresh()->failed_at);
        Queue::assertPushed(SendWeeklySummaryEmail::class, fn (SendWeeklySummaryEmail $job): bool => $job->userId === $user->id && $job->organizationId === $organization->id);
    }

    public function test_weekly_summary_mail_links_each_metric_to_source_screen(): void
    {
        [, $organization] = $this->member();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $summary = app(WeeklySummaryBuilder::class)->build($organization->id);
        $summary = array_merge($summary, ['import_errors' => 1, 'failed_import_batches' => 2, 'renewals' => 2, 'security_open' => 3, 'mfa_unknown' => 4]);
        $this->travel(2)->days();
        $html = (new WeeklySummaryMail($organization, $summary))->render();

        $this->assertStringContainsString('直近7日間に新しく起きたこと', $html);
        $this->assertStringContainsString('今も確認が必要な項目', $html);
        $this->assertStringContainsString('ログイン時の追加確認が済んでいないクラウドサービスのアカウント', $html);
        $this->assertStringContainsString($summary['as_of'], $html);
        $importErrorsUrl = route('imports.index', ['has_errors' => 1, 'created_after' => $summary['period_start'], 'created_before' => $summary['period_end']]);
        $this->assertStringContainsString(str_replace('&', '&amp;', $importErrorsUrl), $html);
        $failedImportsUrl = route('imports.index', ['status' => 'failed', 'created_after' => $summary['period_start'], 'created_before' => $summary['period_end']]);
        $this->assertStringContainsString(str_replace('&', '&amp;', $failedImportsUrl), $html);
        $this->assertStringContainsString(route('reminders.renewals'), $html);
        $this->assertStringContainsString(route('security-controls.index', ['attention' => 1]), $html);
        $this->assertStringContainsString(route('saas-services.index'), $html);
    }

    public function test_free_plan_summary_excludes_premium_alert_counts_and_links(): void
    {
        [, $organization] = $this->member();

        $summary = app(WeeklySummaryBuilder::class)->build($organization->id);
        $html = (new WeeklySummaryMail($organization, $summary))->render();

        $this->assertFalse($summary['renewal_alerts_enabled']);
        $this->assertFalse($summary['security_alerts_enabled']);
        $this->assertSame(0, $summary['renewals']);
        $this->assertSame(0, $summary['security_open']);
        $this->assertSame(0, $summary['mfa_unknown']);
        $this->assertStringContainsString('無料プランでは、契約の更新期限やセキュリティ項目は表示していません。', $html);
        $this->assertStringNotContainsString(route('reminders.renewals'), $html);
        $this->assertStringNotContainsString(route('security-controls.index', ['attention' => 1]), $html);
    }

    public function test_weekly_summary_mail_uses_the_admin_sender_address(): void
    {
        [, $organization] = $this->member();
        $mail = new WeeklySummaryMail($organization, ['import_errors' => 0, 'renewals' => 0, 'security_open' => 0, 'mfa_unknown' => 0]);

        $this->assertSame(config('opsdeck.admin_mail_from_address'), $mail->envelope()->from->address);
        $this->assertNotSame(config('opsdeck.support_email'), $mail->envelope()->from->address);
    }

    public function test_weekly_summary_excludes_ended_assignments_and_inactive_services_from_mfa_count(): void
    {
        [, $organization] = $this->member();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $service = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Summary SaaS', 'status' => 'active']);
        $inactiveService = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Inactive Summary SaaS', 'status' => 'inactive']);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $service->id, 'status' => 'active', 'mfa_status' => 'unknown']);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $service->id, 'status' => 'ended', 'mfa_status' => 'unknown', 'ended_at' => now()->subDay()]);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $inactiveService->id, 'status' => 'active', 'mfa_status' => 'unknown']);

        $summary = app(WeeklySummaryBuilder::class)->build($organization->id);

        $this->assertSame(1, $summary['mfa_unknown']);
    }

    public function test_weekly_summary_import_errors_use_the_configured_period(): void
    {
        config(['opsdeck.weekly_summary_period_days' => 4]);
        $this->travelTo('2026-10-10 12:00:00');
        [, $organization] = $this->member();
        $recentBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'recent-errors', 'status' => 'completed', 'error_rows' => 2]);
        $recentBatch->forceFill(['created_at' => now()->subDays(2)])->save();
        $olderBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'older-errors', 'status' => 'completed', 'error_rows' => 5]);
        $olderBatch->forceFill(['created_at' => now()->subDays(5)])->save();
        $recentFailure = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'recent-failure', 'status' => 'failed']);
        $recentFailure->forceFill(['created_at' => now()->subDays(2)])->save();
        $olderFailure = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'older-failure', 'status' => 'failed']);
        $olderFailure->forceFill(['created_at' => now()->subDays(5)])->save();

        $summary = app(WeeklySummaryBuilder::class)->build($organization->id);

        $this->assertSame(2, $summary['import_errors']);
        $this->assertSame(1, $summary['failed_import_batches']);
        $this->assertSame('2026-10-07', $summary['period_start']);
        $this->assertSame('2026-10-10', $summary['period_end']);
    }

    public function test_import_history_created_before_filter_limits_results_and_validates_range(): void
    {
        [$user, $organization] = $this->member();
        $includedBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'period-included', 'status' => 'completed', 'error_rows' => 1]);
        $includedBatch->forceFill(['created_at' => '2026-10-08 12:00:00'])->save();
        $excludedBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'period-excluded', 'status' => 'completed', 'error_rows' => 1]);
        $excludedBatch->forceFill(['created_at' => '2026-10-11 12:00:00'])->save();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.index', ['has_errors' => 1, 'created_after' => '2026-10-07', 'created_before' => '2026-10-10']))
            ->assertOk()
            ->assertSee('period-included')
            ->assertDontSee('period-excluded');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.index', ['created_after' => '2026-10-10', 'created_before' => '2026-10-07']))
            ->assertSessionHasErrors('created_before');
    }

    /** @return array{0: User, 1: Organization} */
    private function member(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'summary-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Summary '.$suffix, 'slug' => 'summary-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
