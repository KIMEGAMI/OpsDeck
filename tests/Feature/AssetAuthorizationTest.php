<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetAuthorizationTest extends TestCase
{
    public function test_viewer_cannot_create_an_asset(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $assetNumber = 'ASSET-'.Str::upper(Str::random(8));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('assets.store'), $this->validAssetInput($assetNumber))
            ->assertForbidden();

        $this->assertDatabaseMissing('assets', ['organization_id' => $organization->id, 'asset_number' => $assetNumber]);
    }

    public function test_operator_can_create_an_asset_in_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $assetNumber = 'ASSET-'.Str::upper(Str::random(8));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('assets.store'), $this->validAssetInput($assetNumber))
            ->assertRedirect();

        $this->assertDatabaseHas('assets', ['organization_id' => $organization->id, 'asset_number' => $assetNumber]);
    }

    public function test_viewer_cannot_update_an_asset(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $asset = $this->asset($organization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('assets.update', $asset), $this->validAssetInput('CHANGED-'.$asset->asset_number))
            ->assertForbidden();

        $this->assertSame($asset->asset_number, $asset->refresh()->asset_number);
    }

    public function test_operator_cannot_update_an_asset_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $asset = $this->asset($otherOrganization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('assets.update', $asset), $this->validAssetInput('CHANGED-'.$asset->asset_number))
            ->assertNotFound();

        $this->assertSame($asset->asset_number, $asset->refresh()->asset_number);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'asset-authorization-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Asset Authorization '.$suffix,
            'slug' => 'asset-authorization-'.$suffix,
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }

    private function asset(Organization $organization): Asset
    {
        return Asset::query()->create([
            'organization_id' => $organization->id,
            'asset_number' => 'ASSET-'.Str::upper(Str::random(8)),
            'asset_type' => config('opsdeck.asset_types')[0],
            'status' => config('opsdeck.asset_statuses')[0],
        ]);
    }

    /** @return array{asset_number: string, asset_type: string, status: string} */
    private function validAssetInput(string $assetNumber): array
    {
        return [
            'asset_number' => $assetNumber,
            'asset_type' => config('opsdeck.asset_types')[0],
            'status' => config('opsdeck.asset_statuses')[0],
        ];
    }
}
