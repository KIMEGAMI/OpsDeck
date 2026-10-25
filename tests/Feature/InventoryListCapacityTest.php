<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryListCapacityTest extends TestCase
{
    private const RECORD_COUNT = 100;

    private const PAGE_SIZE = 50;

    public function test_100_saas_services_and_contracts_are_paginated_without_cross_organization_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => self::PAGE_SIZE]);

        [$user, $organization] = $this->ownerOrganization();
        [, $otherOrganization] = $this->ownerOrganization();
        $services = [];
        $contracts = [];

        foreach (range(1, self::RECORD_COUNT) as $number) {
            $services[] = SaasService::query()->create([
                'organization_id' => $organization->id,
                'name' => sprintf('Service-%03d', $number),
                'status' => config('opsdeck.saas_service_active_status'),
            ]);
            $contracts[] = Contract::query()->create([
                'organization_id' => $organization->id,
                'vendor_name' => 'Vendor',
                'contract_name' => sprintf('Contract-%03d', $number),
                'contract_type' => 'subscription',
                'billing_cycle' => 'monthly',
                'amount' => '9800.00',
                'currency' => 'JPY',
                'auto_renew' => true,
                'status' => config('opsdeck.contract_status.draft'),
            ]);
        }

        $otherService = SaasService::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other-Organization-Service',
            'status' => config('opsdeck.saas_service_active_status'),
        ]);
        $otherContract = Contract::query()->create([
            'organization_id' => $otherOrganization->id,
            'vendor_name' => 'Other Vendor',
            'contract_name' => 'Other-Organization-Contract',
            'contract_type' => 'subscription',
            'billing_cycle' => 'monthly',
            'amount' => '9800.00',
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => config('opsdeck.contract_status.draft'),
        ]);
        $session = ['current_organization_id' => $organization->id];

        $firstSaasPage = $this->actingAs($user)->withSession($session)->get(route('saas-services.index', ['page' => 1]));
        $secondSaasPage = $this->actingAs($user)->withSession($session)->get(route('saas-services.index', ['page' => 2]));
        $firstContractPage = $this->actingAs($user)->withSession($session)->get(route('contracts.index', ['page' => 1]));
        $secondContractPage = $this->actingAs($user)->withSession($session)->get(route('contracts.index', ['page' => 2]));

        $firstSaasPage->assertOk()
            ->assertSee($services[0]->name)
            ->assertSee($services[self::PAGE_SIZE - 1]->name)
            ->assertDontSee($services[self::PAGE_SIZE]->name)
            ->assertDontSee($otherService->name);
        $secondSaasPage->assertOk()
            ->assertSee($services[self::PAGE_SIZE]->name)
            ->assertSee($services[self::RECORD_COUNT - 1]->name)
            ->assertDontSee($services[self::PAGE_SIZE - 1]->name)
            ->assertDontSee($otherService->name);
        $firstContractPage->assertOk()
            ->assertSee($contracts[0]->contract_name)
            ->assertSee($contracts[self::PAGE_SIZE - 1]->contract_name)
            ->assertDontSee($contracts[self::PAGE_SIZE]->contract_name)
            ->assertDontSee($otherContract->contract_name);
        $secondContractPage->assertOk()
            ->assertSee($contracts[self::PAGE_SIZE]->contract_name)
            ->assertSee($contracts[self::RECORD_COUNT - 1]->contract_name)
            ->assertDontSee($contracts[self::PAGE_SIZE - 1]->contract_name)
            ->assertDontSee($otherContract->contract_name);
    }

    /** @return array{0: User, 1: Organization} */
    private function ownerOrganization(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'inventory-capacity-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Inventory Capacity '.$suffix,
            'slug' => 'inventory-capacity-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }
}
