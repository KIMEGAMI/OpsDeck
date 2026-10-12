<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Models\WeeklySummaryDelivery;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMailDeliveryReviewControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_platform_admin_can_view_unresolved_mail_deliveries(): void
    {
        $admin = $this->platformAdmin();
        $recipient = User::factory()->create(['email' => 'delivery-review-'.Str::uuid().'@example.test']);
        $announcement = Announcement::query()->create(['title' => '配信確認のお知らせ', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()]);
        $resolvedAnnouncement = Announcement::query()->create(['title' => '解決済み配信', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()]);
        AnnouncementDelivery::query()->create([
            'announcement_id' => $announcement->id,
            'user_id' => $recipient->id,
            'failed_at' => now()->subHours(25),
            'resend_idempotency_started_at' => now()->subHours(25),
            'manual_review_at' => now(),
        ]);
        $organization = Organization::query()->create([
            'name' => '配信確認組織',
            'slug' => 'delivery-review-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        WeeklySummaryDelivery::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $recipient->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'failed_at' => now()->subHours(25),
            'resend_idempotency_started_at' => now()->subHours(25),
            'manual_review_at' => now(),
        ]);
        AnnouncementDelivery::query()->create([
            'announcement_id' => $resolvedAnnouncement->id,
            'user_id' => User::factory()->create()->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.mail-delivery-reviews.index'))
            ->assertOk()
            ->assertSee('配信確認のお知らせ')
            ->assertSee($recipient->email)
            ->assertSee('配信確認組織')
            ->assertSee('メール事業者の配信履歴で成功を確認した場合だけ')
            ->assertSee('事業者の履歴で配信成功を確認しました')
            ->assertDontSee('解決済み配信');
    }

    public function test_platform_admin_can_resolve_an_announcement_after_confirming_delivery(): void
    {
        Queue::fake();
        $admin = $this->platformAdmin();
        $recipient = User::factory()->create(['email' => 'delivery-confirmed-'.Str::uuid().'@example.test']);
        $announcement = Announcement::query()->create(['title' => '配信確認', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()]);
        $delivery = AnnouncementDelivery::query()->create([
            'announcement_id' => $announcement->id,
            'user_id' => $recipient->id,
            'failed_at' => now()->subHours(25),
            'resend_idempotency_started_at' => now()->subHours(25),
            'manual_review_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.mail-delivery-reviews.announcements.resolve', $delivery), ['confirm_delivery' => '1'])
            ->assertRedirect(route('admin.mail-delivery-reviews.index'));

        $this->assertNotNull($delivery->fresh()->sent_at);
        $this->assertNull($delivery->fresh()->failed_at);
        $this->assertNotNull($delivery->fresh()->manual_review_at);
        $this->assertSame($admin->id, $delivery->fresh()->manual_review_resolved_by);
        Queue::assertNothingPushed();
    }

    public function test_repeated_announcement_resolution_does_not_overwrite_the_original_delivery_record(): void
    {
        Queue::fake();
        $admin = $this->platformAdmin();
        $recipient = User::factory()->create(['email' => 'delivery-repeat-'.Str::uuid().'@example.test']);
        $announcement = Announcement::query()->create(['title' => '重複確認', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()]);
        $delivery = AnnouncementDelivery::query()->create([
            'announcement_id' => $announcement->id,
            'user_id' => $recipient->id,
            'failed_at' => now()->subHours(25),
            'resend_idempotency_started_at' => now()->subHours(25),
            'manual_review_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.mail-delivery-reviews.announcements.resolve', $delivery), ['confirm_delivery' => '1'])
            ->assertRedirect(route('admin.mail-delivery-reviews.index'));
        $firstResolvedDelivery = $delivery->fresh();
        $firstSentAt = $firstResolvedDelivery->sent_at->toDateTimeString();
        $firstResolver = $firstResolvedDelivery->manual_review_resolved_by;
        $this->travel(1)->minutes();

        $this->post(route('admin.mail-delivery-reviews.announcements.resolve', $delivery), ['confirm_delivery' => '1'])
            ->assertRedirect(route('admin.mail-delivery-reviews.index'))
            ->assertSessionHas('status', 'このお知らせメールは確認待ちではありません。');

        $resolvedDelivery = $delivery->fresh();
        $this->assertSame($firstSentAt, $resolvedDelivery->sent_at->toDateTimeString());
        $this->assertSame($firstResolver, $resolvedDelivery->manual_review_resolved_by);
        Queue::assertNothingPushed();
    }

    public function test_platform_admin_can_resolve_a_weekly_summary_after_confirming_delivery(): void
    {
        Queue::fake();
        $admin = $this->platformAdmin();
        $user = User::factory()->create(['email' => 'weekly-delivery-confirmed-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => '週次確認組織',
            'slug' => 'weekly-review-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $delivery = WeeklySummaryDelivery::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'failed_at' => now()->subHours(25),
            'resend_idempotency_started_at' => now()->subHours(25),
            'manual_review_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.mail-delivery-reviews.weekly-summaries.resolve', $delivery), ['confirm_delivery' => '1'])
            ->assertRedirect(route('admin.mail-delivery-reviews.index'));

        $this->assertNotNull($delivery->fresh()->sent_at);
        $this->assertNull($delivery->fresh()->failed_at);
        $this->assertNotNull($delivery->fresh()->manual_review_at);
        $this->assertSame($admin->id, $delivery->fresh()->manual_review_resolved_by);
        Queue::assertNothingPushed();
    }

    public function test_manual_delivery_resolution_requires_explicit_confirmation(): void
    {
        Queue::fake();
        $admin = $this->platformAdmin();
        $recipient = User::factory()->create(['email' => 'delivery-unconfirmed-'.Str::uuid().'@example.test']);
        $announcement = Announcement::query()->create(['title' => '確認必須', 'body' => '本文', 'created_by' => $admin->id, 'published_at' => now()]);
        $delivery = AnnouncementDelivery::query()->create([
            'announcement_id' => $announcement->id,
            'user_id' => $recipient->id,
            'failed_at' => now()->subHours(25),
            'resend_idempotency_started_at' => now()->subHours(25),
            'manual_review_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.mail-delivery-reviews.announcements.resolve', $delivery))
            ->assertSessionHasErrors(['confirm_delivery']);

        $this->assertNull($delivery->fresh()->sent_at);
        $this->assertNotNull($delivery->fresh()->manual_review_at);
        Queue::assertNothingPushed();
    }

    public function test_non_platform_admin_cannot_view_or_resolve_manual_mail_reviews(): void
    {
        Queue::fake();
        $recipient = User::factory()->create(['email' => 'delivery-review-denied-'.Str::uuid().'@example.test']);
        $announcement = Announcement::query()->create(['title' => '管理者限定', 'body' => '本文', 'published_at' => now()]);
        $delivery = AnnouncementDelivery::query()->create([
            'announcement_id' => $announcement->id,
            'user_id' => $recipient->id,
            'failed_at' => now(),
            'manual_review_at' => now(),
        ]);
        $user = User::factory()->create(['email' => 'regular-'.Str::uuid().'@example.test']);

        $this->actingAs($user)->get(route('admin.mail-delivery-reviews.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.mail-delivery-reviews.announcements.resolve', $delivery), ['confirm_delivery' => '1'])->assertForbidden();

        $this->assertNull($delivery->fresh()->sent_at);
        Queue::assertNothingPushed();
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['email' => 'platform-delivery-review-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        return $admin;
    }
}
