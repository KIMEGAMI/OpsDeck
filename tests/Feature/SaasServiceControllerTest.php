<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasServiceControllerTest extends TestCase
{
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
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
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
