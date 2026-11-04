<?php

namespace Tests\Feature\Feature\Employee;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_cannot_create_employee_beyond_plan_limit(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Config::set('opsdeck.plans.free.limits.employees', 1);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '既存社員', 'employment_status' => 'active']);

        $this->from(route('employees.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post('/employees', ['name' => '上限超過社員', 'employment_status' => 'active'])
            ->assertOk()
            ->assertSee('利用上限を超えた場合は、不要な登録データを整理してください。')
            ->assertDontSee(route('billing.index'));

        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_owner_receives_billing_link_when_employee_limit_is_reached(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Config::set('opsdeck.plans.free.limits.employees', 1);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '既存社員', 'employment_status' => 'active']);

        $this->from(route('employees.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post('/employees', ['name' => '上限超過社員', 'employment_status' => 'active'])
            ->assertOk()
            ->assertSee('無料公開と利用上限を確認する')
            ->assertSee('href="'.route('billing.index').'"', false);

        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
    }

    public function test_operator_can_create_employee_only_in_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post('/employees', [
                'name' => '田中 太郎',
                'employment_status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'organization_id' => $organization->id,
            'name' => '田中 太郎',
        ]);
        $employee = Employee::query()->where('organization_id', $organization->id)->where('name', '田中 太郎')->sole();
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $employee->id)->where('action', AuditLog::EmployeeCreated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['created_fields' => ['name', 'employment_status']], $auditLog->after_json);
        $this->assertStringNotContainsString('田中 太郎', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_invalid_employee_email_is_shown_next_to_its_field_and_preserved_for_correction(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $response = $this->from(route('employees.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('employees.store'), [
                'name' => '確認する社員',
                'email' => 'not-an-email',
                'employment_status' => 'active',
            ])
            ->assertOk()
            ->assertSee('id="email-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="email-error"', false)
            ->assertSee('value="not-an-email"', false);

        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertStringContainsString('id="email" name="email" type="email"', $response->getContent());
    }

    public function test_viewer_cannot_create_an_employee(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('employees.store'), ['name' => '閲覧者からの登録', 'employment_status' => 'active'])
            ->assertForbidden();

        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'name' => '閲覧者からの登録']);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_viewer_cannot_update_an_employee(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '変更前の社員',
            'employment_status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('employees.update', $employee), ['name' => '変更後の社員', 'employment_status' => 'active'])
            ->assertForbidden();

        $this->assertSame('変更前の社員', $employee->refresh()->name);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::EmployeeUpdated]);
    }

    public function test_employee_update_records_changed_field_names_without_personal_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '変更前の社員',
            'email' => 'private@example.test',
            'employment_status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('employees.update', $employee), [
                'name' => '個人情報の変更値',
                'email' => 'private-new@example.test',
                'employment_status' => 'active',
            ])
            ->assertRedirect();

        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $employee->id)->where('action', AuditLog::EmployeeUpdated)->sole();

        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame($organization->id, $auditLog->organization_id);
        $this->assertSame($employee->id, $auditLog->entity_id);
        $this->assertSame(['changed_fields' => ['name', 'email']], $auditLog->after_json);
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
        $this->assertStringNotContainsString('個人情報の変更値', json_encode($auditLog->toArray()));
        $this->assertStringNotContainsString('private-new@example.test', json_encode($auditLog->toArray()));
    }

    public function test_operator_cannot_update_an_employee_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $employee = Employee::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => '別組織の社員',
            'employment_status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('employees.update', $employee), ['name' => '改ざん試行', 'employment_status' => 'active'])
            ->assertNotFound();

        $this->assertSame('別組織の社員', $employee->refresh()->name);
    }

    public function test_user_cannot_view_employee_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $otherEmployee = Employee::query()->create([
            'organization_id' => Organization::query()->create([
                'name' => 'Other Organization',
                'slug' => 'other-'.Str::lower(Str::random(config('opsdeck.slug_suffix_length'))),
                'timezone' => 'Asia/Tokyo',
                'plan_code' => config('opsdeck.default_plan_code'),
                'billing_status' => config('opsdeck.default_billing_status'),
            ])->id,
            'name' => 'Other Employee',
            'employment_status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $otherEmployee))
            ->assertNotFound();
    }

    public function test_departed_employee_shows_remaining_opsdeck_records_as_an_offboarding_checklist(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '退職予定の社員',
            'employment_status' => config('opsdeck.employee_status.retired'),
        ]);
        $asset = Asset::query()->create([
            'organization_id' => $organization->id,
            'asset_number' => 'ASSET-OFFBOARD-1',
            'asset_type' => 'pc',
            'status' => 'in_use',
        ]);
        AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'assigned_at' => now()->toDateString(),
        ]);
        $returnedAsset = Asset::query()->create([
            'organization_id' => $organization->id,
            'asset_number' => 'ASSET-OFFBOARD-RETURNED',
            'asset_type' => 'pc',
            'status' => 'in_stock',
        ]);
        AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $returnedAsset->id,
            'employee_id' => $employee->id,
            'assigned_at' => now()->subDays(3)->toDateString(),
            'returned_at' => now()->subDay()->toDateString(),
        ]);
        $service = SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => '退職時確認サービス',
            'status' => config('opsdeck.saas_service_active_status'),
        ]);
        $saasAssignment = SaasAssignment::query()->create([
            'organization_id' => $organization->id,
            'saas_service_id' => $service->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'vendor_name' => '契約先',
            'contract_name' => '退職時引き継ぎ契約',
            'contract_type' => 'subscription',
            'billing_cycle' => 'monthly',
            'amount' => 9800,
            'currency' => 'JPY',
            'auto_renew' => true,
            'owner_employee_id' => $employee->id,
            'status' => 'active',
        ]);
        $securityControl = SecurityControl::query()->create([
            'organization_id' => $organization->id,
            'control_code' => 'OFFBOARD-1',
            'title' => '退職時の引き継ぎ確認項目',
            'category' => 'access_control',
            'description' => '確認用',
            'status' => 'unknown',
            'owner_employee_id' => $employee->id,
        ]);
        SecurityControl::query()->create([
            'organization_id' => $organization->id,
            'control_code' => 'OFFBOARD-NA',
            'title' => '対象外の退職時確認項目',
            'category' => 'access_control',
            'description' => '確認用',
            'status' => 'not_applicable',
            'owner_employee_id' => $employee->id,
        ]);
        [, $otherOrganization] = $this->member(MembershipRole::Owner);
        $otherService = SaasService::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => '別組織のサービス',
            'status' => config('opsdeck.saas_service_active_status'),
        ]);
        SaasAssignment::query()->create([
            'organization_id' => $otherOrganization->id,
            'saas_service_id' => $otherService->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('退職時の確認')
            ->assertSee('貸与端末の返却')
            ->assertSee('クラウドサービスの利用終了')
            ->assertSee('有効な契約の担当者引き継ぎ')
            ->assertSee('セキュリティ管理項目の担当者引き継ぎ')
            ->assertSee('ASSET-OFFBOARD-1')
            ->assertDontSee('ASSET-OFFBOARD-RETURNED')
            ->assertSee('退職時確認サービス')
            ->assertDontSee('別組織のサービス')
            ->assertSee('退職時引き継ぎ契約')
            ->assertSee('退職時の引き継ぎ確認項目')
            ->assertDontSee('対象外の退職時確認項目')
            ->assertSee(route('assets.show', $asset))
            ->assertSee(route('saas-assignments.edit', $saasAssignment))
            ->assertSee(route('contracts.show', $contract))
            ->assertSee(route('contracts.edit', $contract))
            ->assertSee(route('security-controls.show', $securityControl))
            ->assertSee(route('security-controls.edit', $securityControl))
            ->assertSee('返却を確認')
            ->assertSee('利用終了を記録')
            ->assertSee('担当者の引き継ぎを記録')
            ->assertSee('外部サービスのアカウント停止や権限移管が完了したかは');

        $viewer = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $viewer->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('退職時の確認')
            ->assertDontSee(route('saas-assignments.edit', $saasAssignment))
            ->assertDontSee(route('contracts.edit', $contract))
            ->assertDontSee(route('security-controls.edit', $securityControl));
    }

    public function test_retired_employee_with_no_open_records_is_not_misreported_as_fully_offboarded(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $organization->update(['plan_code' => config('opsdeck.free_plan_code')]);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '登録対象がない退職者',
            'employment_status' => config('opsdeck.employee_status.retired'),
        ]);
        $freePlanSecurityControl = SecurityControl::query()->create([
            'organization_id' => $organization->id,
            'control_code' => 'FREE-OFFBOARD-1',
            'title' => '退職時の確認対象',
            'category' => 'access_control',
            'description' => 'Premium限定項目',
            'status' => 'unknown',
            'owner_employee_id' => $employee->id,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('退職時の確認')
            ->assertSee('1件の確認が必要です。')
            ->assertSee($freePlanSecurityControl->title)
            ->assertSee(route('security-controls.show', $freePlanSecurityControl), false)
            ->assertSee('外部サービスのアカウント停止や権限移管が完了したかは')
            ->assertDontSee('OpsDeck上の登録では、未処理として残る項目はありません。');
    }

    public function test_offboarding_checklist_includes_opsdeck_membership_and_pending_invitation_actions(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$owner, $organization] = $this->member(MembershipRole::Owner);
        $memberEmail = 'offboard-member-'.Str::uuid().'@example.test';
        $memberEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'OpsDeck利用者の退職者',
            'email' => $memberEmail,
            'employment_status' => config('opsdeck.employee_status.retired'),
        ]);
        $memberUser = User::factory()->create(['name' => '停止対象のOpsDeck利用者', 'email' => $memberEmail]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $memberUser->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $unrelatedUser = User::factory()->create(['name' => '無関係の組織メンバー']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $unrelatedUser->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $invitedEmail = 'offboard-invitation-'.Str::uuid().'@example.test';
        $invitedEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '招待中アカウントの退職者',
            'email' => $invitedEmail,
            'employment_status' => config('opsdeck.employee_status.retired'),
        ]);
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'invited_by' => $owner->id,
            'email' => $invitedEmail,
            'role' => MembershipRole::Operator,
            'token_hash' => hash('sha256', Str::uuid()->toString()),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $memberEmployee))
            ->assertOk()
            ->assertSee('OpsDeck利用者の権限確認')
            ->assertSee('停止対象のOpsDeck利用者')
            ->assertSee($memberEmail)
            ->assertSee('この利用者の設定を確認')
            ->assertSee(route('settings.members', ['membership_id' => $membership->id]), false);

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('settings.members', ['membership_id' => $membership->id]))
            ->assertOk()
            ->assertSee('退職チェックリストの対象メンバーを表示しています。')
            ->assertSee($memberUser->name)
            ->assertDontSee($unrelatedUser->name);

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $invitedEmployee))
            ->assertOk()
            ->assertSee('未承諾のOpsDeck招待の取り消し')
            ->assertSee($invitedEmail)
            ->assertSee('招待を取り消す')
            ->assertSee(route('settings.members.invitations.revoke', $invitation), false);
    }

    public function test_active_employee_does_not_show_the_offboarding_checklist(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '在籍中の社員',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertDontSee('退職時の確認');
    }

    public function test_active_employee_with_a_departure_date_can_be_reviewed_before_retirement(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '退職日を設定済みの社員',
            'employment_status' => config('opsdeck.employee_status.active'),
            'left_at' => today()->addDays(14),
        ]);
        $asset = Asset::query()->create([
            'organization_id' => $organization->id,
            'asset_number' => 'ASSET-PRE-OFFBOARD-1',
            'asset_type' => 'pc',
            'status' => config('opsdeck.asset_status.assigned'),
        ]);
        AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'assigned_at' => today()->subDays(30),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('退職時の確認')
            ->assertSee('ASSET-PRE-OFFBOARD-1')
            ->assertSee('返却を確認')
            ->assertDontSee('入社時のIT確認')
            ->assertSee('在籍中');
    }

    public function test_active_employee_shows_a_scoped_onboarding_checklist_with_existing_actions(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '入社した社員',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $asset = Asset::query()->create([
            'organization_id' => $organization->id,
            'asset_number' => 'ASSET-ONBOARD-1',
            'asset_type' => 'pc',
            'status' => config('opsdeck.asset_status.in_stock'),
        ]);
        AssetAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'assigned_at' => now()->toDateString(),
        ]);
        $availableAsset = Asset::query()->create([
            'organization_id' => $organization->id,
            'asset_number' => 'ASSET-ONBOARD-AVAILABLE',
            'asset_type' => 'pc',
            'status' => config('opsdeck.asset_status.in_stock'),
        ]);
        $service = SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => '入社時確認サービス',
            'status' => config('opsdeck.saas_service_active_status'),
        ]);
        SaasAssignment::query()->create([
            'organization_id' => $organization->id,
            'saas_service_id' => $service->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
        ]);
        SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => '利用停止中サービス',
            'status' => config('opsdeck.saas_service_statuses')[1],
        ]);
        [, $otherOrganization] = $this->member(MembershipRole::Owner);
        SaasService::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => '別組織サービス',
            'status' => config('opsdeck.saas_service_active_status'),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('入社時のIT確認')
            ->assertSee('ASSET-ONBOARD-1')
            ->assertSee(route('assets.index', [
                'statuses' => [config('opsdeck.asset_status.in_stock')],
                'unassigned' => true,
                'employee_id' => $employee->id,
            ]))
            ->assertSee('入社時確認サービス')
            ->assertSee(route('saas-services.show', ['saas_service' => $service, 'employee_id' => $employee->id]))
            ->assertDontSee('利用停止中サービス')
            ->assertDontSee('別組織サービス')
            ->assertDontSee('退職時の確認')
            ->assertSee('必要な端末・サービスだけ登録してください。');

        $this->get(route('assets.show', ['asset' => $availableAsset, 'employee_id' => $employee->id]))
            ->assertOk()
            ->assertSee('<option value="'.$employee->id.'" selected>'.$employee->name.'</option>', false);
    }

    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'user-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Organization '.$suffix,
            'slug' => 'organization-'.$suffix,
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
}
