<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasServiceControllerTest extends TestCase
{
    public function test_saas_service_screens_show_japanese_status_labels(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'saas-labels-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'SaaS Labels '.$suffix,
            'slug' => 'saas-labels-'.$suffix,
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $service = SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => '社内チャット',
            'status' => 'inactive',
        ]);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '入社する社員',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $retiredEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '退職済み社員',
            'employment_status' => config('opsdeck.employee_status.retired'),
        ]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('saas-services.index'))
            ->assertOk()
            ->assertSee('クラウドサービス')
            ->assertSee('会社で使っているWebサービスやアプリの契約・利用状況をまとめて管理します。')
            ->assertSee('利用停止');

        $this->get(route('saas-services.show', $service))
            ->assertOk()
            ->assertSee('ログイン時の追加確認')
            ->assertSee('利用停止');

        $this->get(route('saas-services.show', ['saas_service' => $service, 'employee_id' => $employee->id]))
            ->assertOk()
            ->assertSee('<option value="'.$employee->id.'" selected>'.$employee->name.'</option>', false);

        $this->get(route('saas-services.show', $service))
            ->assertOk()
            ->assertDontSee('<option value="'.$retiredEmployee->id.'">'.$retiredEmployee->name.'</option>', false);

        $this->get(route('saas-services.create'))
            ->assertOk()
            ->assertSee('クラウドサービスを登録')
            ->assertSee('提供会社（ベンダー）')
            ->assertSee('利用中')
            ->assertSee('利用終了');
    }

    public function test_operator_cannot_create_saas_service_beyond_plan_limit(): void
    {
        Config::set('opsdeck.plans.free.limits.saas_services', 1);
        $user = User::factory()->create(['email' => 'saas-limit-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'SaaS Limit', 'slug' => 'saas-limit-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => 'free', 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Operator, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Existing SaaS', 'status' => 'active']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-services.store'), ['name' => 'New SaaS', 'status' => 'active'])
            ->assertSessionHasErrors('plan_limit');

        $this->assertSame(1, SaasService::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_operator_creates_service_in_current_organization(): void
    {
        $organizationSlug = fake()->uuid();
        $user = User::factory()->create(['email' => 'saas-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'SaaS Org', 'slug' => $organizationSlug, 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Operator, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('saas-services.store'), ['name' => 'Slack', 'status' => 'active'])->assertRedirect();

        $this->assertDatabaseHas('saas_services', ['organization_id' => $organization->id, 'name' => 'Slack']);
        $service = SaasService::query()->where('organization_id', $organization->id)->where('name', 'Slack')->sole();
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $service->id)->where('action', AuditLog::SaasServiceCreated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['created_fields' => ['name', 'status']], $auditLog->after_json);
        $this->assertStringNotContainsString('Slack', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_invalid_saas_website_is_shown_and_invalid_status_falls_back_to_default(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $user = User::factory()->create(['email' => 'saas-invalid-url-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'SaaS Validation Org',
            'slug' => 'saas-validation-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->from(route('saas-services.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-services.store'), [
                'name' => '社内サービス',
                'website_url' => 'not-a-url',
                'status' => 'not-a-configured-status',
            ])
            ->assertOk()
            ->assertSee('id="website_url-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="website_url-error"', false)
            ->assertSee('value="not-a-url"', false)
            ->assertSee('id="status-error" class="errors field-error"', false)
            ->assertSee('<option value="active" selected>利用中</option>', false);

        $this->assertDatabaseMissing('saas_services', ['organization_id' => $organization->id, 'name' => '社内サービス']);
    }

    public function test_saas_service_list_attention_filter_shows_unassigned_and_former_employee_services_only(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'saas-attention-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'SaaS Attention '.$suffix,
            'slug' => 'saas-attention-'.$suffix,
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $otherOrganization = Organization::query()->create([
            'name' => 'Other SaaS Attention '.$suffix,
            'slug' => 'other-saas-attention-'.$suffix,
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $unassignedService = SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Unassigned SaaS '.$suffix,
            'status' => config('opsdeck.saas_service_active_status'),
        ]);
        $formerEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Former SaaS employee',
            'employment_status' => 'retired',
        ]);
        $formerEmployeeService = SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Former employee SaaS '.$suffix,
            'status' => config('opsdeck.saas_service_active_status'),
        ]);
        SaasAssignment::query()->create([
            'organization_id' => $organization->id,
            'saas_service_id' => $formerEmployeeService->id,
            'employee_id' => $formerEmployee->id,
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
        ]);
        $activeEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Active SaaS employee',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $assignedService = SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Assigned SaaS '.$suffix,
            'status' => config('opsdeck.saas_service_active_status'),
        ]);
        SaasAssignment::query()->create([
            'organization_id' => $organization->id,
            'saas_service_id' => $assignedService->id,
            'employee_id' => $activeEmployee->id,
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
        ]);
        $inactiveService = SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Inactive SaaS '.$suffix,
            'status' => 'inactive',
        ]);
        $otherOrganizationService = SaasService::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other organization SaaS '.$suffix,
            'status' => config('opsdeck.saas_service_active_status'),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('saas-services.index', ['needs_attention' => 1]))
            ->assertOk()
            ->assertSee($unassignedService->name)
            ->assertSee($formerEmployeeService->name)
            ->assertDontSee($assignedService->name)
            ->assertDontSee($inactiveService->name)
            ->assertDontSee($otherOrganizationService->name);
    }

    public function test_saas_service_list_rejects_invalid_attention_filter_values(): void
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'saas-invalid-filter-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'SaaS Filter '.$suffix,
            'slug' => 'saas-filter-'.$suffix,
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->getJson(route('saas-services.index', ['needs_attention' => 'unexpected']))
            ->assertUnprocessable();
    }

    public function test_viewer_cannot_create_a_saas_service(): void
    {
        $user = User::factory()->create(['email' => 'saas-viewer-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'SaaS Viewer Org',
            'slug' => 'saas-viewer-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('saas-services.store'), ['name' => 'Restricted SaaS', 'status' => 'active'])
            ->assertForbidden();

        $this->assertDatabaseMissing('saas_services', ['organization_id' => $organization->id, 'name' => 'Restricted SaaS']);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_viewer_cannot_update_a_saas_service(): void
    {
        $user = User::factory()->create(['email' => 'saas-viewer-update-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'SaaS Viewer Update Org',
            'slug' => 'saas-viewer-update-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $service = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Read Only SaaS', 'status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-services.update', $service), ['name' => 'Changed SaaS', 'status' => 'inactive'])
            ->assertForbidden();

        $this->assertSame('Read Only SaaS', $service->refresh()->name);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::SaasServiceUpdated]);
    }

    public function test_saas_service_update_records_changed_field_names_without_service_values(): void
    {
        $user = User::factory()->create(['email' => 'saas-update-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'SaaS Update Org',
            'slug' => 'saas-update-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $service = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Existing Service', 'status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-services.update', $service), ['name' => 'Changed Service', 'status' => 'active'])
            ->assertRedirect();

        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $service->id)->where('action', AuditLog::SaasServiceUpdated)->sole();

        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame($organization->id, $auditLog->organization_id);
        $this->assertSame($service->id, $auditLog->entity_id);
        $this->assertSame(['changed_fields' => ['name']], $auditLog->after_json);
        $this->assertStringNotContainsString('Changed Service', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_operator_cannot_update_a_saas_service_in_another_organization(): void
    {
        $user = User::factory()->create(['email' => 'saas-cross-org-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'SaaS Current Org',
            'slug' => 'saas-current-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $otherOrganization = Organization::query()->create([
            'name' => 'SaaS Other Org',
            'slug' => 'saas-other-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $service = SaasService::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Private SaaS', 'status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('saas-services.update', $service), ['name' => 'Changed SaaS', 'status' => 'inactive'])
            ->assertNotFound();

        $this->assertSame('Private SaaS', $service->refresh()->name);
    }

    public function test_returns_404_for_service_in_another_organization(): void
    {
        $organizationSlug = fake()->uuid();
        $otherOrganizationSlug = fake()->uuid();
        $user = User::factory()->create(['email' => 'saas-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Viewer Org', 'slug' => $organizationSlug, 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $otherOrganization = Organization::query()->create(['name' => 'Other SaaS Org', 'slug' => $otherOrganizationSlug, 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $service = SaasService::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Private SaaS', 'status' => 'active']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('saas-services.show', $service))->assertNotFound();
    }
}
