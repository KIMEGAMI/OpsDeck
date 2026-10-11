<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnforceBillingGracePeriodTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_billing_grace_enforcement_is_scheduled_hourly(): void
    {
        $billingGraceEvent = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'opsdeck:billing:enforce-grace'));
        $impactSnapshotPruningEvent = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'opsdeck:impact:prune-snapshots'));

        $this->assertNotNull($billingGraceEvent);
        $this->assertSame('0 * * * *', $billingGraceEvent->expression);
        $this->assertNotNull($impactSnapshotPruningEvent);
        $this->assertSame('0 0 * * *', $impactSnapshotPruningEvent->expression);
        $this->assertTrue($impactSnapshotPruningEvent->withoutOverlapping);
    }

    public function test_past_due_premium_organization_is_downgraded_after_grace_period(): void
    {
        config(['opsdeck.billing.past_due_grace_days' => 7]);
        $organization = $this->organization(['billing_past_due_since' => now()->subDays(8)]);
        $graceDays = (int) config('opsdeck.billing.past_due_grace_days');
        $eligibleOrganizationCount = Organization::query()
            ->where('plan_code', config('opsdeck.premium_plan_code'))
            ->where('billing_status', 'past_due')
            ->where('billing_past_due_since', '<=', now()->subDays($graceDays))
            ->count();

        $this->artisan('opsdeck:billing:enforce-grace')
            ->expectsOutput('Downgraded organizations after billing grace period: '.$eligibleOrganizationCount)
            ->assertExitCode(0);

        $this->assertSame(config('opsdeck.free_plan_code'), $organization->refresh()->plan_code);
        $this->assertSame('past_due', $organization->billing_status);
        $this->assertDatabaseHas('product_events', [
            'event_key' => 'billing_past_due_grace_expired',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_premium_access_remains_during_the_grace_period(): void
    {
        config(['opsdeck.billing.past_due_grace_days' => 7]);
        $organization = $this->organization(['billing_past_due_since' => now()->subDays(6)]);

        $this->artisan('opsdeck:billing:enforce-grace')->assertExitCode(0);

        $this->assertSame(config('opsdeck.premium_plan_code'), $organization->refresh()->plan_code);
        $this->assertDatabaseMissing('product_events', [
            'event_key' => 'billing_past_due_grace_expired',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_organization_is_downgraded_at_the_exact_end_of_the_seven_day_grace_period(): void
    {
        config(['opsdeck.billing.past_due_grace_days' => 7]);
        $this->travelTo('2026-10-09 12:00:00');
        $organization = $this->organization(['billing_past_due_since' => now()->subDays(7)]);

        $this->artisan('opsdeck:billing:enforce-grace')->assertExitCode(0);

        $this->assertSame(config('opsdeck.free_plan_code'), $organization->refresh()->plan_code);
    }

    public function test_organization_keeps_premium_access_one_second_before_the_grace_deadline(): void
    {
        config(['opsdeck.billing.past_due_grace_days' => 7]);
        $this->travelTo('2026-10-09 12:00:00');
        $organization = $this->organization(['billing_past_due_since' => now()->subDays(7)->addSecond()]);

        $this->artisan('opsdeck:billing:enforce-grace')->assertExitCode(0);

        $this->assertSame(config('opsdeck.premium_plan_code'), $organization->refresh()->plan_code);
    }

    public function test_legacy_past_due_record_without_start_time_receives_a_full_grace_period(): void
    {
        config(['opsdeck.billing.past_due_grace_days' => 7]);
        $organization = $this->organization(['billing_past_due_since' => null]);

        $this->artisan('opsdeck:billing:enforce-grace')->assertExitCode(0);

        $organization->refresh();
        $this->assertSame(config('opsdeck.premium_plan_code'), $organization->plan_code);
        $this->assertNotNull($organization->billing_past_due_since);
    }

    public function test_missing_grace_start_uses_the_last_webhook_timestamp_before_downgrade(): void
    {
        $organization = $this->organization([
            'billing_past_due_since' => null,
            'stripe_last_event_created_at' => now()->subDays(8)->timestamp,
        ]);

        $this->artisan('opsdeck:billing:enforce-grace')->assertExitCode(0);

        $organization->refresh();
        $this->assertSame(config('opsdeck.free_plan_code'), $organization->plan_code);
        $this->assertTrue($organization->billing_past_due_since->lessThanOrEqualTo(now()->subDays(7)));
    }

    public function test_recovered_or_canceled_organizations_are_not_downgraded_by_the_command(): void
    {
        $recovered = $this->organization(['billing_status' => 'active', 'billing_past_due_since' => now()->subDays(8)]);
        $canceled = $this->organization(['billing_status' => 'cancelled', 'billing_past_due_since' => now()->subDays(8)]);

        $this->artisan('opsdeck:billing:enforce-grace')->assertExitCode(0);

        $this->assertSame(config('opsdeck.premium_plan_code'), $recovered->refresh()->plan_code);
        $this->assertSame(config('opsdeck.premium_plan_code'), $canceled->refresh()->plan_code);
    }

    /** @param array<string, mixed> $overrides */
    private function organization(array $overrides = []): Organization
    {
        $suffix = Str::lower(Str::random(12));

        return Organization::query()->create(array_merge([
            'name' => 'Grace '.$suffix,
            'slug' => 'grace-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'past_due',
            'billing_past_due_since' => now()->subDays(8),
        ], $overrides));
    }
}
