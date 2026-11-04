<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CancellationFeedbackControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_owner_can_submit_cancellation_feedback(): void
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cancel-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cancel '.$suffix, 'slug' => 'cancel-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('billing.cancellation-feedback.store'), ['reason' => 'price', 'details' => '予算見直し']);

        $response->assertRedirect(route('billing.index'));
        $this->assertDatabaseHas('cancellation_feedback', ['organization_id' => $organization->id, 'reason' => 'price']);
    }

    public function test_free_organization_cannot_submit_cancellation_feedback(): void
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cancel-free-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cancel Free '.$suffix, 'slug' => 'cancel-free-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.cancellation-feedback.store'), ['reason' => 'price'])
            ->assertForbidden();

        $this->assertDatabaseMissing('cancellation_feedback', ['organization_id' => $organization->id]);
    }

    public function test_feedback_reason_must_match_a_configured_reason(): void
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cancel-invalid-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cancel Invalid '.$suffix, 'slug' => 'cancel-invalid-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.cancellation-feedback.store'), ['reason' => 'unrecognized_reason'])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseMissing('cancellation_feedback', ['organization_id' => $organization->id]);
    }

    public function test_cancellation_feedback_is_shown_only_for_current_or_former_subscriptions(): void
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cancel-view-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cancel View '.$suffix, 'slug' => 'cancel-view-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertDontSee('解約理由（任意）');

        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('解約理由（任意）')
            ->assertSee('value="price"', false)
            ->assertSee('>価格</option>', false)
            ->assertSee('>必要な機能がない</option>', false);

        $organization->update(['plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => 'cancelled']);
        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('解約理由（任意）');
    }
}
