<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationMemberControllerTest extends TestCase
{
    public function test_member_can_view_only_current_organization_members(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $otherUser = User::factory()->create(['name' => 'Other Organization User']);
        $otherOrganization = Organization::query()->create(['name' => 'Other '.$organization->slug, 'slug' => 'other-'.$organization->slug, 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $otherOrganization->id, 'user_id' => $otherUser->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('settings.members'))->assertOk()->assertSee($user->email)->assertDontSee($otherUser->email);
    }

    public function test_owner_can_update_another_members_role_and_status(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $target = User::factory()->create(['email' => 'target-'.Str::uuid().'@example.test']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $target->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])->put(route('settings.members.update', $membership), ['role' => 'admin', 'status' => 'suspended'])->assertRedirect(route('settings.members'));
        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id, 'role' => 'admin', 'status' => 'suspended']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'entity_id' => $membership->id, 'action' => 'organization.member_updated']);
    }

    public function test_free_plan_prevents_promoting_a_member_to_administrator_over_the_limit(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $target = User::factory()->create(['email' => 'promote-'.Str::uuid().'@example.test']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $target->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->from(route('settings.members'))
            ->put(route('settings.members.update', $membership), ['role' => 'admin', 'status' => 'active'])
            ->assertRedirect(route('settings.members'))
            ->assertSessionHasErrors('plan_limit');

        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id, 'role' => 'viewer', 'status' => 'active']);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'entity_id' => $membership->id, 'action' => 'organization.member_updated']);
    }

    public function test_viewer_cannot_update_member(): void
    {
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $target = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $target->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])->put(route('settings.members.update', $membership), ['role' => 'admin', 'status' => 'active'])->assertForbidden();
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'members-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Members '.$suffix, 'slug' => 'members-'.$suffix, 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
