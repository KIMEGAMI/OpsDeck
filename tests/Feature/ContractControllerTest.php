<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractControllerTest extends TestCase
{
    public function test_operator_can_create_a_contract_in_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('contracts.store'), $this->attributes())
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'organization_id' => $organization->id,
            'contract_name' => 'Core Services',
            'status' => config('opsdeck.contract_status.draft'),
        ]);
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

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('contracts.store'), $this->attributes(['amount' => '-0.01']))
            ->assertSessionHasErrors('amount');

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
