<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalListScaleTest extends TestCase
{
    public function test_operational_lists_render_at_the_documented_organization_scale_with_bounded_queries(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $timestamp = now()->toDateTimeString();
        $departmentId = DB::table('departments')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Scale Test Department',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $employeeCount = 200;
        $assetCount = 500;
        $saasCount = 100;
        $contractCount = 100;

        $this->insertRecords('employees', array_map(fn (int $index): array => [
            'organization_id' => $organization->id,
            'employee_number' => 'SCALE-EMP-'.$index,
            'name' => 'Scale Employee '.$index,
            'email' => 'scale-employee-'.$index.'@example.test',
            'department_id' => $departmentId,
            'employment_status' => config('opsdeck.employee_status.active'),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], range(1, $employeeCount)));

        $this->insertRecords('assets', array_map(fn (int $index): array => [
            'organization_id' => $organization->id,
            'asset_number' => 'SCALE-ASSET-'.$index,
            'asset_type' => config('opsdeck.asset_types')[0],
            'status' => config('opsdeck.asset_statuses')[0],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], range(1, $assetCount)));

        $this->insertRecords('saas_services', array_map(fn (int $index): array => [
            'organization_id' => $organization->id,
            'name' => 'Scale SaaS '.$index,
            'status' => config('opsdeck.saas_service_statuses')[0],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], range(1, $saasCount)));

        $this->insertRecords('contracts', array_map(fn (int $index): array => [
            'organization_id' => $organization->id,
            'vendor_name' => 'Scale Vendor',
            'contract_name' => 'Scale Contract '.$index,
            'contract_type' => 'other',
            'billing_cycle' => config('opsdeck.contract_billing_cycles')[0],
            'amount' => '0.00',
            'currency' => config('opsdeck.cost_savings.default_currency'),
            'auto_renew' => false,
            'status' => config('opsdeck.contract_status.draft'),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], range(1, $contractCount)));

        $this->assertListRendersWithBoundedQueries($user, $organization, 'employees.index', 'employees', $employeeCount);
        $this->assertListRendersWithBoundedQueries($user, $organization, 'assets.index', 'assets', $assetCount);
        $this->assertListRendersWithBoundedQueries($user, $organization, 'saas-services.index', 'services', $saasCount);
        $this->assertListRendersWithBoundedQueries($user, $organization, 'contracts.index', 'contracts', $contractCount);
    }

    /** @param array<int, array<string, mixed>> $records */
    private function insertRecords(string $table, array $records): void
    {
        foreach (array_chunk($records, 25) as $recordChunk) {
            DB::table($table)->insert($recordChunk);
        }
    }

    private function assertListRendersWithBoundedQueries(User $user, Organization $organization, string $routeName, string $viewVariable, int $expectedTotal): void
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route($routeName));

        $queryCount = count($connection->getQueryLog());
        $connection->disableQueryLog();

        $response->assertOk()->assertViewHas($viewVariable, function ($paginator) use ($expectedTotal): bool {
            return $paginator->total() === $expectedTotal
                && $paginator->count() === (int) config('opsdeck.pagination.per_page');
        });
        $this->assertLessThan(15, $queryCount, $routeName.' should not issue per-row relationship queries');
    }

    /** @return array{0: User, 1: Organization} */
    private function member(): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'scale-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Scale '.$suffix,
            'slug' => 'scale-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }
}
