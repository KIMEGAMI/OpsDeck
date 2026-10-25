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

class AssetPaginationTest extends TestCase
{
    private const ASSET_COUNT = 500;

    private const PAGE_SIZE = 100;

    public function test_500_assets_are_paginated_and_remain_scoped_to_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => self::PAGE_SIZE]);

        [$user, $organization] = $this->ownerOrganization();
        [, $otherOrganization] = $this->ownerOrganization();
        $assets = [];

        foreach (range(1, self::ASSET_COUNT) as $number) {
            $assets[] = Asset::query()->create([
                'organization_id' => $organization->id,
                'asset_number' => sprintf('ASSET-%04d', $number),
                'asset_type' => config('opsdeck.asset_types')[0],
                'status' => config('opsdeck.asset_statuses')[0],
            ]);
        }

        $otherOrganizationAsset = Asset::query()->create([
            'organization_id' => $otherOrganization->id,
            'asset_number' => 'OTHER-ASSET-0001',
            'asset_type' => config('opsdeck.asset_types')[0],
            'status' => config('opsdeck.asset_statuses')[0],
        ]);
        $session = ['current_organization_id' => $organization->id];

        $firstPage = $this->actingAs($user)->withSession($session)->get(route('assets.index', ['page' => 1]));
        $secondPage = $this->actingAs($user)->withSession($session)->get(route('assets.index', ['page' => 2]));

        $firstPage->assertOk()
            ->assertSee($assets[0]->asset_number)
            ->assertSee($assets[self::PAGE_SIZE - 1]->asset_number)
            ->assertDontSee($assets[self::PAGE_SIZE]->asset_number)
            ->assertDontSee($otherOrganizationAsset->asset_number);
        $secondPage->assertOk()
            ->assertSee($assets[self::PAGE_SIZE]->asset_number)
            ->assertSee($assets[(self::PAGE_SIZE * 2) - 1]->asset_number)
            ->assertDontSee($assets[self::PAGE_SIZE - 1]->asset_number)
            ->assertDontSee($otherOrganizationAsset->asset_number);
    }

    /** @return array{0: User, 1: Organization} */
    private function ownerOrganization(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'asset-pagination-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Asset Pagination '.$suffix,
            'slug' => 'asset-pagination-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }
}
