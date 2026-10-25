<?php

namespace Tests\Feature;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class StorageAddonReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['opsdeck.release_features.storage_addon' => true]);
    }

    #[RunInSeparateProcess]
    public function test_monthly_reconciliation_is_scheduled_with_overlap_protection(): void
    {
        $event = collect($this->scheduleWithCurrentConfiguration()->events())
            ->first(static fn (Event $event): bool => str_contains((string) $event->command, 'opsdeck:storage-addon:reconcile'));

        $this->assertNotNull($event);
        $this->assertSame('0 3 1 * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[RunInSeparateProcess]
    public function test_monthly_reconciliation_is_not_scheduled_when_addon_is_outside_release_scope(): void
    {
        config(['opsdeck.release_features.storage_addon' => false]);

        $event = collect($this->scheduleWithCurrentConfiguration()->events())
            ->first(static fn (Event $event): bool => str_contains((string) $event->command, 'opsdeck:storage-addon:reconcile'));

        $this->assertNull($event);
    }

    public function test_reconciliation_command_exits_without_work_when_addon_is_outside_release_scope(): void
    {
        config(['opsdeck.release_features.storage_addon' => false]);
        Http::preventStrayRequests();

        $this->artisan('opsdeck:storage-addon:reconcile')
            ->expectsOutput('Storage add-on reconciliation: disabled')
            ->assertExitCode(0);

        $this->assertDatabaseCount('storage_addon_reconciliation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_reconciliation_verifies_the_current_stripe_addon_quantity(): void
    {
        $organization = $this->organization(['storage_addon_gib' => 1]);
        $this->configureStripe();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$organization->stripe_subscription_id => Http::response($this->subscriptionPayload($organization, 1)),
        ]);

        $this->artisan('opsdeck:storage-addon:reconcile')
            ->expectsOutput('Storage add-on reconciliation: verified')
            ->expectsOutput('Organizations checked: 1')
            ->expectsOutput('Mismatches: 0')
            ->assertExitCode(0);

        $this->assertDatabaseHas('storage_addon_reconciliation_runs', [
            'status' => 'verified',
            'organizations_checked' => 1,
            'mismatch_count' => 0,
            'unavailable_count' => 0,
        ]);
    }

    public function test_reconciliation_reports_quantity_mismatch_without_changing_entitlements(): void
    {
        $organization = $this->organization(['storage_addon_gib' => 1]);
        $this->configureStripe();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$organization->stripe_subscription_id => Http::response($this->subscriptionPayload($organization, 2)),
        ]);

        $this->artisan('opsdeck:storage-addon:reconcile')
            ->expectsOutput('Storage add-on reconciliation: mismatch')
            ->expectsOutputToContain('Review storage add-on billing for organization ID '.$organization->id)
            ->assertExitCode(1);

        $organization->refresh();
        $this->assertSame(1, $organization->storage_addon_gib);
        $this->assertDatabaseHas('storage_addon_reconciliation_runs', [
            'status' => 'mismatch',
            'mismatch_count' => 1,
        ]);
        $reconciliationRun = DB::table('storage_addon_reconciliation_runs')
            ->where('status', 'mismatch')
            ->where('mismatch_count', 1)
            ->latest('id')
            ->first();
        $this->assertNotNull($reconciliationRun);
        $this->assertSame([$organization->id], json_decode($reconciliationRun->mismatch_organization_ids, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_reconciliation_verifies_pending_quantity_and_effective_date(): void
    {
        $effectiveAt = CarbonImmutable::now()->addDays(30)->startOfSecond();
        $organization = $this->organization([
            'storage_addon_gib' => 1,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => $effectiveAt,
        ]);
        $scheduleId = 'sub_sched_'.Str::uuid();
        $this->configureStripe();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$organization->stripe_subscription_id => Http::response($this->subscriptionPayload($organization, 1, $scheduleId)),
            'https://api.stripe.com/v1/subscription_schedules/'.$scheduleId => Http::response([
                'id' => $scheduleId,
                'subscription' => $organization->stripe_subscription_id,
                'status' => 'active',
                'metadata' => ['organization_id' => (string) $organization->id, 'purpose' => 'storage_capacity_addon'],
                'current_phase' => ['start_date' => $effectiveAt->subMonth()->timestamp, 'end_date' => $effectiveAt->timestamp],
                'phases' => [
                    ['start_date' => $effectiveAt->subMonth()->timestamp, 'end_date' => $effectiveAt->timestamp, 'items' => $this->subscriptionItems(1)],
                    ['start_date' => $effectiveAt->timestamp, 'end_date' => $effectiveAt->addMonth()->timestamp, 'items' => $this->subscriptionItems(3)],
                ],
            ]),
        ]);

        $this->artisan('opsdeck:storage-addon:reconcile')
            ->expectsOutput('Storage add-on reconciliation: verified')
            ->assertExitCode(0);

        Http::assertSent(static fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.stripe.com/v1/subscription_schedules/'.$scheduleId);
    }

    public function test_reconciliation_is_unavailable_when_configured_entitlements_cannot_be_verified(): void
    {
        $organization = $this->organization(['storage_addon_gib' => 1]);
        config([
            'services.stripe.secret' => null,
            'services.stripe.prices.premium' => null,
            'services.stripe.prices.storage_addon_gib' => null,
        ]);
        Http::preventStrayRequests();

        $this->artisan('opsdeck:storage-addon:reconcile')
            ->expectsOutput('Storage add-on reconciliation: unavailable')
            ->expectsOutputToContain('Could not verify storage add-on billing for organization ID '.$organization->id)
            ->assertExitCode(1);

        $this->assertDatabaseHas('storage_addon_reconciliation_runs', [
            'status' => 'unavailable',
            'unavailable_count' => 1,
        ]);
        Http::assertNothingSent();
    }

    public function test_unconfigured_reconciliation_is_non_blocking_when_no_addon_entitlements_exist(): void
    {
        config([
            'services.stripe.secret' => null,
            'services.stripe.prices.premium' => null,
            'services.stripe.prices.storage_addon_gib' => null,
        ]);
        Http::preventStrayRequests();

        $this->artisan('opsdeck:storage-addon:reconcile')
            ->expectsOutput('Storage add-on reconciliation: not_configured')
            ->assertExitCode(0);

        $this->assertDatabaseHas('storage_addon_reconciliation_runs', [
            'status' => 'not_configured',
            'organizations_checked' => 0,
            'unavailable_count' => 0,
        ]);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides */
    private function organization(array $overrides = []): Organization
    {
        $suffix = Str::lower(Str::random(10));

        return Organization::factory()->create(array_merge([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => 'sub_'.Str::uuid(),
            'name' => 'Reconcile '.$suffix,
            'slug' => 'reconcile-'.$suffix,
        ], $overrides));
    }

    private function scheduleWithCurrentConfiguration(): Schedule
    {
        $this->app->forgetInstance(Schedule::class);

        return app(Schedule::class);
    }

    private function configureStripe(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(Organization $organization, int $addonQuantity, ?string $scheduleId = null): array
    {
        return [
            'id' => $organization->stripe_subscription_id,
            'customer' => $organization->stripe_customer_id,
            'status' => 'active',
            'metadata' => ['organization_id' => (string) $organization->id],
            'items' => ['data' => $this->subscriptionItems($addonQuantity)],
            'schedule' => $scheduleId,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function subscriptionItems(int $addonQuantity): array
    {
        return [
            ['price' => ['id' => 'price_premium_test'], 'quantity' => 1],
            ['price' => ['id' => 'price_storage_test'], 'quantity' => $addonQuantity],
        ];
    }
}
