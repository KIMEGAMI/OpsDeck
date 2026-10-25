<?php

namespace Tests\Feature\Feature\Tenant;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_select_an_organization_without_active_membership(): void
    {
        $user = User::factory()->create(['email' => 'boundary-'.Str::uuid().'@example.test']);
        $authorizedOrganization = Organization::query()->create([
            'name' => 'Authorized Organization',
            'slug' => 'authorized-organization',
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $otherOrganization = Organization::query()->create([
            'name' => 'Other Organization',
            'slug' => 'other-organization',
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $authorizedOrganization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $otherOrganization->id])
            ->get('/dashboard')
            ->assertForbidden();

        $this->assertFalse($user->can('view', $otherOrganization));
    }
}
