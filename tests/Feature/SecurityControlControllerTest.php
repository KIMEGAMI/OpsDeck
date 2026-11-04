<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SecurityControl;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityControlControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_control_screens_show_japanese_status_labels(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $attributes = $this->attributes();
        $attributes['status'] = 'not_implemented';
        $control = SecurityControl::query()->create($attributes + ['organization_id' => $organization->id]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('security-controls.index'))
            ->assertOk()
            ->assertSee('未対応');

        $this->get(route('security-controls.show', $control))
            ->assertOk()
            ->assertSee('未対応')
            ->assertSee('最終確認日')
            ->assertSee('未確認')
            ->assertSee('今日、確認しました');

        $this->get(route('security-controls.create'))
            ->assertOk()
            ->assertSee('対応済み')
            ->assertSee('一部対応')
            ->assertSee('対象外');
    }

    public function test_operator_can_create_a_security_control(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('security-controls.store'), $this->attributes())->assertRedirect();

        $this->assertDatabaseHas('security_controls', ['organization_id' => $organization->id, 'control_code' => 'MFA', 'status' => config('opsdeck.security_control_status.unknown')]);
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_security_control_owner_and_review_schedule_can_be_saved_and_viewed(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '確認担当者',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $attributes = $this->attributes() + [
            'owner_employee_id' => $employee->id,
            'review_frequency' => '毎月',
            'last_reviewed_at' => '2026-10-01',
            'next_review_at' => '2026-11-01',
        ];

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('security-controls.store'), $attributes)
            ->assertRedirect();

        $control = SecurityControl::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame($employee->id, $control->owner_employee_id);
        $this->assertSame('毎月', $control->review_frequency);
        $this->assertSame('2026-10-01', $control->last_reviewed_at?->toDateString());
        $this->assertSame('2026-11-01', $control->next_review_at?->toDateString());

        $this->get(route('security-controls.show', $control))
            ->assertOk()
            ->assertSee('確認担当者')
            ->assertSee('毎月')
            ->assertSee('2026-10-01')
            ->assertSee('2026-11-01');

        $this->get(route('security-controls.edit', $control))
            ->assertOk()
            ->assertSee('name="owner_employee_id"', false)
            ->assertSee('name="review_frequency"', false)
            ->assertSee('value="毎月"', false);
    }

    public function test_invalid_next_review_date_is_shown_next_to_field_and_preserved(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->from(route('security-controls.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('security-controls.store'), $this->attributes() + ['next_review_at' => 'not-a-date'])
            ->assertOk()
            ->assertSee('id="next_review_at-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="next_review_at-error"', false)
            ->assertSee('value="not-a-date"', false);

        $this->assertDatabaseMissing('security_controls', [
            'organization_id' => $organization->id,
            'control_code' => 'MFA',
        ]);
    }

    public function test_invalid_security_status_falls_back_to_unknown_and_malformed_owner_is_safe(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->from(route('security-controls.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('security-controls.store'), array_merge($this->attributes(), [
                'status' => 'not-a-configured-status',
                'owner_employee_id' => ['malformed'],
            ]))
            ->assertOk()
            ->assertSee('id="status-error" class="errors field-error"', false)
            ->assertSee('id="owner_employee_id-error" class="errors field-error"', false)
            ->assertSee('<option value="unknown" selected>未確認</option>', false)
            ->assertSee('<option value="" selected>担当者を選ばない</option>', false);

        $this->assertDatabaseMissing('security_controls', [
            'organization_id' => $organization->id,
            'control_code' => 'MFA',
        ]);
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
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
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

    public function test_operator_can_mark_a_security_control_as_reviewed_and_the_action_is_audited_once(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $control = SecurityControl::query()->create($this->attributes() + ['organization_id' => $organization->id]);
        $session = ['current_organization_id' => $organization->id];
        $reviewDate = CarbonImmutable::today($organization->timezone)->toDateString();

        $this->actingAs($user)->withSession($session)
            ->post(route('security-controls.review', $control))
            ->assertRedirect(route('security-controls.show', $control))
            ->assertSessionHas('status', '確認日を記録しました。');

        $this->assertSame($reviewDate, $control->refresh()->last_reviewed_at?->toDateString());
        $this->get(route('security-controls.show', $control))->assertOk()->assertSee($reviewDate);

        $this->post(route('security-controls.review', $control))->assertRedirect(route('security-controls.show', $control));

        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $control->id)->where('action', AuditLog::SecurityControlUpdated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['changed_fields' => ['last_reviewed_at']], $auditLog->after_json);
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_viewer_cannot_mark_a_security_control_as_reviewed(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $control = SecurityControl::query()->create($this->attributes() + ['organization_id' => $organization->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('security-controls.review', $control))
            ->assertForbidden();

        $this->assertNull($control->refresh()->last_reviewed_at);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::SecurityControlUpdated]);
    }

    public function test_operator_cannot_mark_another_organizations_security_control_as_reviewed(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());
        $control = SecurityControl::query()->create($this->attributes() + ['organization_id' => $otherOrganization->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('security-controls.review', $control))
            ->assertNotFound();

        $this->assertNull($control->refresh()->last_reviewed_at);
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
