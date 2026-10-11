<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductEvent;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnnouncementReadControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_unpublished_announcement_cannot_be_marked_as_read(): void
    {
        ['user' => $user, 'organization' => $organization] = $this->createOrganizationMember();
        $announcement = Announcement::query()->create(['title' => '未公開', 'body' => '公開前のお知らせ', 'published_at' => null]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('announcements.read', $announcement))
            ->assertNotFound();

        $this->assertDatabaseMissing('announcement_reads', ['announcement_id' => $announcement->id, 'user_id' => $user->id]);
    }

    public function test_future_announcement_cannot_be_marked_as_read(): void
    {
        $this->travelTo('2026-10-09 12:00:00');
        ['user' => $user, 'organization' => $organization] = $this->createOrganizationMember();
        $announcement = Announcement::query()->create(['title' => '予約公開', 'body' => '未来のお知らせ', 'published_at' => now()->addMinute()]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('announcements.read', $announcement))
            ->assertNotFound();

        $this->assertDatabaseMissing('announcement_reads', ['announcement_id' => $announcement->id, 'user_id' => $user->id]);
    }

    public function test_member_cannot_mark_another_organizations_announcement_as_read(): void
    {
        ['user' => $user, 'organization' => $organization] = $this->createOrganizationMember();
        $announcement = Announcement::query()->create(['title' => '別組織限定', 'body' => '他組織のお知らせ', 'published_at' => now()->subMinute()]);
        $targetOrganization = $this->createOrganization();
        $announcement->organizations()->attach($targetOrganization->id);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('announcements.read', $announcement))
            ->assertNotFound();

        $this->assertDatabaseMissing('announcement_reads', ['announcement_id' => $announcement->id, 'user_id' => $user->id]);
    }

    public function test_marking_a_dashboard_announcement_as_read_records_one_completed_action_event(): void
    {
        ['user' => $user, 'organization' => $organization] = $this->createOrganizationMember();
        $announcement = Announcement::query()->create(['title' => '公開済み', 'body' => '確認するお知らせ', 'published_at' => now()->subMinute()]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('announcements.read', $announcement))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('announcement_reads', ['announcement_id' => $announcement->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('announcements.read', $announcement))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, ProductEvent::query()
            ->where('organization_id', $organization->id)
            ->where('event_key', 'first_dashboard_action_completed')
            ->count());
    }

    /** @return array{user: User, organization: Organization} */
    private function createOrganizationMember(): array
    {
        $user = User::factory()->create(['email' => 'announcement-read-'.Str::uuid().'@example.test']);
        $organization = $this->createOrganization();
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);

        return ['user' => $user, 'organization' => $organization];
    }

    private function createOrganization(): Organization
    {
        $suffix = Str::lower(Str::random(8));

        return Organization::query()->create(['name' => 'Announcement '.$suffix, 'slug' => 'announcement-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
    }
}
