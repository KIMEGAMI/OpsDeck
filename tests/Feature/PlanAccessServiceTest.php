<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\PlanAccessService;
use App\Services\PlanFeatureService;
use App\Services\PlanLimitService;
use App\Services\PlanUsageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlanAccessServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.billing.past_due_grace_days' => 7]);
    }

    public function test_expired_premium_access_is_free_even_before_the_scheduled_downgrade(): void
    {
        $organization = $this->organization(['billing_past_due_since' => now()->subDays(7)]);
        $planAccess = app(PlanAccessService::class);

        $this->assertSame(config('opsdeck.free_plan_code'), $planAccess->effectivePlanCode($organization));
        $this->assertSame(config('opsdeck.premium_plan_code'), $organization->plan_code);
        $this->assertTrue(app(PlanFeatureService::class)->isAvailable($organization, 'import_execution'));
        $this->assertFalse(app(PlanFeatureService::class)->isAvailable($organization, 'xlsx_preview'));
        $this->assertSame(20, collect(app(PlanUsageService::class)->forOrganization($organization))->firstWhere('key', 'employees')['limit']);
    }

    public function test_free_resource_limits_apply_after_grace_without_waiting_for_the_command(): void
    {
        config(['opsdeck.plans.free.limits.employees' => 0]);
        $organization = $this->organization(['billing_past_due_since' => now()->subDays(8)]);

        $this->expectException(ValidationException::class);
        app(PlanLimitService::class)->assertCanAdd($organization, 'employees');
    }

    public function test_last_webhook_timestamp_is_used_when_the_new_grace_timestamp_is_missing(): void
    {
        $organization = $this->organization([
            'billing_past_due_since' => null,
            'stripe_last_event_created_at' => now()->subDays(8)->timestamp,
        ]);

        $this->assertSame(config('opsdeck.free_plan_code'), app(PlanAccessService::class)->effectivePlanCode($organization));
    }

    public function test_active_plan_is_not_downgraded_and_unknown_start_time_receives_grace(): void
    {
        $activeOrganization = $this->organization(['billing_status' => 'active', 'billing_past_due_since' => now()->subDays(8)]);
        $unknownStartOrganization = $this->organization(['billing_past_due_since' => null, 'stripe_last_event_created_at' => null]);
        $planAccess = app(PlanAccessService::class);

        $this->assertSame(config('opsdeck.premium_plan_code'), $planAccess->effectivePlanCode($activeOrganization));
        $this->assertSame(config('opsdeck.premium_plan_code'), $planAccess->effectivePlanCode($unknownStartOrganization));
    }

    public function test_cancelled_premium_plan_does_not_grant_paid_features(): void
    {
        $organization = $this->organization(['billing_status' => 'cancelled']);

        $this->assertSame(config('opsdeck.free_plan_code'), app(PlanAccessService::class)->effectivePlanCode($organization));
        $this->assertTrue(app(PlanFeatureService::class)->isAvailable($organization, 'import_execution'));
        $this->assertFalse(app(PlanFeatureService::class)->isAvailable($organization, 'exports'));
    }

    /** @param array<string, mixed> $overrides */
    private function organization(array $overrides = []): Organization
    {
        $suffix = Str::lower(Str::random(12));

        return Organization::query()->create(array_merge([
            'name' => 'Plan access '.$suffix,
            'slug' => 'plan-access-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'past_due',
            'billing_past_due_since' => now()->subDays(8),
        ], $overrides));
    }
}
