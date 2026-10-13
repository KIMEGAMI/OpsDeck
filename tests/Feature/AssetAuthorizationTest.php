<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
use App\Models\Employee;
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
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
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
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_asset_list_filters_remain_scoped_to_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        [, $otherOrganization] = $this->member(MembershipRole::Viewer);
        $localAsset = $this->asset($organization);
        $otherAsset = $this->asset($otherOrganization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('assets.index', ['statuses' => [$localAsset->status], 'unassigned' => 1]))
            ->assertOk()
            ->assertSee($localAsset->asset_number)
            ->assertDontSee($otherAsset->asset_number);
    }

    public function test_asset_list_attention_filter_shows_unassigned_and_former_employee_assets_only(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        [, $otherOrganization] = $this->member(MembershipRole::Viewer);
        $unassignedAsset = $this->asset($organization);
        $formerEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Former employee',
            'employment_status' => 'retired',
        ]);
        $formerEmployeeAsset = $this->asset($organization);
        $formerEmployeeAsset->update(['status' => config('opsdeck.asset_status.assigned')]);
        AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $formerEmployeeAsset->id,
            'employee_id' => $formerEmployee->id,
            'assigned_at' => now()->subMonth(),
        ]);
        $activeEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Active employee',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $activeEmployeeAsset = $this->asset($organization);
        $activeEmployeeAsset->update(['status' => config('opsdeck.asset_status.assigned')]);
        AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $activeEmployeeAsset->id,
            'employee_id' => $activeEmployee->id,
            'assigned_at' => now()->subMonth(),
        ]);
        $retiredAsset = $this->asset($organization);
        $retiredAsset->update(['status' => 'retired']);
        $otherOrganizationAsset = $this->asset($otherOrganization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('assets.index', ['needs_attention' => 1]))
            ->assertOk()
            ->assertSee($unassignedAsset->asset_number)
            ->assertSee($formerEmployeeAsset->asset_number)
            ->assertDontSee($activeEmployeeAsset->asset_number)
            ->assertDontSee($retiredAsset->asset_number)
            ->assertDontSee($otherOrganizationAsset->asset_number);
    }

    public function test_asset_list_rejects_invalid_attention_filter_values(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->getJson(route('assets.index', ['needs_attention' => 'unexpected']))
            ->assertUnprocessable();
    }

    public function test_asset_list_rejects_unknown_status_filters(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->getJson(route('assets.index', ['statuses' => ['unknown-status']]))
            ->assertUnprocessable();
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
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::AssetUpdated]);
    }

    public function test_asset_update_records_changed_field_names_without_asset_values(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $asset = $this->asset($organization);
        $changedAssetNumber = 'CHANGED-'.$asset->asset_number;

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('assets.update', $asset), $this->validAssetInput($changedAssetNumber))
            ->assertRedirect();

        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $asset->id)->where('action', AuditLog::AssetUpdated)->sole();

        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame($organization->id, $auditLog->organization_id);
        $this->assertSame($asset->id, $auditLog->entity_id);
        $this->assertSame(['changed_fields' => ['asset_number']], $auditLog->after_json);
        $this->assertStringNotContainsString($changedAssetNumber, json_encode($auditLog->toArray()));
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
