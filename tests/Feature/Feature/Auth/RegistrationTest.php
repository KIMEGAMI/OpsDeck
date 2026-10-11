<?php

namespace Tests\Feature\Feature\Auth;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_owner_membership_and_authenticated_session(): void
    {
        $response = $this->post('/register', [
            'organization_name' => 'Example Operations',
            'name' => 'Example Owner',
            'email' => 'owner@example.test',
            'password' => 'A-secure-password-2026',
            'password_confirmation' => 'A-secure-password-2026',
        ]);

        $response->assertRedirectToRoute('onboarding.index');
        $this->assertAuthenticated();

        $organization = Organization::query()->where('name', 'Example Operations')->firstOrFail();
        $this->assertSame('free', $organization->plan_code);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'role' => MembershipRole::Owner->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $this->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('導入チェックリスト')
            ->assertSee('社員表');
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'onboarding_started',
        ]);
    }
}
