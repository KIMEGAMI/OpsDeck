<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractControllerTest extends TestCase
{
    public function test_contract_screens_show_japanese_status_and_billing_cycle_labels(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $contract = Contract::query()->create($this->attributes([
            'status' => 'pending_cancel',
            'billing_cycle' => 'annual',
        ]) + ['organization_id' => $organization->id]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('解約手続中');

        $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('解約手続中');

        $this->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('毎月')
            ->assertSee('毎年')
            ->assertSee('一回のみ')
            ->assertSee('次回更新日')
            ->assertSee('解約申込期限')
            ->assertSee('利用ライセンス数')
            ->assertSee('社内の担当者')
            ->assertSee('期限切れ');
    }

    public function test_operator_can_create_a_contract_in_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $employee = Employee::query()->create(['organization_id' => $organization->id, 'name' => '契約担当者', 'employment_status' => 'active']);
        $service = SaasService::query()->create(['organization_id' => $organization->id, 'name' => '契約サービス', 'status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('contracts.store'), $this->attributes([
                'saas_service_id' => $service->id,
                'owner_employee_id' => $employee->id,
                'license_quantity' => 25,
                'renewal_date' => '2027-03-01',
                'cancellation_deadline' => '2027-01-31',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'organization_id' => $organization->id,
            'contract_name' => 'Core Services',
            'status' => config('opsdeck.contract_status.draft'),
            'saas_service_id' => $service->id,
            'owner_employee_id' => $employee->id,
            'license_quantity' => 25,
        ]);
        $contract = Contract::query()->where('organization_id', $organization->id)->where('contract_name', 'Core Services')->sole();
        $this->assertSame('2027-03-01', $contract->renewal_date?->toDateString());
        $this->assertSame('2027-01-31', $contract->cancellation_deadline?->toDateString());
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $contract->id)->where('action', AuditLog::ContractCreated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['created_fields' => ['saas_service_id', 'vendor_name', 'contract_name', 'contract_type', 'billing_cycle', 'amount', 'currency', 'auto_renew', 'cancellation_deadline', 'renewal_date', 'license_quantity', 'owner_employee_id', 'status']], $auditLog->after_json);
        $this->assertStringNotContainsString('Core Services', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('次回更新日')
            ->assertSee('2027-03-01')
            ->assertSee('解約申込期限')
            ->assertSee('2027-01-31')
            ->assertSee('利用ライセンス数')
            ->assertSee('25')
            ->assertSee('契約サービス')
            ->assertSee('契約担当者');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('contracts.edit', $contract))
            ->assertOk()
            ->assertSee('value="'.$service->id.'" selected', false)
            ->assertSee('value="'.$employee->id.'" selected', false)
            ->assertSee('value="2027-03-01"', false)
            ->assertSee('value="2027-01-31"', false)
            ->assertSee('value="25"', false);
    }

    public function test_viewer_cannot_create_a_contract(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('contracts.store'), $this->attributes())
            ->assertForbidden();

        $this->assertDatabaseMissing('contracts', [
            'organization_id' => $organization->id,
            'contract_name' => 'Core Services',
        ]);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_viewer_cannot_update_a_contract(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $contract = Contract::query()->create($this->attributes() + ['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('contracts.update', $contract), $this->attributes(['contract_name' => 'Changed Contract']))
            ->assertForbidden();

        $this->assertSame('Core Services', $contract->refresh()->contract_name);
    }

    public function test_contract_update_records_changed_field_names_without_contract_values(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $contract = Contract::query()->create($this->attributes() + ['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('contracts.update', $contract), $this->attributes(['contract_name' => 'Sensitive renewal title']))
            ->assertRedirect();

        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $contract->id)->where('action', AuditLog::ContractUpdated)->sole();

        $this->assertSame($organization->id, $auditLog->organization_id);
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame($contract->id, $auditLog->entity_id);
        $this->assertSame(['changed_fields' => ['contract_name']], $auditLog->after_json);
        $this->assertStringNotContainsString('Sensitive renewal title', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_operator_cannot_update_a_contract_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $contract = Contract::query()->create($this->attributes() + ['organization_id' => $otherOrganization->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('contracts.update', $contract), $this->attributes(['contract_name' => 'Changed Contract']))
            ->assertNotFound();

        $this->assertSame('Core Services', $contract->refresh()->contract_name);
    }

    public function test_contract_rejects_an_end_date_before_the_start_date(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('contracts.store'), $this->attributes(['started_at' => '2026-09-21', 'ends_at' => '2026-09-20']))
            ->assertSessionHasErrors(['ends_at' => '終了日は開始日以降の日付を指定してください。']);
    }

    public function test_contract_amount_cannot_be_negative_when_created(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->from(route('contracts.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('contracts.store'), $this->attributes(['amount' => '-0.01']))
            ->assertOk()
            ->assertSee('id="amount-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="amount-error"', false)
            ->assertSee('value="-0.01"', false);

        $this->assertDatabaseMissing('contracts', [
            'organization_id' => $organization->id,
            'contract_name' => 'Core Services',
        ]);
    }

    public function test_contract_amount_cannot_be_negative_when_updated(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $contract = Contract::query()->create($this->attributes() + ['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('contracts.update', $contract), $this->attributes(['amount' => '-0.01']))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'amount' => '9800.00',
        ]);
    }

    public function test_invalid_contract_select_values_fall_back_to_saved_values(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $contract = Contract::query()->create($this->attributes() + ['organization_id' => $organization->id]);

        $this->from(route('contracts.edit', $contract))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('contracts.update', $contract), array_merge($this->attributes(), [
                'billing_cycle' => 'not-a-configured-cycle',
                'status' => 'not-a-configured-status',
                'auto_renew' => 'not-a-boolean-value',
            ]))
            ->assertOk()
            ->assertSee('id="billing_cycle-error" class="errors field-error"', false)
            ->assertSee('id="status-error" class="errors field-error"', false)
            ->assertSee('id="auto_renew-error" class="errors field-error"', false)
            ->assertSee('<option value="monthly" selected>毎月</option>', false)
            ->assertSee('<option value="draft" selected>下書き</option>', false)
            ->assertSee('<option value="1" selected>あり</option>', false);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'billing_cycle' => 'monthly',
            'status' => config('opsdeck.contract_status.draft'),
            'auto_renew' => true,
        ]);
    }

    public function test_contract_amount_can_be_zero(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('contracts.store'), $this->attributes(['amount' => '0.00']))
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'organization_id' => $organization->id,
            'contract_name' => 'Core Services',
            'amount' => '0.00',
        ]);
    }

    public function test_contract_pages_use_a_stable_order_when_end_dates_match(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => 1]);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $endDate = now()->addMonth()->toDateString();
        $firstContract = Contract::query()->create($this->attributes(['ends_at' => $endDate]) + ['organization_id' => $organization->id]);
        $secondContract = Contract::query()->create($this->attributes(['ends_at' => $endDate]) + ['organization_id' => $organization->id]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('contracts.index', ['page' => 1]))
            ->assertOk()
            ->assertSee('href="'.route('contracts.show', $firstContract).'"', false)
            ->assertDontSee('href="'.route('contracts.show', $secondContract).'"', false);

        $this->actingAs($user)->withSession($session)
            ->get(route('contracts.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('href="'.route('contracts.show', $secondContract).'"', false)
            ->assertDontSee('href="'.route('contracts.show', $firstContract).'"', false);
    }

    public function test_contract_returns_404_for_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());
        $contract = Contract::query()->create($this->attributes() + ['organization_id' => $otherOrganization->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('contracts.show', $contract))
            ->assertNotFound();
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $user = User::factory()->create(['email' => 'contract-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create($this->organizationAttributes());
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }

    /** @return array{name: string, slug: string, timezone: string, plan_code: string, billing_status: string} */
    private function organizationAttributes(): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));

        return ['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')];
    }

    private function attributes(array $overrides = []): array
    {
        return $overrides + [
            'vendor_name' => 'OpsDeck Vendor',
            'contract_name' => 'Core Services',
            'contract_type' => 'subscription',
            'billing_cycle' => 'monthly',
            'amount' => '9800.00',
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => config('opsdeck.contract_status.draft'),
        ];
    }
}
