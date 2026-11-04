<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\PlanFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_plan_prices_are_zero_and_the_free_entitlements_match_legacy_premium(): void
    {
        $freePlan = config('opsdeck.plans.free');
        $legacyPlan = config('opsdeck.plans.premium');

        foreach (config('opsdeck.plans') as $plan) {
            $this->assertSame(0, $plan['monthly_price_jpy']);
        }

        $this->assertSame(0, config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy'));
        $this->assertSame($legacyPlan['limits'], $freePlan['limits']);
        $this->assertSame($legacyPlan['capabilities'], $freePlan['capabilities']);
        $this->assertFalse((bool) config('opsdeck.billing.enabled'));
        $this->assertSame(3, config('opsdeck.ai.daily_request_limit'));
        $this->assertSame(['fast' => 'gpt-6-luna', 'standard' => 'gpt-6-luna', 'deep' => 'gpt-6-luna'], config('opsdeck.ai.models'));
    }

    public function test_free_organization_can_open_currently_available_feature_pages(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $session = ['current_organization_id' => $organization->id];

        foreach ([
            route('exports.index'),
            route('exports.download', 'employees'),
            route('security-controls.index'),
            route('evidence.index'),
            route('questionnaires.index'),
            route('impact-report.show'),
        ] as $url) {
            $this->actingAs($user)->withSession($session)->get($url)->assertOk();
        }
    }

    public function test_import_page_offers_xlsx_without_paid_upgrade_copy(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create'))
            ->assertOk()
            ->assertSee('CSVファイル / Excelファイル（XLSX）')
            ->assertSee('使える形式：CSV / TXT / Excel（.xlsx）')
            ->assertDontSee('Premium');
    }

    public function test_free_plan_features_are_available_without_a_paid_plan_code(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [, $organization] = $this->member();

        foreach (['xlsx_preview', 'import_execution', 'ai_column_mapping', 'renewal_alerts', 'savings_opportunities', 'realized_savings', 'security_controls', 'evidence', 'questionnaires', 'exports', 'impact_report'] as $feature) {
            $this->assertTrue(app(PlanFeatureService::class)->isAvailable($organization, $feature), $feature);
        }
    }

    /** @return array{0: User, 1: Organization} */
    private function member(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'free-feature-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Free Features '.$suffix,
            'slug' => 'free-features-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.free_plan_code'),
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
