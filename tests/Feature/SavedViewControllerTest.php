<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SavedView;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SavedViewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_operator_can_create_personal_saved_view_and_viewer_can_read_shared_view(): void
    {
        [$operator, $organization] = $this->member(MembershipRole::Operator);
        $this->actingAs($operator)->withSession(['current_organization_id' => $organization->id])
            ->get(route('saved-views.create'))
            ->assertOk()
            ->assertSee('作成しても契約や資産のデータは変更されません。')
            ->assertSee('作成した一覧は自分だけが見られます。')
            ->assertSee('例：今月更新する契約');
        $admin = User::factory()->create(['email' => 'saved-share-admin-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $admin->id, 'role' => MembershipRole::Admin, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $this->actingAs($admin)->withSession(['current_organization_id' => $organization->id])
            ->get(route('saved-views.create'))
            ->assertOk()
            ->assertSee('組織内で共有すると、同じ組織のメンバー全員がこの一覧を見られます。')
            ->assertSee('組織内で共有する');
        $response = $this->actingAs($operator)->withSession(['current_organization_id' => $organization->id])->post(route('saved-views.store'), ['name' => '更新確認', 'view_key' => 'renewals', 'is_shared' => '1']);
        $response->assertRedirect(route('saved-views.index'));
        $savedView = SavedView::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertFalse($savedView->is_shared);

        [$viewer, $viewerOrganization] = $this->member(MembershipRole::Viewer);
        $shared = SavedView::query()->create(['organization_id' => $viewerOrganization->id, 'created_by' => $viewer->id, 'name' => '共有MFA', 'view_key' => 'mfa_unknown', 'is_shared' => true]);
        $this->actingAs($viewer)->withSession(['current_organization_id' => $viewerOrganization->id])->get(route('saved-views.show', $shared))->assertOk()->assertSee('共有MFA');
        $this->actingAs($viewer)->withSession(['current_organization_id' => $viewerOrganization->id])
            ->get(route('saved-views.index'))
            ->assertOk()
            ->assertSee('更新期限、未割当の端末、ログイン時の追加確認が済んでいないクラウドサービスのアカウントを、保存した条件で確認できます。')
            ->assertSee('ログイン時の追加確認が済んでいないクラウドサービスのアカウント')
            ->assertDontSee('MFA未確認SaaSアカウント');
        $this->actingAs($viewer)->withSession(['current_organization_id' => $viewerOrganization->id])->get(route('saved-views.create'))->assertForbidden();
    }

    public function test_invalid_saved_view_name_is_shown_next_to_field_and_preserved(): void
    {
        [$operator, $organization] = $this->member(MembershipRole::Operator);
        $invalidName = str_repeat('更', 121);

        $this->from(route('saved-views.create'))
            ->followingRedirects()
            ->actingAs($operator)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saved-views.store'), [
                'name' => $invalidName,
                'view_key' => 'renewals',
            ])
            ->assertOk()
            ->assertSee('id="name-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="name-error"', false)
            ->assertSee('value="'.$invalidName.'"', false);

        $this->assertDatabaseMissing('saved_views', [
            'organization_id' => $organization->id,
            'name' => $invalidName,
        ]);
    }

    public function test_personal_and_shared_saved_views_have_distinct_delete_permissions(): void
    {
        [$creator, $organization] = $this->member(MembershipRole::Operator);
        $admin = User::factory()->create(['email' => 'saved-admin-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $admin->id, 'role' => MembershipRole::Admin, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $viewer = User::factory()->create(['email' => 'saved-viewer-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $viewer->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $personalView = SavedView::query()->create(['organization_id' => $organization->id, 'created_by' => $creator->id, 'name' => '個人ビュー', 'view_key' => 'renewals', 'is_shared' => false]);
        $sharedView = SavedView::query()->create(['organization_id' => $organization->id, 'created_by' => $creator->id, 'name' => '共有ビュー', 'view_key' => 'mfa_unknown', 'is_shared' => true]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($creator)->withSession($session)->get(route('saved-views.index'))->assertOk()->assertSee('個人ビューを削除')->assertDontSee('共有ビューを削除');
        $this->actingAs($admin)->withSession($session)->get(route('saved-views.index'))->assertOk()->assertSee('共有ビューを削除')->assertDontSee('個人ビューを削除');
        $this->actingAs($admin)->withSession($session)->delete(route('saved-views.destroy', $personalView))->assertForbidden();
        $this->actingAs($viewer)->withSession($session)->delete(route('saved-views.destroy', $sharedView))->assertForbidden();
        $this->assertDatabaseHas('saved_views', ['id' => $personalView->id]);
        $this->assertDatabaseHas('saved_views', ['id' => $sharedView->id]);

        $this->actingAs($creator)->withSession($session)->delete(route('saved-views.destroy', $personalView))->assertRedirect(route('saved-views.index'));
        $this->assertDatabaseMissing('saved_views', ['id' => $personalView->id]);

        $this->actingAs($admin)->withSession($session)->delete(route('saved-views.destroy', $sharedView))->assertRedirect(route('saved-views.index'));
        $this->assertDatabaseMissing('saved_views', ['id' => $sharedView->id]);
        $auditLog = AuditLog::query()->where('entity_id', $sharedView->id)->where('action', AuditLog::SavedViewDeleted)->sole();
        $this->assertSame($admin->id, $auditLog->actor_user_id);
        $this->assertSame(['view_key' => 'mfa_unknown', 'is_shared' => true], $auditLog->before_json);
        $this->assertStringNotContainsString('共有ビュー', json_encode($auditLog->toArray()));
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $personalView->id, 'action' => AuditLog::SavedViewDeleted]);
    }

    public function test_owner_sharing_a_saved_view_records_the_shared_change_without_the_view_name(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->post(route('saved-views.store'), [
                'name' => '顧客情報を含む社内名',
                'view_key' => 'renewals',
                'is_shared' => '1',
            ])
            ->assertRedirect(route('saved-views.index'));

        $savedView = SavedView::query()->where('organization_id', $organization->id)->sole();
        $auditLog = AuditLog::query()->where('entity_id', $savedView->id)->where('action', AuditLog::SavedViewCreated)->sole();
        $this->assertSame($owner->id, $auditLog->actor_user_id);
        $this->assertSame(['view_key' => 'renewals', 'is_shared' => true], $auditLog->after_json);
        $this->assertStringNotContainsString('顧客情報を含む社内名', json_encode($auditLog->toArray()));
    }

    public function test_renewal_saved_view_uses_the_organizations_local_date(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 00:30:00', 'UTC'));
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['timezone' => 'America/Los_Angeles']);
        $savedView = SavedView::query()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'name' => '組織日付の更新確認',
            'view_key' => 'renewals',
            'is_shared' => true,
        ]);
        Contract::query()->create([
            'organization_id' => $organization->id,
            'vendor_name' => 'Today vendor',
            'contract_name' => 'Organization local today',
            'contract_type' => 'service',
            'billing_cycle' => 'monthly',
            'amount' => 1000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
            'renewal_date' => '2026-10-10',
        ]);
        Contract::query()->create([
            'organization_id' => $organization->id,
            'vendor_name' => 'Cancellation vendor',
            'contract_name' => 'Cancellation deadline in window',
            'contract_type' => 'service',
            'billing_cycle' => 'monthly',
            'amount' => 1000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
            'cancellation_deadline' => '2026-10-20',
        ]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('saved-views.show', $savedView))
            ->assertOk()
            ->assertSee('Organization local today')
            ->assertSee('Cancellation deadline in window');
    }

    public function test_operational_saved_views_exclude_retired_assets_and_ended_or_inactive_saas(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $activeAsset = Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-CURRENT', 'asset_type' => 'laptop', 'status' => 'in_stock']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-RETIRED', 'asset_type' => 'laptop', 'status' => 'retired']);
        Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-DISPOSED', 'asset_type' => 'laptop', 'status' => 'disposed']);
        $activeService = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Current service', 'status' => config('opsdeck.saas_service_active_status')]);
        $inactiveService = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Inactive service', 'status' => 'inactive']);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $activeService->id, 'account_identifier' => 'ACTIVE-UNKNOWN', 'status' => 'active', 'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown')]);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $activeService->id, 'account_identifier' => 'ENDED-UNKNOWN', 'status' => 'ended', 'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'), 'ended_at' => now()->subDay()]);
        SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $inactiveService->id, 'account_identifier' => 'INACTIVE-UNKNOWN', 'status' => 'active', 'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown')]);
        $assetView = SavedView::query()->create(['organization_id' => $organization->id, 'created_by' => $owner->id, 'name' => 'Current unassigned assets', 'view_key' => 'unassigned_assets', 'is_shared' => true]);
        $mfaView = SavedView::query()->create(['organization_id' => $organization->id, 'created_by' => $owner->id, 'name' => 'Current unknown MFA', 'view_key' => 'mfa_unknown', 'is_shared' => true]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('saved-views.show', $assetView))
            ->assertOk()
            ->assertSee($activeAsset->asset_number)
            ->assertDontSee('ASSET-RETIRED')
            ->assertDontSee('ASSET-DISPOSED');

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('saved-views.show', $mfaView))
            ->assertOk()
            ->assertSee('ACTIVE-UNKNOWN')
            ->assertSee('追加のログイン確認：未確認')
            ->assertDontSee('(MFA：unknown)')
            ->assertDontSee('ENDED-UNKNOWN')
            ->assertDontSee('INACTIVE-UNKNOWN');
    }

    public function test_saved_view_indexes_and_results_are_paginated_with_total_counts(): void
    {
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        config(['opsdeck.pagination.per_page' => 2]);

        foreach (['A', 'B', 'C'] as $suffix) {
            Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'PAGINATED-ASSET-'.$suffix, 'asset_type' => 'laptop', 'status' => 'in_stock']);
            SavedView::query()->create(['organization_id' => $organization->id, 'created_by' => $owner->id, 'name' => 'Saved view '.$suffix, 'view_key' => 'unassigned_assets', 'is_shared' => true]);
        }
        $assetView = SavedView::query()->where('organization_id', $organization->id)->firstOrFail();
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->get(route('saved-views.index'))
            ->assertOk()
            ->assertSee('Saved view C')
            ->assertSee('Saved view B')
            ->assertDontSee('Saved view A')
            ->assertSee('page=2');

        $this->actingAs($owner)->withSession($session)->get(route('saved-views.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Saved view A')
            ->assertDontSee('Saved view C');

        $this->actingAs($owner)->withSession($session)->get(route('saved-views.show', $assetView))
            ->assertOk()
            ->assertSee('3件')
            ->assertSee('PAGINATED-ASSET-A')
            ->assertSee('PAGINATED-ASSET-B')
            ->assertDontSee('PAGINATED-ASSET-C')
            ->assertSee('page=2');

        $this->actingAs($owner)->withSession($session)->get(route('saved-views.show', $assetView).'?page=2')
            ->assertOk()
            ->assertSee('3件')
            ->assertSee('PAGINATED-ASSET-C')
            ->assertDontSee('PAGINATED-ASSET-A');
    }

    public function test_saved_renewals_are_stable_when_dates_match_and_mfa_queries_have_an_id_tie_breaker(): void
    {
        config(['opsdeck.pagination.per_page' => 1]);
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $dueDate = CarbonImmutable::today($organization->timezone)->addDay()->toDateString();
        $renewalView = SavedView::query()->create(['organization_id' => $organization->id, 'created_by' => $owner->id, 'name' => '同日期の更新', 'view_key' => 'renewals', 'is_shared' => true]);
        $firstContract = Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => '同日ベンダー', 'contract_name' => '同日契約1', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => $dueDate, 'ends_at' => $dueDate]);
        $secondContract = Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => '同日ベンダー', 'contract_name' => '同日契約2', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => $dueDate, 'ends_at' => $dueDate]);
        $mfaView = SavedView::query()->create(['organization_id' => $organization->id, 'created_by' => $owner->id, 'name' => 'MFA未確認', 'view_key' => 'mfa_unknown', 'is_shared' => true]);
        $service = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'MFAサービス', 'status' => config('opsdeck.saas_service_active_status')]);
        foreach (range(1, 2) as $number) {
            SaasAssignment::query()->create(['organization_id' => $organization->id, 'saas_service_id' => $service->id, 'account_identifier' => '同一アカウント', 'status' => 'active', 'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown')]);
        }
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)
            ->get(route('saved-views.show', $renewalView).'?page=1')
            ->assertOk()
            ->assertSee('同日契約1')
            ->assertDontSee('同日契約2');

        $this->actingAs($owner)->withSession($session)
            ->get(route('saved-views.show', $renewalView).'?page=2')
            ->assertOk()
            ->assertSee('同日契約2')
            ->assertDontSee('同日契約1');

        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            if (str_contains($query->sql, 'saas_assignments')) {
                $statements[] = $query->sql;
            }
        });

        $this->actingAs($owner)->withSession($session)
            ->get(route('saved-views.show', $mfaView))
            ->assertOk()
            ->assertSee('2件');

        $mfaQuery = implode("\n", $statements);
        $this->assertMatchesRegularExpression('/order by .*account_identifier.*asc, .*id.*asc/i', $mfaQuery);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'saved-view-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Saved View '.$suffix, 'slug' => 'saved-view-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
