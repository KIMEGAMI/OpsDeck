<?php

namespace Tests\Feature;

use App\Jobs\SendAnnouncementEmail;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\AnnouncementRecipientQuery;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminAnnouncementControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_platform_admin_can_publish_announcement_and_queue_one_email_per_user(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['email' => 'platform-'.Str::uuid().'@example.test']);
        $recipient = User::factory()->create(['email' => 'recipient-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Broadcast '.Str::random(6), 'slug' => 'broadcast-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), ['title' => 'メンテナンスのお知らせ', 'body' => '9月30日にメンテナンスを実施します。']);

        $response->assertRedirect(route('admin.announcements.index'));
        $eligibleRecipients = app(AnnouncementRecipientQuery::class)->eligibleFor()->count();
        $this->assertDatabaseHas('announcements', ['title' => 'メンテナンスのお知らせ', 'recipients_count' => $eligibleRecipients]);
        $this->assertDatabaseHas('product_events', ['event_key' => 'announcement_published', 'organization_id' => null]);
        Queue::assertPushed(SendAnnouncementEmail::class, $eligibleRecipients);
        Queue::assertPushed(SendAnnouncementEmail::class, fn (SendAnnouncementEmail $job): bool => $job->recipientUserId === $recipient->id);
    }

    public function test_platform_admin_can_view_eligible_recipient_count_before_publishing(): void
    {
        $admin = User::factory()->create(['email' => 'platform-create-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        $eligible = app(AnnouncementRecipientQuery::class)->eligibleFor()->count();
        $this->actingAs($admin)->get(route('admin.announcements.create'))->assertOk()->assertSee('全組織配信時の現在の対象ユーザー数：'.$eligible.'人')->assertSee('送信元：'.config('opsdeck.admin_mail_from_address'))->assertSee('問い合わせ先：'.config('opsdeck.support_email'))->assertSee('お知らせを公開してメール送信しますか？')->assertSee('disabled = true')->assertSee('announcement-submit')->assertSee('aria-disabled')->assertSee('organizationIds.forEach')->assertSee('AbortController')->assertSee('aria-busy')->assertSee('配信対象人数を取得中です')->assertSee('配信対象人数を取得できませんでした');
    }

    public function test_platform_admin_can_preview_targeted_recipient_count(): void
    {
        $admin = User::factory()->create(['email' => 'platform-count-'.Str::uuid().'@example.test']);
        $recipient = User::factory()->create(['email' => 'count-recipient-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Count '.Str::random(6), 'slug' => 'count-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $otherOrganization = Organization::query()->create(['name' => 'Count Other '.Str::random(6), 'slug' => 'count-other-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        OrganizationMembership::query()->create(['organization_id' => $otherOrganization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        UserNotificationPreference::query()->create(['user_id' => $recipient->id, 'organization_id' => $otherOrganization->id, 'weekly_summary_enabled' => true, 'announcement_enabled' => false]);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        $this->actingAs($admin)->getJson(route('admin.announcements.recipient-count', ['organization_ids' => [$organization->id]]))->assertOk()->assertJson(['count' => 1]);
        $this->actingAs($admin)->getJson(route('admin.announcements.recipient-count', ['organization_ids' => [$organization->id, $otherOrganization->id]]))->assertOk()->assertJson(['count' => 1]);
    }

    public function test_non_platform_admin_cannot_preview_recipient_count(): void
    {
        $user = User::factory()->create(['email' => 'count-denied-'.Str::uuid().'@example.test']);

        $this->actingAs($user)->getJson(route('admin.announcements.recipient-count'))->assertForbidden();
    }

    public function test_non_platform_admin_cannot_publish_announcement(): void
    {
        $user = User::factory()->create(['email' => 'member-'.Str::uuid().'@example.test']);

        $this->actingAs($user)->post(route('admin.announcements.store'), ['title' => 'Denied', 'body' => 'Denied'])->assertForbidden();
    }

    public function test_platform_admin_can_view_delivery_status_counts(): void
    {
        $admin = User::factory()->create(['email' => 'platform-status-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $announcement = Announcement::query()->create(['title' => '配信状況', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()->subYears(10), 'recipients_count' => 2]);
        AnnouncementDelivery::query()->create(['announcement_id' => $announcement->id, 'user_id' => $admin->id, 'sent_at' => now()]);
        AnnouncementDelivery::query()->create(['announcement_id' => $announcement->id, 'user_id' => User::factory()->create()->id, 'failed_at' => now()]);
        AnnouncementDelivery::query()->create(['announcement_id' => $announcement->id, 'user_id' => User::factory()->create()->id, 'failed_at' => now(), 'suppressed_at' => now()]);

        $this->actingAs($admin)->get(route('admin.announcements.index'))->assertOk()->assertSee('送信済み')->assertSee('失敗・停止済み')->assertSee('停止 1')->assertSee('配信状況')->assertSee('失敗したメールを再送しますか？')->assertSee('disabled = true');
    }

    public function test_announcement_pages_use_a_stable_order_when_creation_times_match(): void
    {
        config(['opsdeck.pagination.per_page' => 1]);
        $admin = User::factory()->create(['email' => 'platform-pagination-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $this->travelTo('2999-10-01 12:00:00');
        Announcement::query()->create(['title' => '同時刻のお知らせ1', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now(), 'recipients_count' => 0]);
        Announcement::query()->create(['title' => '同時刻のお知らせ2', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now(), 'recipients_count' => 0]);

        $this->actingAs($admin)->get(route('admin.announcements.index', ['page' => 1]))
            ->assertOk()
            ->assertSee('同時刻のお知らせ2')
            ->assertDontSee('同時刻のお知らせ1');

        $this->actingAs($admin)->get(route('admin.announcements.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('同時刻のお知らせ1')
            ->assertDontSee('同時刻のお知らせ2');
    }

    public function test_platform_admin_can_retry_failed_announcement_delivery(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['email' => 'platform-retry-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $recipient = User::factory()->create(['email' => 'retry-recipient-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Retry '.Str::random(6), 'slug' => 'retry-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        $announcement = Announcement::query()->create(['title' => '再送対象', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()->subYears(10), 'recipients_count' => 1]);
        $delivery = AnnouncementDelivery::query()->create(['announcement_id' => $announcement->id, 'user_id' => $recipient->id, 'failed_at' => now()]);

        $this->actingAs($admin)->post(route('admin.announcements.retry', $announcement))->assertRedirect(route('admin.announcements.index'));
        $this->assertNotNull($delivery->fresh()->failed_at);
        Queue::assertPushed(SendAnnouncementEmail::class, fn (SendAnnouncementEmail $job): bool => $job->recipientUserId === $recipient->id);
    }

    public function test_failed_announcement_is_not_retried_for_a_recipient_who_opted_out(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['email' => 'platform-retry-optout-'.Str::uuid().'@example.test']);
        $recipient = User::factory()->create(['email' => 'retry-optout-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Retry Optout '.Str::random(6), 'slug' => 'retry-optout-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        UserNotificationPreference::query()->create(['user_id' => $recipient->id, 'organization_id' => $organization->id, 'weekly_summary_enabled' => true, 'announcement_enabled' => false]);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $announcement = Announcement::query()->create(['title' => '停止後の再送', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now(), 'recipients_count' => 1]);
        $announcement->organizations()->attach($organization->id);
        $delivery = AnnouncementDelivery::query()->create(['announcement_id' => $announcement->id, 'user_id' => $recipient->id, 'failed_at' => now()]);

        $this->actingAs($admin)->post(route('admin.announcements.retry', $announcement))->assertRedirect(route('admin.announcements.index'));

        $this->assertNotNull($delivery->fresh()->failed_at);
        Queue::assertNotPushed(SendAnnouncementEmail::class, fn (SendAnnouncementEmail $job): bool => $job->recipientUserId === $recipient->id);
    }

    public function test_failed_delivery_is_suppressed_after_recipient_opts_out_and_is_not_retried(): void
    {
        Queue::fake();
        $recipient = User::factory()->create(['email' => 'suppressed-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Suppressed '.Str::random(6), 'slug' => 'suppressed-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        $announcement = Announcement::query()->create(['title' => '停止後の失敗再送', 'body' => '本文', 'published_at' => now()]);
        $announcement->organizations()->attach($organization->id);
        $delivery = AnnouncementDelivery::query()->create(['announcement_id' => $announcement->id, 'user_id' => $recipient->id, 'failed_at' => now()]);
        UserNotificationPreference::query()->create(['user_id' => $recipient->id, 'organization_id' => $organization->id, 'weekly_summary_enabled' => true, 'announcement_enabled' => false]);
        Mail::fake();

        (new SendAnnouncementEmail($announcement, $recipient->id))->handle();

        Mail::assertNothingSent();
        $this->assertNotNull($delivery->fresh()->failed_at);
        $this->assertNotNull($delivery->fresh()->suppressed_at);
        $this->artisan('opsdeck:retry-announcement-emails')->assertExitCode(0);
        Queue::assertNotPushed(SendAnnouncementEmail::class, fn (SendAnnouncementEmail $job): bool => $job->recipientUserId === $recipient->id);
    }

    public function test_announcement_mail_escapes_html_in_title_and_body(): void
    {
        $announcement = new Announcement(['title' => '<script>alert(1)</script>', 'body' => '<img src=x onerror=alert(1)>']);
        $html = (new AnnouncementMail($announcement))->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    public function test_announcement_mail_uses_the_admin_sender_address(): void
    {
        $announcement = new Announcement(['title' => '送信元確認', 'body' => '本文']);

        $announcementMail = (new AnnouncementMail($announcement))->envelope()->from->address;

        $this->assertSame(config('opsdeck.admin_mail_from_address'), $announcementMail);
        $this->assertNotSame(config('opsdeck.support_email'), $announcementMail);
    }

    public function test_announcement_delivery_failure_does_not_rethrow_transport_details_or_email_address(): void
    {
        $recipient = User::factory()->create(['email' => 'private-recipient-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Failure '.Str::random(6), 'slug' => 'failure-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        $announcement = Announcement::query()->create(['title' => '送信失敗', 'body' => '本文', 'published_at' => now()]);
        $announcement->organizations()->attach($organization->id);
        Mail::shouldReceive('to')
            ->once()
            ->with($recipient->email)
            ->andThrow(new RuntimeException('Transport rejected '.$recipient->email));

        $caughtException = null;
        try {
            (new SendAnnouncementEmail($announcement, $recipient->id))->handle();
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caughtException);
        $this->assertSame('Announcement delivery failed: RuntimeException', $caughtException->getMessage());
        $this->assertStringNotContainsString($recipient->email, $caughtException->getMessage());
        $this->assertNull($caughtException->getPrevious());
        $delivery = AnnouncementDelivery::query()->where('announcement_id', $announcement->id)->where('user_id', $recipient->id)->firstOrFail();
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->sent_at);
    }

    public function test_organization_dashboard_displays_published_announcements(): void
    {
        $user = User::factory()->create(['email' => 'dashboard-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Announcement Org '.Str::random(8), 'slug' => 'announcement-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        Announcement::query()->create(['title' => '重要なお知らせ', 'body' => '全利用者に表示されます。', 'published_at' => now()->subMinute()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('dashboard'))->assertOk()->assertSee('重要なお知らせ')->assertSee('全利用者に表示されます。');
    }

    public function test_admin_can_target_announcement_and_user_can_mark_it_read(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['email' => 'platform-target-'.Str::uuid().'@example.test']);
        $targetUser = User::factory()->create(['email' => 'target-'.Str::uuid().'@example.test']);
        $otherUser = User::factory()->create(['email' => 'other-'.Str::uuid().'@example.test']);
        $targetOrganization = Organization::query()->create(['name' => 'Target '.Str::random(6), 'slug' => 'target-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $otherOrganization = Organization::query()->create(['name' => 'Other '.Str::random(6), 'slug' => 'other-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $targetOrganization->id, 'user_id' => $targetUser->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        OrganizationMembership::query()->create(['organization_id' => $otherOrganization->id, 'user_id' => $otherUser->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        $title = '対象限定 '.Str::uuid();
        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), ['title' => $title, 'body' => '対象組織のみ', 'organization_ids' => [$targetOrganization->id]]);
        $response->assertRedirect(route('admin.announcements.index'));
        $announcement = Announcement::query()->where('title', $title)->firstOrFail();
        $this->assertTrue($announcement->organizations()->where('organizations.id', $targetOrganization->id)->exists());
        Queue::assertPushed(SendAnnouncementEmail::class, 1);

        $this->actingAs($targetUser)->withSession(['current_organization_id' => $targetOrganization->id])->get(route('dashboard'))->assertOk()->assertSee('対象限定')->assertSee('既読にする');
        $this->actingAs($targetUser)->withSession(['current_organization_id' => $targetOrganization->id])->post(route('announcements.read', $announcement))->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('announcement_reads', ['announcement_id' => $announcement->id, 'user_id' => $targetUser->id]);
        $this->actingAs($otherUser)->withSession(['current_organization_id' => $otherOrganization->id])->get(route('dashboard'))->assertOk()->assertDontSee('対象限定');
        $this->assertDatabaseMissing('announcement_reads', ['announcement_id' => $announcement->id, 'user_id' => $otherUser->id]);
    }

    public function test_announcement_email_is_not_queued_for_opted_out_recipient(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['email' => 'platform-optout-'.Str::uuid().'@example.test']);
        $recipient = User::factory()->create(['email' => 'optout-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Optout '.Str::random(6), 'slug' => 'optout-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        UserNotificationPreference::query()->create(['user_id' => $recipient->id, 'organization_id' => $organization->id, 'weekly_summary_enabled' => true, 'announcement_enabled' => false]);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        $this->actingAs($admin)->post(route('admin.announcements.store'), ['title' => '停止対象', 'body' => 'メール停止対象', 'organization_ids' => [$organization->id]])->assertRedirect(route('admin.announcements.index'));

        Queue::assertNotPushed(SendAnnouncementEmail::class, fn (SendAnnouncementEmail $job): bool => $job->recipientUserId === $recipient->id);
    }

    public function test_announcement_job_skips_member_who_becomes_inactive(): void
    {
        Mail::fake();
        $recipient = User::factory()->create(['email' => 'inactive-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Inactive '.Str::random(6), 'slug' => 'inactive-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'suspended', 'joined_at' => now()]);
        $announcement = Announcement::query()->create(['title' => '在籍確認', 'body' => '本文', 'published_at' => now()]);
        $announcement->organizations()->attach($organization->id);

        (new SendAnnouncementEmail($announcement, $recipient->id))->handle();

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('announcement_deliveries', ['announcement_id' => $announcement->id, 'user_id' => $recipient->id]);
    }

    public function test_announcement_job_ignores_opt_out_preferences_for_unrelated_organizations(): void
    {
        Mail::fake();
        $recipient = User::factory()->create(['email' => 'scoped-optout-'.Str::uuid().'@example.test']);
        $targetOrganization = Organization::query()->create(['name' => 'Target '.Str::random(6), 'slug' => 'target-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $otherOrganization = Organization::query()->create(['name' => 'Other '.Str::random(6), 'slug' => 'other-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        foreach ([$targetOrganization, $otherOrganization] as $organization) {
            OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        }
        UserNotificationPreference::query()->create(['user_id' => $recipient->id, 'organization_id' => $otherOrganization->id, 'weekly_summary_enabled' => true, 'announcement_enabled' => false]);
        $announcement = Announcement::query()->create(['title' => '対象組織のお知らせ', 'body' => '本文', 'published_at' => now()]);
        $announcement->organizations()->attach($targetOrganization->id);

        (new SendAnnouncementEmail($announcement, $recipient->id))->handle();

        Mail::assertSent(AnnouncementMail::class);
        $this->assertDatabaseHas('announcement_deliveries', ['announcement_id' => $announcement->id, 'user_id' => $recipient->id]);
    }

    public function test_announcement_queue_rejects_duplicate_jobs_for_the_same_recipient(): void
    {
        $recipient = User::factory()->create(['email' => 'announcement-dedup-'.Str::uuid().'@example.test']);
        $announcement = Announcement::query()->create(['title' => '重複防止', 'body' => '本文', 'published_at' => now()]);
        $queueName = 'announcement-dedup-'.Str::lower(Str::random(8));
        config([
            'cache.default' => 'database',
            'cache.stores.database.connection' => null,
            'cache.stores.database.lock_connection' => null,
            'queue.default' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.connection' => null,
        ]);
        $job = new SendAnnouncementEmail($announcement, $recipient->id);

        try {
            dispatch($job)->onQueue($queueName);
            SendAnnouncementEmail::dispatch($announcement, $recipient->id)->onQueue($queueName);

            $this->assertSame(1, DB::table('jobs')->where('queue', $queueName)->count());
        } finally {
            (new UniqueLock(Cache::store('database')))->release($job);
            DB::table('jobs')->where('queue', $queueName)->delete();
        }
    }
}
