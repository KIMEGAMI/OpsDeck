<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_owner_checklist_progress_is_recorded_by_settings_and_renewal_workflows(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('導入チェックリスト')
            ->assertSee('社員表の取込プレビューを確認')
            ->assertSee('0/5 完了')
            ->assertSee('業務データへのImport実行')
            ->assertDontSee('Security Controlを確認')
            ->assertDontSee('更新期限を1件確認')
            ->assertSee('組織設定を確認')
            ->assertDontSee('完了として記録');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->put(route('settings.organization.update'), ['timezone' => 'UTC', 'company_size' => 10, 'ai_enabled' => false])
            ->assertRedirect(route('settings.organization'));
        $this->assertDatabaseHas('onboarding_steps', [
            'organization_id' => $organization->id,
            'step_key' => OnboardingStep::ORGANIZATION_SETTINGS_STEP,
            'completed_by' => $user->id,
        ]);

        $organization->update(['plan_code' => 'premium', 'billing_status' => 'active']);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('reminders.renewals'))
            ->assertOk()
            ->assertSee('更新期限を確認済みにする');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('onboarding.steps.renewal-check'))
            ->assertRedirect(route('reminders.renewals'));

        $this->assertDatabaseHas('onboarding_steps', [
            'organization_id' => $organization->id,
            'step_key' => OnboardingStep::RENEWAL_CHECKED_STEP,
            'completed_by' => $user->id,
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertSee('2/7 完了');
    }

    public function test_default_timezone_does_not_mark_organization_settings_as_confirmed(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $this->assertNotEmpty($organization->timezone);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('0/5 完了')
            ->assertSee('組織設定を確認');

        $this->assertDatabaseMissing('onboarding_steps', ['organization_id' => $organization->id, 'step_key' => 'organization_settings']);
    }

    public function test_invalid_organization_settings_do_not_complete_the_onboarding_step(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('settings.organization.update'), ['timezone' => 'not-a-timezone', 'company_size' => 10])
            ->assertSessionHasErrors('timezone');

        $this->assertDatabaseMissing('onboarding_steps', [
            'organization_id' => $organization->id,
            'step_key' => OnboardingStep::ORGANIZATION_SETTINGS_STEP,
        ]);
    }

    public function test_onboarding_progress_cannot_be_marked_through_a_generic_completion_endpoint(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post('/onboarding/steps', ['step_key' => 'employees_import'])
            ->assertNotFound();

        $this->assertDatabaseMissing('onboarding_steps', ['organization_id' => $organization->id, 'step_key' => 'employees_import']);
    }

    public function test_data_derived_step_remains_incomplete_even_if_a_manual_record_exists(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        OnboardingStep::query()->create(['organization_id' => $organization->id, 'step_key' => 'employees_import', 'completed_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('0/5 完了');
    }

    public function test_employee_data_without_a_completed_employee_import_does_not_complete_the_step(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        Employee::query()->create(['organization_id' => $organization->id, 'employee_number' => 'EMP-'.Str::upper(Str::random(6)), 'name' => '手入力社員', 'email' => 'manual-'.Str::uuid().'@example.test', 'employment_status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('0/5 完了')
            ->assertSee('社員表の取込プレビューを確認');
    }

    public function test_completed_import_for_a_different_target_does_not_complete_employee_import_step(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        Employee::query()->create(['organization_id' => $organization->id, 'employee_number' => 'EMP-'.Str::upper(Str::random(6)), 'name' => '手入力社員', 'email' => 'manual-'.Str::uuid().'@example.test', 'employment_status' => 'active']);
        $asset = Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-UNRELATED', 'asset_type' => 'laptop', 'status' => 'in_stock']);
        $importBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'assets.csv', 'status' => 'completed']);
        $mapping = $importBatch->mappings()->create(['target_type' => 'assets', 'column_map' => [], 'status' => 'ready']);
        $importBatch->update(['mapping_id' => (string) $mapping->id]);
        $importBatch->rowResults()->create(['source_row_number' => 1, 'target_type' => 'assets', 'target_id' => $asset->id, 'action' => 'inserted']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('1/5 完了');
    }

    public function test_only_the_mapping_used_by_the_completed_batch_counts_toward_import_step(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        Employee::query()->create(['organization_id' => $organization->id, 'employee_number' => 'EMP-'.Str::upper(Str::random(6)), 'name' => '手入力社員', 'email' => 'manual-'.Str::uuid().'@example.test', 'employment_status' => 'active']);
        $importBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'assets.csv', 'status' => 'completed']);
        $importBatch->mappings()->create(['target_type' => 'employees', 'column_map' => [], 'status' => 'ready']);
        $executedMapping = $importBatch->mappings()->create(['target_type' => 'assets', 'column_map' => [], 'status' => 'ready']);
        $importBatch->update(['mapping_id' => (string) $executedMapping->id]);
        $employee = Employee::query()->where('organization_id', $organization->id)->firstOrFail();
        $importBatch->rowResults()->create(['source_row_number' => 1, 'target_type' => 'employees', 'target_id' => $employee->id, 'action' => 'inserted']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('1/5 完了');
    }

    public function test_completed_asset_import_with_asset_data_completes_only_the_asset_step(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $asset = Asset::query()->create(['organization_id' => $organization->id, 'asset_number' => 'ASSET-ONBOARDING', 'asset_type' => 'laptop', 'status' => 'in_stock']);
        $importBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'assets.csv', 'status' => 'completed']);
        $mapping = $importBatch->mappings()->create(['target_type' => 'assets', 'column_map' => [], 'status' => 'ready']);
        $importBatch->update(['mapping_id' => (string) $mapping->id]);
        $importBatch->rowResults()->create(['source_row_number' => 1, 'target_type' => 'assets', 'target_id' => $asset->id, 'action' => 'inserted']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('1/5 完了');
    }

    public function test_admin_can_see_data_derived_completion_but_viewer_cannot_manage_checklist(): void
    {
        [$admin, $organization] = $this->member(MembershipRole::Admin);
        $organization->update(['plan_code' => 'premium', 'billing_status' => 'active']);
        $employee = Employee::query()->create(['organization_id' => $organization->id, 'employee_number' => 'EMP-'.Str::upper(Str::random(6)), 'name' => '導入確認', 'email' => 'onboarding-'.Str::uuid().'@example.test', 'employment_status' => 'active']);
        $importBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'employees.csv', 'status' => 'completed']);
        $mapping = $importBatch->mappings()->create(['target_type' => 'employees', 'column_map' => [], 'status' => 'ready']);
        $importBatch->update(['mapping_id' => (string) $mapping->id]);
        $importBatch->rowResults()->create(['source_row_number' => 1, 'target_type' => 'employees', 'target_id' => $employee->id, 'action' => 'inserted']);
        $this->actingAs($admin)->withSession(['current_organization_id' => $organization->id])->get(route('onboarding.index'))->assertOk()->assertSee('✅');

        SecurityControl::query()->create(['organization_id' => $organization->id, 'control_code' => 'AC-1', 'title' => 'アクセス制御', 'category' => 'access', 'description' => '導入確認', 'status' => 'unknown']);
        $this->actingAs($admin)->withSession(['current_organization_id' => $organization->id])->get(route('onboarding.index'))->assertOk()->assertSee('2/7 完了')->assertSee('Security Controlを確認');

        [$viewer, $viewerOrganization] = $this->member(MembershipRole::Viewer);
        $this->actingAs($viewer)->withSession(['current_organization_id' => $viewerOrganization->id])->get(route('onboarding.index'))->assertForbidden();
    }

    public function test_a_valid_pending_invitation_completes_the_member_invitation_step(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'invited_by' => $user->id,
            'email' => 'pending-'.Str::uuid().'@example.test',
            'role' => MembershipRole::Operator->value,
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(config('opsdeck.invitation_expiry_days')),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk();

        $memberStep = collect($response->viewData('steps'))->firstWhere('key', 'member_invited');
        $this->assertTrue($memberStep['completed']);
    }

    public function test_expired_or_revoked_invitations_do_not_complete_the_member_invitation_step(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        foreach ([
            ['email' => 'expired-'.Str::uuid().'@example.test', 'expires_at' => now()->subMinute()],
            ['email' => 'revoked-'.Str::uuid().'@example.test', 'expires_at' => now()->addDay(), 'revoked_at' => now()],
        ] as $attributes) {
            OrganizationInvitation::query()->create(array_merge([
                'organization_id' => $organization->id,
                'invited_by' => $user->id,
                'role' => MembershipRole::Operator->value,
                'token_hash' => hash('sha256', Str::random(64)),
            ], $attributes));
        }

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk();

        $memberStep = collect($response->viewData('steps'))->firstWhere('key', 'member_invited');
        $this->assertFalse($memberStep['completed']);
    }

    public function test_free_plan_can_complete_import_onboarding_steps_with_a_saved_csv_preview(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'status' => 'ready',
        ]);
        $mapping = $batch->mappings()->create(['target_type' => 'employees', 'column_map' => [], 'status' => 'ready']);
        $batch->update(['mapping_id' => (string) $mapping->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('1/5 完了')
            ->assertSee('社員表の取込プレビューを確認')
            ->assertDontSee('社員表をImport');

        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_import_completed',
        ]);
    }

    public function test_free_owner_sees_initial_setup_complete_after_finishing_all_free_steps(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->put(route('settings.organization.update'), ['timezone' => 'UTC', 'company_size' => 10, 'ai_enabled' => false])
            ->assertRedirect(route('settings.organization'));

        foreach (['employees', 'assets'] as $targetType) {
            $batch = ImportBatch::query()->create([
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => $targetType.'.csv',
                'status' => 'ready',
            ]);
            $mapping = $batch->mappings()->create(['target_type' => $targetType, 'column_map' => [], 'status' => 'ready']);
            $batch->update(['mapping_id' => (string) $mapping->id]);
        }

        SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Onboarding SaaS',
            'status' => 'active',
        ]);
        OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'invited_by' => $user->id,
            'email' => 'onboarding-member-'.Str::uuid().'@example.test',
            'role' => MembershipRole::Operator->value,
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(config('opsdeck.invitation_expiry_days')),
        ]);

        $this->actingAs($user)->withSession($session)
            ->get(route('onboarding.index'))
            ->assertSee('5/5 完了')
            ->assertSee('Freeプランの初期設定が完了')
            ->assertDontSee('導入完了です。');

        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'onboarding_completed',
        ]);
    }

    public function test_viewer_can_view_renewal_reminders_without_needing_onboarding_confirmation(): void
    {
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $organization->update(['plan_code' => 'premium', 'billing_status' => 'active']);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('reminders.renewals'))
            ->assertOk()
            ->assertDontSee('確認済みにする');
        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('onboarding.steps.renewal-check'))
            ->assertForbidden();

        $this->assertDatabaseMissing('onboarding_steps', [
            'organization_id' => $organization->id,
            'step_key' => OnboardingStep::RENEWAL_CHECKED_STEP,
        ]);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'onboarding-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Onboarding '.$suffix, 'slug' => 'onboarding-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
