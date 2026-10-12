<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SecurityControl;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityControlControllerTest extends TestCase
{
    public function test_operator_can_create_a_security_control(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('security-controls.store'), $this->attributes())->assertRedirect();

        $this->assertDatabaseHas('security_controls', ['organization_id' => $organization->id, 'control_code' => 'MFA', 'status' => config('opsdeck.security_control_status.unknown')]);
    }

    public function test_viewer_cannot_create_a_security_control(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('security-controls.store'), $this->attributes())
            ->assertForbidden();

        $this->assertDatabaseMissing('security_controls', ['organization_id' => $organization->id, 'control_code' => 'MFA']);
    }

    public function test_operator_can_update_a_security_control(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $control = SecurityControl::query()->create($this->attributes() + ['organization_id' => $organization->id]);
        $updatedAttributes = $this->attributes();
        $updatedAttributes['title'] = 'MFA再確認';

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('security-controls.update', $control), $updatedAttributes)
            ->assertRedirect(route('security-controls.show', $control));

        $this->assertSame('MFA再確認', $control->refresh()->title);
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $control->id)->where('action', AuditLog::SecurityControlUpdated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame($organization->id, $auditLog->organization_id);
        $this->assertSame($control->id, $auditLog->entity_id);
        $this->assertSame(['changed_fields' => ['title']], $auditLog->after_json);
        $this->assertStringNotContainsString('MFA再確認', json_encode($auditLog->toArray()));
    }

    public function test_viewer_cannot_update_a_security_control(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $control = SecurityControl::query()->create($this->attributes() + ['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('security-controls.update', $control), $this->attributes())
            ->assertForbidden();

        $this->assertSame('MFA管理', $control->refresh()->title);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::SecurityControlUpdated]);
    }

    public function test_operator_cannot_update_a_security_control_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());
        $control = SecurityControl::query()->create($this->attributes() + ['organization_id' => $otherOrganization->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('security-controls.update', $control), $this->attributes())
            ->assertNotFound();

        $this->assertSame('MFA管理', $control->refresh()->title);
    }

    public function test_security_control_returns_404_for_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());
        $control = SecurityControl::query()->create($this->attributes() + ['organization_id' => $otherOrganization->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('security-controls.show', $control))->assertNotFound();
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create($this->organizationAttributes());
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }

    /** @return array{name: string, slug: string, timezone: string, plan_code: string, billing_status: string} */
    private function organizationAttributes(): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));

        return ['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')];
    }

    private function attributes(): array
    {
        return ['control_code' => 'MFA', 'title' => 'MFA管理', 'category' => 'アクセス制御', 'description' => '多要素認証の確認', 'status' => config('opsdeck.security_control_status.unknown')];
    }
}
