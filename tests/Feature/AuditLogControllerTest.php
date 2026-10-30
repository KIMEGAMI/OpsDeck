<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_displays_japanese_operation_and_entity_labels(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Admin);
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'entity_type' => 'App\\Models\\GoogleSheetConnection',
            'action' => AuditLog::GoogleSheetDisconnected,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('Googleスプレッドシートの接続を解除')
            ->assertSee('Googleスプレッドシート接続')
            ->assertDontSee(AuditLog::GoogleSheetDisconnected);
    }

    public function test_admin_can_view_current_organization_audit_logs(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Admin);
        AuditLog::query()->create(['organization_id' => $organization->id, 'actor_user_id' => $user->id, 'entity_type' => 'QuestionnaireAnswer', 'action' => AuditLog::QuestionnaireAnswerApproved]);
        AuditLog::query()->create(['organization_id' => $organization->id, 'entity_type' => 'FutureRecord', 'action' => 'future.internal_action']);
        [, $otherOrganization] = $this->member(MembershipRole::Admin);
        AuditLog::query()->create(['organization_id' => $otherOrganization->id, 'entity_type' => 'QuestionnaireAnswer', 'action' => 'other_organization.private_action']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('質問票の回答を承認')
            ->assertSee('質問票の回答')
            ->assertSee('その他の操作')
            ->assertSee('記録')
            ->assertDontSee($user->email)
            ->assertDontSee(AuditLog::QuestionnaireAnswerApproved)
            ->assertDontSee('future.internal_action')
            ->assertDontSee('other_organization.private_action');
    }

    public function test_audit_log_pages_use_a_stable_order_when_creation_times_match(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => 1]);
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $createdAt = now()->startOfSecond();
        $firstLog = AuditLog::query()->create(['organization_id' => $organization->id, 'actor_user_id' => $user->id, 'entity_type' => 'Employee', 'action' => AuditLog::EmployeeCreated, 'created_at' => $createdAt]);
        $secondLog = AuditLog::query()->create(['organization_id' => $organization->id, 'actor_user_id' => $user->id, 'entity_type' => 'Employee', 'action' => AuditLog::EmployeeUpdated, 'created_at' => $createdAt]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('audit-logs.index', ['page' => 1]))
            ->assertOk()
            ->assertSee('社員情報を変更')
            ->assertDontSee('社員を登録');

        $this->actingAs($user)->withSession($session)
            ->get(route('audit-logs.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('社員を登録')
            ->assertDontSee('社員情報を変更');
    }

    public function test_viewer_cannot_view_audit_logs(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('audit-logs.index'))->assertForbidden();
    }

    public function test_admin_role_in_another_organization_does_not_grant_audit_log_access(): void
    {
        [$user] = $this->member(MembershipRole::Admin);
        [, $selectedOrganization] = $this->member(MembershipRole::Viewer);
        OrganizationMembership::query()->create(['organization_id' => $selectedOrganization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        AuditLog::query()->create(['organization_id' => $selectedOrganization->id, 'actor_user_id' => $user->id, 'entity_type' => 'QuestionnaireAnswer', 'action' => AuditLog::QuestionnaireAnswerApproved]);

        $this->actingAs($user)->withSession(['current_organization_id' => $selectedOrganization->id])->get(route('audit-logs.index'))->assertForbidden();
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
