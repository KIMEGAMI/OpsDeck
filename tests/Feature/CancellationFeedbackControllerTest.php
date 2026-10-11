<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class CancellationFeedbackControllerTest extends TestCase
{
    public function test_owner_can_submit_cancellation_feedback(): void
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'cancel-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Cancel '.$suffix, 'slug' => 'cancel-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('billing.cancellation-feedback.store'), ['reason' => 'price', 'details' => '予算見直し']);

        $response->assertRedirect(route('billing.index'));
        $this->assertDatabaseHas('cancellation_feedback', ['organization_id' => $organization->id, 'reason' => 'price']);
    }
}
