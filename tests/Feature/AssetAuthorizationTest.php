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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_screens_show_human_readable_japanese_labels(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $asset = $this->asset($organization);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('assets.index'))
            ->assertOk()
            ->assertSee('パソコン')
            ->assertSee('在庫（貸出可能）');

        $this->get(route('assets.show', $asset))
            ->assertOk()
            ->assertSee('パソコン')
            ->assertSee('在庫（貸出可能）');

        $this->get(route('assets.create'))
            ->assertOk()
            ->assertSee('スマートフォン')
            ->assertSee('貸出中');
    }

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
        $asset = Asset::query()->where('organization_id', $organization->id)->where('asset_number', $assetNumber)->sole();
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $asset->id)->where('action', AuditLog::AssetCreated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['created_fields' => ['asset_number', 'asset_type', 'status']], $auditLog->after_json);
        $this->assertStringNotContainsString($assetNumber, json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_invalid_asset_purchase_cost_is_shown_next_to_its_field_and_preserved_for_correction(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $assetNumber = 'ASSET-'.Str::upper(Str::random(8));

        $this->from(route('assets.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('assets.store'), $this->validAssetInput($assetNumber) + ['purchase_cost' => '-1'])
            ->assertOk()
            ->assertSee('id="purchase_cost-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="purchase_cost-error"', false)
            ->assertSee('value="-1"', false);

        $this->assertDatabaseMissing('assets', ['organization_id' => $organization->id, 'asset_number' => $assetNumber]);
    }

    public function test_invalid_asset_type_and_status_values_do_not_select_an_unintended_option(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $assetNumber = 'ASSET-'.Str::upper(Str::random(8));
        $defaultAssetType = config('opsdeck.asset_types')[0];
        $defaultAssetStatus = config('opsdeck.asset_statuses')[0];

        $this->from(route('assets.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('assets.store'), array_merge($this->validAssetInput($assetNumber), [
                'asset_type' => 'not-a-configured-type',
                'status' => 'not-a-configured-status',
            ]))
            ->assertOk()
            ->assertSee('id="asset_type-error" class="errors field-error"', false)
            ->assertSee('id="status-error" class="errors field-error"', false)
            ->assertSee('<option value="'.$defaultAssetType.'" selected>'.config('opsdeck.asset_type_labels.'.$defaultAssetType).'</option>', false)
            ->assertSee('<option value="'.$defaultAssetStatus.'" selected>'.config('opsdeck.asset_status_labels.'.$defaultAssetStatus).'</option>', false);

        $this->assertDatabaseMissing('assets', ['organization_id' => $organization->id, 'asset_number' => $assetNumber]);
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
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
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
