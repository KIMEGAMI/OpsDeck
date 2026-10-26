<?php

namespace Tests\Feature;

use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\OrganizationStorageQuotaService;
use App\Services\PlanAccessService;
use App\Services\PlanFeatureService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request as StripeHttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeWebhookControllerTest extends TestCase
{
    public function test_webhook_is_unavailable_when_billing_is_disabled(): void
    {
        config(['opsdeck.billing.enabled' => false]);
        Http::preventStrayRequests();

        $this->post(route('billing.stripe.webhook'), [], ['Stripe-Signature' => 'invalid'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_webhook_queues_only_minimal_encrypted_event_data_and_returns_before_processing(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        Queue::fake();
        $organization = Organization::query()->create([
            'name' => 'Stripe Queued '.Str::random(8),
            'slug' => 'stripe-queued-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => 'inactive',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
        ]);
        $eventId = 'evt_queued_'.Str::uuid();
        $event = [
            'id' => $eventId,
            'type' => 'customer.subscription.updated',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'id' => 'sub_queued',
                'customer' => $organization->stripe_customer_id,
                'customer_details' => ['email' => 'private@example.invalid'],
                'status' => 'active',
                'cancel_at_period_end' => true,
                'cancel_at' => now()->addMonth()->timestamp,
                'items' => ['data' => [[
                    'id' => 'si_queued',
                    'price' => ['id' => 'price_premium_test'],
                    'quantity' => 1,
                    'current_period_end' => now()->addMonth()->timestamp,
                ]]],
                'cancellation_details' => ['comment' => 'private cancellation note'],
                'metadata' => ['organization_id' => (string) $organization->id, 'private' => 'discard-me'],
            ]],
        ];

        $this->postSignedStripeEvent($event)->assertNoContent();

        $organization->refresh();
        $this->assertSame('inactive', $organization->billing_status);
        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => $eventId, 'processed_at' => null]);
        Queue::assertPushed(ProcessStripeWebhookEvent::class, function (ProcessStripeWebhookEvent $job) use ($eventId, $organization): bool {
            $encoded = json_encode($job->eventData, JSON_THROW_ON_ERROR);

            return $job->eventData['id'] === $eventId
                && $job->eventData['data']['object']['metadata']['organization_id'] === (string) $organization->id
                && $job->eventData['data']['object']['cancel_at_period_end'] === true
                && is_int($job->eventData['data']['object']['cancel_at'])
                && $job->eventData['data']['object']['items']['data'][0]['current_period_end'] > 0
                && $job->eventRecordId > 0
                && $job->uniqueId() === hash_hmac('sha256', (string) $job->eventRecordId, (string) config('app.key'))
                && $job instanceof ShouldBeEncrypted
                && $job instanceof ShouldBeUnique
                && ! str_contains($encoded, 'private@example.invalid')
                && ! str_contains($encoded, 'private cancellation note')
                && ! str_contains($encoded, 'discard-me');
        });
    }

    public function test_database_queue_persists_an_encrypted_job_and_worker_processes_it(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'queue.default' => 'database',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);
        $organization = Organization::query()->create([
            'name' => 'Stripe Worker '.Str::random(8),
            'slug' => 'stripe-worker-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => 'inactive',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
        ]);
        $eventId = 'evt_worker_'.Str::uuid();
        $event = [
            'id' => $eventId,
            'type' => 'invoice.payment_failed',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'subscription' => 'sub_worker',
                'customer' => $organization->stripe_customer_id,
                'metadata' => ['organization_id' => (string) $organization->id],
            ]],
        ];

        $this->postSignedStripeEvent($event)->assertNoContent();

        $this->assertSame('inactive', $organization->refresh()->billing_status);
        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => $eventId, 'processed_at' => null]);
        $queuedPayload = DB::table('jobs')->sole()->payload;
        $queuedPayloadData = json_decode($queuedPayload, true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertArrayNotHasKey('eventRecordId', $queuedPayloadData);
        $this->assertArrayNotHasKey('eventData', $queuedPayloadData);
        $this->assertStringNotContainsString($eventId, $queuedPayload);
        $this->assertStringNotContainsString($organization->stripe_customer_id, $queuedPayload);
        $this->assertStringNotContainsString('customer_details', $queuedPayload);
        $decryptedCommand = Crypt::decryptString($queuedPayloadData['command']);
        $this->assertStringContainsString($eventId, $decryptedCommand);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--quiet' => true])->assertExitCode(0);

        $this->assertNotNull(StripeWebhookEvent::query()->where('stripe_event_id', $eventId)->value('processed_at'));
        $this->assertSame('past_due', $organization->refresh()->billing_status);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_terminal_queue_failure_marks_webhook_for_operations_without_exposing_details(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'queue.default' => 'database',
            'services.stripe.secret' => 'sk_test_synthetic',
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.webhook_job_tries' => 1,
            'services.stripe.webhook_job_backoff_seconds' => [],
        ]);
        $subscriptionId = 'sub_queue_failure_'.Str::uuid();
        $organization = Organization::query()->create([
            'name' => 'Stripe Failed Worker '.Str::random(8),
            'slug' => 'stripe-failed-worker-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => $subscriptionId,
        ]);
        Http::fake(['https://api.stripe.com/v1/subscriptions/'.$subscriptionId => Http::response([], 503)]);
        $eventId = 'evt_queue_failure_'.Str::uuid();

        $this->postSignedStripeEvent([
            'id' => $eventId,
            'type' => 'invoice.payment_failed',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'subscription' => $subscriptionId,
                'customer' => $organization->stripe_customer_id,
                'metadata' => ['organization_id' => (string) $organization->id],
            ]],
        ])->assertNoContent();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--quiet' => true])->assertExitCode(0);

        $event = StripeWebhookEvent::query()->where('stripe_event_id', $eventId)->firstOrFail();
        $this->assertNull($event->processed_at);
        $this->assertSame('event_processing_failed', $event->processing_error);
        $this->assertSame('active', $organization->refresh()->billing_status);
        $failedPayload = DB::table('failed_jobs')->sole()->payload;
        $this->assertStringNotContainsString($subscriptionId, $failedPayload);
        $this->assertStringNotContainsString($organization->stripe_customer_id, $failedPayload);
    }

    public function test_valid_signature_updates_billing_and_deduplicates_event(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $subscriptionId = 'sub_test_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe '.Str::random(8), 'slug' => 'stripe-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => 'inactive', 'stripe_subscription_id' => $subscriptionId]);
        $eventId = 'evt_test_'.Str::uuid();
        $payload = json_encode(['id' => $eventId, 'type' => 'invoice.payment_failed', 'created' => now()->timestamp, 'data' => ['object' => ['subscription' => $subscriptionId, 'customer' => $organization->stripe_customer_id, 'metadata' => ['organization_id' => (string) $organization->id]]]], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $headers = ['Stripe-Signature' => 't='.$timestamp.',v1='.$signature];
        $server = ['HTTP_STRIPE_SIGNATURE' => $headers['Stripe-Signature'], 'CONTENT_TYPE' => 'application/json'];
        $this->call('POST', route('billing.stripe.webhook'), [], [], [], $server, $payload)->assertNoContent();
        $this->assertSame('past_due', $organization->refresh()->billing_status);
        $this->assertNotNull($organization->billing_past_due_since);
        $this->assertSame($eventId, $organization->billing_failure_event_id);
        $this->call('POST', route('billing.stripe.webhook'), [], [], [], $server, $payload)->assertNoContent();
        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['id']]);
    }

    public function test_invoice_event_without_a_subscription_does_not_change_subscription_billing(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $organization = Organization::query()->create([
            'name' => 'Stripe One-Time Invoice '.Str::random(8),
            'slug' => 'stripe-one-time-invoice-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => 'free',
            'billing_status' => 'inactive',
        ]);

        $this->postSignedStripeEvent([
            'id' => 'evt_one_time_invoice_'.Str::uuid(),
            'type' => 'invoice.paid',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'id' => 'in_unrelated_one_time_invoice',
                'customer' => 'cus_unrelated',
                'metadata' => ['organization_id' => (string) $organization->id],
            ]],
        ])->assertNoContent();

        $organization->refresh();
        $this->assertSame('inactive', $organization->billing_status);
        $this->assertSame('free', $organization->plan_code);
        $this->assertNull($organization->stripe_subscription_id);
    }

    public function test_processing_failure_keeps_an_organization_scoped_retry_reference_without_changing_billing_state(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $subscriptionId = 'sub_processing_error_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe Error '.Str::random(8), 'slug' => 'stripe-error-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => 'inactive', 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => $subscriptionId]);
        $eventId = 'evt_processing_error_'.Str::uuid();
        $shouldFail = true;
        ProductEvent::creating(static function (ProductEvent $productEvent) use (&$shouldFail): void {
            if ($shouldFail) {
                throw new \RuntimeException('Synthetic event recording failure.');
            }
        });
        $event = ['id' => $eventId, 'type' => 'invoice.paid', 'created' => now()->timestamp, 'data' => ['object' => ['customer' => $organization->stripe_customer_id, 'subscription' => $subscriptionId]]];

        $response = $this->postSignedStripeEvent($event);

        $response->assertInternalServerError();
        $this->assertSame('inactive', $organization->refresh()->billing_status);
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => $eventId,
            'organization_id' => $organization->id,
            'processing_error' => 'event_processing_failed',
        ]);
        $this->assertDatabaseMissing('product_events', ['organization_id' => $organization->id, 'event_key' => 'billing_active']);

        $shouldFail = false;
        $this->postSignedStripeEvent($event)->assertNoContent();

        $this->assertSame('active', $organization->refresh()->billing_status);
        $webhookEvent = StripeWebhookEvent::query()->where('stripe_event_id', $eventId)->firstOrFail();
        $this->assertNotNull($webhookEvent->processed_at);
        $this->assertNull($webhookEvent->processing_error);
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'billing_active']);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $this->postJson(route('billing.stripe.webhook'), ['id' => 'evt_invalid', 'type' => 'invoice.paid'], ['Stripe-Signature' => 't='.now()->timestamp.',v1=invalid'])->assertStatus(400);
    }

    public function test_webhook_fails_closed_when_secret_is_missing(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->postJson(route('billing.stripe.webhook'), ['id' => 'evt_missing_secret', 'type' => 'invoice.paid'], ['Stripe-Signature' => 't='.now()->timestamp.',v1=invalid'])->assertStatus(503);
    }

    public function test_webhook_rejects_events_without_a_valid_creation_timestamp(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $payload = json_encode(['id' => 'evt_timestamp_missing', 'type' => 'invoice.paid', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->call('POST', route('billing.stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
    }

    public function test_subscription_event_uses_customer_id_and_stores_subscription_id(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $organization = Organization::query()->create(['name' => 'Stripe Customer '.Str::random(8), 'slug' => 'stripe-customer-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => 'inactive', 'stripe_customer_id' => 'cus_'.Str::uuid()]);
        $payload = json_encode(['id' => 'evt_subscription_'.Str::uuid(), 'type' => 'customer.subscription.updated', 'created' => now()->timestamp, 'data' => ['object' => ['id' => 'sub_'.Str::uuid(), 'customer' => $organization->stripe_customer_id, 'status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]]]]], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->call('POST', route('billing.stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertNoContent();
        $this->assertSame('active', $organization->refresh()->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['data']['object']['id'], $organization->stripe_subscription_id);
    }

    public function test_subscription_event_keeps_premium_when_an_addon_item_precedes_the_plan_item(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_addon_test',
        ]);
        $organization = Organization::query()->create([
            'name' => 'Stripe Add-on Order '.Str::random(8),
            'slug' => 'stripe-addon-order-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => 'inactive',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
        ]);

        $this->postSignedStripeEvent([
            'id' => 'evt_subscription_addon_order_'.Str::uuid(),
            'type' => 'customer.subscription.updated',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'id' => 'sub_'.Str::uuid(),
                'customer' => $organization->stripe_customer_id,
                'status' => 'active',
                'items' => ['data' => [
                    ['id' => 'si_storage_addon', 'price' => ['id' => 'price_storage_addon_test'], 'quantity' => 2],
                    ['id' => 'si_premium_plan', 'price' => ['id' => 'price_premium_test'], 'quantity' => 1],
                ]],
            ]],
        ])->assertNoContent();

        $this->assertSame('premium', $organization->refresh()->plan_code);
        $this->assertSame('active', $organization->billing_status);
    }

    public function test_storage_addon_quantity_changes_only_after_the_next_invoice_is_paid(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_addon_test',
        ]);
        $subscriptionId = 'sub_storage_addon_'.Str::uuid();
        $organization = Organization::query()->create([
            'name' => 'Stripe Storage Add-on '.Str::random(8),
            'slug' => 'stripe-storage-addon-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => 'premium',
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => $subscriptionId,
            'storage_addon_gib' => 1,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => now()->addMonth(),
        ]);

        $this->postSignedStripeEvent([
            'id' => 'evt_storage_addon_scheduled_'.Str::uuid(),
            'type' => 'customer.subscription.updated',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'id' => $subscriptionId,
                'customer' => $organization->stripe_customer_id,
                'status' => 'active',
                'items' => ['data' => [
                    ['id' => 'si_premium', 'price' => ['id' => 'price_premium_test'], 'quantity' => 1],
                    ['id' => 'si_storage_addon', 'price' => ['id' => 'price_storage_addon_test'], 'quantity' => 3],
                ]],
            ]],
        ])->assertNoContent();

        $this->assertSame(1, $organization->refresh()->storage_addon_gib);
        $this->assertSame(3, $organization->storage_addon_pending_gib);

        config(['services.stripe.secret' => 'sk_test_secret']);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$subscriptionId => Http::response([
                'id' => $subscriptionId,
                'status' => 'active',
                'items' => ['data' => [
                    ['id' => 'si_premium', 'price' => ['id' => 'price_premium_test'], 'quantity' => 1],
                    ['id' => 'si_storage_addon', 'price' => ['id' => 'price_storage_addon_test'], 'quantity' => 3],
                ]],
            ], 200),
        ]);

        $this->postSignedStripeEvent([
            'id' => 'evt_storage_addon_paid_'.Str::uuid(),
            'type' => 'invoice.paid',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'customer' => $organization->stripe_customer_id,
                'subscription' => $subscriptionId,
                'lines' => ['data' => [
                    ['price' => ['id' => 'price_premium_test']],
                    ['price' => ['id' => 'price_storage_addon_test']],
                ]],
            ]],
        ])->assertNoContent();

        $organization->refresh();
        $this->assertSame(3, $organization->storage_addon_gib);
        $this->assertNull($organization->storage_addon_pending_gib);
        $this->assertNull($organization->storage_addon_effective_at);
    }

    public function test_unrelated_paid_invoice_does_not_clear_a_future_storage_addon_change(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_addon_test',
        ]);
        $subscriptionId = 'sub_storage_addon_future_'.Str::uuid();
        $effectiveAt = now()->addMonth();
        $organization = Organization::query()->create([
            'name' => 'Stripe Storage Add-on Future '.Str::random(8),
            'slug' => 'stripe-storage-addon-future-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => 'premium',
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => $subscriptionId,
            'storage_addon_gib' => 1,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => $effectiveAt,
        ]);

        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$subscriptionId => Http::response([
                'id' => $subscriptionId,
                'status' => 'active',
                'items' => ['data' => [
                    ['id' => 'si_premium', 'price' => ['id' => 'price_premium_test'], 'quantity' => 1],
                    ['id' => 'si_storage_addon', 'price' => ['id' => 'price_storage_addon_test'], 'quantity' => 1],
                ]],
            ], 200),
        ]);

        $this->postSignedStripeEvent([
            'id' => 'evt_storage_addon_unrelated_paid_'.Str::uuid(),
            'type' => 'invoice.paid',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'customer' => $organization->stripe_customer_id,
                'subscription' => $subscriptionId,
                'lines' => ['data' => [
                    ['price' => ['id' => 'price_premium_test']],
                    ['price' => ['id' => 'price_storage_addon_test']],
                ]],
            ]],
        ])->assertNoContent();

        $organization->refresh();
        $this->assertSame(1, $organization->storage_addon_gib);
        $this->assertSame(3, $organization->storage_addon_pending_gib);
        $this->assertSame($effectiveAt->timestamp, $organization->storage_addon_effective_at->timestamp);
    }

    public function test_paid_invoice_without_the_addon_line_does_not_grant_a_scheduled_increase(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_addon_test',
        ]);
        $subscriptionId = 'sub_storage_addon_unbilled_'.Str::uuid();
        $organization = Organization::query()->create([
            'name' => 'Stripe Storage Add-on Unbilled '.Str::random(8),
            'slug' => 'stripe-storage-addon-unbilled-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => 'premium',
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => $subscriptionId,
            'storage_addon_gib' => 1,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => now()->addMonth(),
        ]);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$subscriptionId => Http::response([
                'id' => $subscriptionId,
                'customer' => $organization->stripe_customer_id,
                'status' => 'active',
                'items' => ['data' => [
                    ['id' => 'si_premium', 'price' => ['id' => 'price_premium_test'], 'quantity' => 1],
                    ['id' => 'si_storage_addon', 'price' => ['id' => 'price_storage_addon_test'], 'quantity' => 3],
                ]],
            ], 200),
        ]);

        $this->postSignedStripeEvent([
            'id' => 'evt_storage_addon_unbilled_'.Str::uuid(),
            'type' => 'invoice.paid',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'customer' => $organization->stripe_customer_id,
                'subscription' => $subscriptionId,
                'lines' => ['data' => [
                    ['price' => ['id' => 'price_premium_test']],
                ]],
            ]],
        ])->assertNoContent();

        $organization->refresh();
        $this->assertSame(1, $organization->storage_addon_gib);
        $this->assertSame(3, $organization->storage_addon_pending_gib);
        $this->assertSame('3 GiB', app(OrganizationStorageQuotaService::class)->summary($organization)['limit_display']);
    }

    public function test_unknown_subscription_price_does_not_grant_a_plan(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $organization = Organization::query()->create(['name' => 'Stripe Unknown '.Str::random(8), 'slug' => 'stripe-unknown-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'free', 'billing_status' => 'inactive', 'stripe_customer_id' => 'cus_'.Str::uuid()]);
        $payload = json_encode(['id' => 'evt_unknown_price_'.Str::uuid(), 'type' => 'customer.subscription.updated', 'created' => now()->timestamp, 'data' => ['object' => ['id' => 'sub_'.Str::uuid(), 'customer' => $organization->stripe_customer_id, 'status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_unconfigured']]]]]]], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->call('POST', route('billing.stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertNoContent();

        $this->assertSame('free', $organization->refresh()->plan_code);
    }

    public function test_unknown_subscription_price_revokes_an_existing_premium_plan(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $organization = Organization::query()->create(['name' => 'Stripe Unknown Existing '.Str::random(8), 'slug' => 'stripe-unknown-existing-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => 'active', 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => 'sub_'.Str::uuid()]);

        $response = $this->postSignedStripeEvent(['id' => 'evt_unknown_existing_price_'.Str::uuid(), 'type' => 'customer.subscription.updated', 'created' => now()->timestamp, 'data' => ['object' => ['id' => $organization->stripe_subscription_id, 'customer' => $organization->stripe_customer_id, 'status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_unconfigured']]]]]]]);

        $response->assertNoContent();
        $this->assertSame('free', $organization->refresh()->plan_code);
        $this->assertSame('active', $organization->billing_status);
        $this->assertDatabaseMissing('product_events', ['organization_id' => $organization->id, 'event_key' => 'billing_active']);
    }

    public function test_paid_invoice_with_unknown_price_does_not_restore_premium_access(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $subscriptionId = 'sub_unknown_invoice_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe Unknown Invoice '.Str::random(8), 'slug' => 'stripe-unknown-invoice-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'free', 'billing_status' => 'past_due', 'billing_past_due_since' => now()->subDays(8), 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => $subscriptionId]);

        $response = $this->postSignedStripeEvent(['id' => 'evt_unknown_invoice_price_'.Str::uuid(), 'type' => 'invoice.paid', 'created' => now()->timestamp, 'data' => ['object' => ['customer' => $organization->stripe_customer_id, 'subscription' => $subscriptionId, 'lines' => ['data' => [['pricing' => ['price_details' => ['price' => 'price_unconfigured']]]]]]]]);

        $response->assertNoContent();
        $organization->refresh();
        $this->assertSame('free', $organization->plan_code);
        $this->assertSame('active', $organization->billing_status);
        $this->assertNull($organization->billing_past_due_since);
        $this->assertDatabaseMissing('product_events', ['organization_id' => $organization->id, 'event_key' => 'billing_active']);
    }

    public function test_paid_invoice_with_unknown_price_revokes_an_existing_premium_plan(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $subscriptionId = 'sub_unknown_invoice_existing_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe Unknown Invoice Existing '.Str::random(8), 'slug' => 'stripe-unknown-invoice-existing-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => 'active', 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => $subscriptionId]);

        $response = $this->postSignedStripeEvent(['id' => 'evt_unknown_invoice_existing_'.Str::uuid(), 'type' => 'invoice.paid', 'created' => now()->timestamp, 'data' => ['object' => ['customer' => $organization->stripe_customer_id, 'subscription' => $subscriptionId, 'lines' => ['data' => [['pricing' => ['price_details' => ['price' => 'price_unconfigured']]]]]]]]);

        $response->assertNoContent();
        $this->assertSame('free', $organization->refresh()->plan_code);
        $this->assertSame('active', $organization->billing_status);
        $this->assertDatabaseMissing('product_events', ['organization_id' => $organization->id, 'event_key' => 'billing_active']);
    }

    public function test_incomplete_subscription_does_not_grant_premium_access(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $organization = Organization::query()->create(['name' => 'Stripe Incomplete '.Str::random(8), 'slug' => 'stripe-incomplete-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'free', 'billing_status' => 'inactive', 'stripe_customer_id' => 'cus_'.Str::uuid()]);
        $payload = json_encode(['id' => 'evt_incomplete_'.Str::uuid(), 'type' => 'customer.subscription.created', 'created' => now()->timestamp, 'data' => ['object' => ['id' => 'sub_'.Str::uuid(), 'customer' => $organization->stripe_customer_id, 'status' => 'incomplete', 'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]]]]], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->call('POST', route('billing.stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertNoContent();

        $this->assertSame('free', $organization->refresh()->plan_code);
        $this->assertSame('inactive', $organization->billing_status);
    }

    public function test_inactive_subscription_statuses_revoke_existing_premium_access(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        foreach (['incomplete', 'paused'] as $stripeStatus) {
            $suffix = Str::lower(Str::random(8));
            $subscriptionId = 'sub_inactive_'.$suffix;
            $organization = Organization::query()->create([
                'name' => 'Stripe Inactive '.$suffix,
                'slug' => 'stripe-inactive-'.$suffix,
                'timezone' => config('opsdeck.default_timezone'),
                'plan_code' => config('opsdeck.premium_plan_code'),
                'billing_status' => 'active',
                'stripe_customer_id' => 'cus_'.Str::uuid(),
                'stripe_subscription_id' => $subscriptionId,
            ]);

            $this->postSignedStripeEvent([
                'id' => 'evt_inactive_'.$suffix,
                'type' => 'customer.subscription.updated',
                'created' => now()->timestamp,
                'data' => ['object' => [
                    'id' => $subscriptionId,
                    'customer' => $organization->stripe_customer_id,
                    'status' => $stripeStatus,
                    'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]],
                ]],
            ])->assertNoContent();

            $organization->refresh();
            $this->assertSame(config('opsdeck.free_plan_code'), $organization->plan_code);
            $this->assertSame('inactive', $organization->billing_status);
            $this->assertTrue(app(PlanFeatureService::class)->isAvailable($organization, 'import_execution'));
        }
    }

    public function test_same_event_id_with_different_payload_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $eventId = 'evt_mismatch_'.Str::uuid();
        $firstPayload = json_encode(['id' => $eventId, 'type' => 'invoice.paid', 'created' => now()->timestamp, 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['id' => $eventId, 'type' => 'invoice.payment_failed', 'created' => now()->timestamp, 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $firstSignature = hash_hmac('sha256', $timestamp.'.'.$firstPayload, 'whsec_test');
        $secondSignature = hash_hmac('sha256', $timestamp.'.'.$secondPayload, 'whsec_test');
        $server = ['CONTENT_TYPE' => 'application/json'];

        $this->call('POST', route('billing.stripe.webhook'), [], [], [], $server + ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$firstSignature], $firstPayload)->assertNoContent();
        $this->call('POST', route('billing.stripe.webhook'), [], [], [], $server + ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$secondSignature], $secondPayload)->assertStatus(400);
    }

    public function test_checkout_completed_does_not_activate_an_unpaid_subscription(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $organization = Organization::query()->create(['name' => 'Stripe Checkout '.Str::random(8), 'slug' => 'stripe-checkout-'.Str::lower(Str::random(8)), 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => 'inactive']);
        $event = ['id' => 'evt_checkout_'.Str::uuid(), 'type' => 'checkout.session.completed', 'created' => now()->timestamp, 'data' => ['object' => ['mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'unpaid', 'metadata' => ['organization_id' => (string) $organization->id]]]];

        $this->postSignedStripeEvent($event)->assertNoContent();

        $this->assertSame('inactive', $organization->refresh()->billing_status);
        $this->assertSame('free', $organization->plan_code);
        $this->assertDatabaseMissing('product_events', ['organization_id' => $organization->id, 'event_key' => 'billing_active']);
    }

    public function test_subscription_deleted_records_billing_cancellation_event(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $organization = Organization::query()->create([
            'name' => 'Stripe Cancel '.Str::random(8),
            'slug' => 'stripe-cancel-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Tokyo',
            'plan_code' => 'premium',
            'billing_status' => 'active',
            'billing_cancel_at_period_end' => true,
            'billing_cancel_at' => now()->addMonth(),
            'billing_failure_event_id' => 'evt_old_failure',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => 'sub_'.Str::uuid(),
            'storage_addon_gib' => 2,
            'storage_addon_pending_gib' => 1,
            'storage_addon_effective_at' => now()->addMonth(),
        ]);
        $user = User::factory()->create();
        $diskName = (string) config('opsdeck.import.disk');
        Storage::fake($diskName);
        $existingFilePath = 'canceled-organization/'.Str::uuid().'.pdf';
        Storage::disk($diskName)->put($existingFilePath, 'existing customer file');
        $evidence = Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => '解約後も保持する証跡',
            'evidence_type' => 'policy',
            'storage_disk' => $diskName,
            'storage_path' => $existingFilePath,
            'mime_type' => 'application/pdf',
            'file_size' => 22,
            'uploaded_by' => $user->id,
        ]);
        $payload = json_encode(['id' => 'evt_cancel_'.Str::uuid(), 'type' => 'customer.subscription.deleted', 'created' => now()->timestamp, 'data' => ['object' => ['id' => $organization->stripe_subscription_id, 'customer' => $organization->stripe_customer_id]]], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->call('POST', route('billing.stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertNoContent();
        $this->assertSame('cancelled', $organization->refresh()->billing_status);
        $this->assertSame('free', $organization->plan_code);
        $this->assertNull($organization->billing_failure_event_id);
        $this->assertFalse($organization->billing_cancel_at_period_end);
        $this->assertNull($organization->billing_cancel_at);
        $this->assertSame(0, $organization->storage_addon_gib);
        $this->assertNull($organization->storage_addon_pending_gib);
        $this->assertNull($organization->storage_addon_effective_at);
        $storageQuota = app(OrganizationStorageQuotaService::class)->summary($organization);
        $this->assertSame('100 MiB', $storageQuota['limit_display']);
        $this->assertSame(0, $storageQuota['addon_gib']);
        $this->assertTrue(Storage::disk($diskName)->exists($existingFilePath));
        $this->assertDatabaseHas('evidence', ['id' => $evidence->id]);
        $this->assertDatabaseHas('product_events', ['event_key' => 'billing_cancelled', 'organization_id' => $organization->id]);
    }

    public function test_period_end_cancellation_keeps_premium_storage_until_stripe_confirms_cancellation(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_addon_test',
        ]);
        $subscriptionId = 'sub_cancel_at_period_end_'.Str::uuid();
        $periodEnd = now()->addMonth()->timestamp;
        $organization = Organization::query()->create([
            'name' => 'Stripe Scheduled Cancel '.Str::random(8),
            'slug' => 'stripe-scheduled-cancel-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => 'premium',
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => $subscriptionId,
            'storage_addon_gib' => 2,
        ]);

        $this->postSignedStripeEvent([
            'id' => 'evt_period_end_cancel_'.Str::uuid(),
            'type' => 'customer.subscription.updated',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'id' => $subscriptionId,
                'customer' => $organization->stripe_customer_id,
                'status' => 'active',
                'cancel_at_period_end' => true,
                'cancel_at' => null,
                'items' => ['data' => [
                    ['id' => 'si_premium', 'price' => ['id' => 'price_premium_test'], 'quantity' => 1, 'current_period_end' => $periodEnd],
                    ['id' => 'si_storage_addon', 'price' => ['id' => 'price_storage_addon_test'], 'quantity' => 2, 'current_period_end' => $periodEnd],
                ]],
            ]],
        ])->assertNoContent();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertTrue($organization->billing_cancel_at_period_end);
        $this->assertSame($periodEnd, $organization->billing_cancel_at?->timestamp);
        $this->assertSame('4 GiB', app(OrganizationStorageQuotaService::class)->summary($organization)['limit_display']);

        $this->postSignedStripeEvent([
            'id' => 'evt_cancel_reversed_'.Str::uuid(),
            'type' => 'customer.subscription.updated',
            'created' => now()->timestamp + 1,
            'data' => ['object' => [
                'id' => $subscriptionId,
                'customer' => $organization->stripe_customer_id,
                'status' => 'active',
                'cancel_at_period_end' => false,
                'cancel_at' => null,
                'items' => ['data' => [
                    ['id' => 'si_premium', 'price' => ['id' => 'price_premium_test'], 'quantity' => 1, 'current_period_end' => $periodEnd],
                    ['id' => 'si_storage_addon', 'price' => ['id' => 'price_storage_addon_test'], 'quantity' => 2, 'current_period_end' => $periodEnd],
                ]],
            ]],
        ])->assertNoContent();

        $organization->refresh();
        $this->assertFalse($organization->billing_cancel_at_period_end);
        $this->assertNull($organization->billing_cancel_at);
        $this->assertSame('4 GiB', app(OrganizationStorageQuotaService::class)->summary($organization)['limit_display']);
    }

    public function test_older_or_previous_subscription_events_do_not_revert_current_state(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.prices.premium' => 'price_premium_test',
        ]);
        $currentSubscriptionId = 'sub_current_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe Ordering '.Str::random(8), 'slug' => 'stripe-ordering-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => 'active', 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => $currentSubscriptionId]);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$currentSubscriptionId => Http::response([
                'id' => $currentSubscriptionId,
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]],
            ], 200),
        ]);

        $newerEvent = ['id' => 'evt_newer_'.Str::uuid(), 'type' => 'customer.subscription.updated', 'created' => 200, 'data' => ['object' => ['id' => $currentSubscriptionId, 'customer' => $organization->stripe_customer_id, 'status' => 'active']]];
        $this->postSignedStripeEvent($newerEvent)->assertNoContent();

        $olderFailure = ['id' => 'evt_older_failure_'.Str::uuid(), 'type' => 'invoice.payment_failed', 'created' => 100, 'data' => ['object' => ['subscription' => $currentSubscriptionId, 'customer' => $organization->stripe_customer_id]]];
        $this->postSignedStripeEvent($olderFailure)->assertNoContent();

        $previousSubscriptionDeletion = ['id' => 'evt_previous_sub_'.Str::uuid(), 'type' => 'customer.subscription.deleted', 'created' => 201, 'data' => ['object' => ['id' => 'sub_previous', 'customer' => $organization->stripe_customer_id]]];
        $this->postSignedStripeEvent($previousSubscriptionDeletion)->assertNoContent();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertSame($currentSubscriptionId, $organization->stripe_subscription_id);
        $this->assertSame(200, $organization->stripe_last_event_created_at);
    }

    public function test_same_second_subscription_update_cannot_reactivate_a_canceled_subscription(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.prices.premium' => 'price_premium_test',
        ]);
        $subscriptionId = 'sub_cancel_same_second_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe Same Second '.Str::random(8), 'slug' => 'stripe-same-second-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => 'active', 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => $subscriptionId]);
        $eventCreatedAt = 300;
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$subscriptionId => Http::response([
                'id' => $subscriptionId,
                'status' => 'canceled',
                'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]],
            ], 200),
        ]);

        $this->postSignedStripeEvent(['id' => 'evt_cancel_same_second_'.Str::uuid(), 'type' => 'customer.subscription.deleted', 'created' => $eventCreatedAt, 'data' => ['object' => ['id' => $subscriptionId, 'customer' => $organization->stripe_customer_id]]])->assertNoContent();
        $this->postSignedStripeEvent(['id' => 'evt_update_same_second_'.Str::uuid(), 'type' => 'customer.subscription.updated', 'created' => $eventCreatedAt, 'data' => ['object' => ['id' => $subscriptionId, 'customer' => $organization->stripe_customer_id, 'status' => 'active']]])->assertNoContent();

        $organization->refresh();
        $this->assertSame('cancelled', $organization->billing_status);
        $this->assertSame('free', $organization->plan_code);

        $newSubscriptionId = 'sub_new_same_second_'.Str::uuid();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$newSubscriptionId => Http::response([
                'id' => $newSubscriptionId,
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]],
            ], 200),
        ]);
        $this->postSignedStripeEvent(['id' => 'evt_new_sub_same_second_'.Str::uuid(), 'type' => 'customer.subscription.created', 'created' => $eventCreatedAt, 'data' => ['object' => ['id' => $newSubscriptionId, 'customer' => $organization->stripe_customer_id, 'status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]]]]])->assertNoContent();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertSame($newSubscriptionId, $organization->stripe_subscription_id);
    }

    public function test_same_second_payment_failure_uses_the_current_subscription_state_from_stripe(): void
    {
        $subscriptionId = 'sub_same_second_payment_'.Str::uuid();
        $eventCreatedAt = 600;
        config([
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);
        $organization = Organization::query()->create([
            'name' => 'Stripe Same Second Payment '.Str::random(8),
            'slug' => 'stripe-same-second-payment-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => 'premium',
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_last_event_created_at' => $eventCreatedAt,
        ]);
        $transactionLevels = [];
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$subscriptionId => function () use (&$transactionLevels, $subscriptionId) {
                $transactionLevels[] = DB::connection()->transactionLevel();

                return Http::response([
                    'id' => $subscriptionId,
                    'status' => 'active',
                    'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]],
                ], 200);
            },
        ]);

        $this->postSignedStripeEvent([
            'id' => 'evt_same_second_payment_failure_'.Str::uuid(),
            'type' => 'invoice.payment_failed',
            'created' => $eventCreatedAt,
            'data' => ['object' => [
                'customer' => $organization->stripe_customer_id,
                'subscription' => $subscriptionId,
            ]],
        ])->assertNoContent();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertNull($organization->billing_failure_event_id);
        $this->assertSame([0], $transactionLevels);
        Http::assertSent(fn (StripeHttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/'.$subscriptionId);
    }

    public function test_same_second_reconciliation_failure_does_not_apply_an_unverified_billing_change(): void
    {
        $subscriptionId = 'sub_same_second_unavailable_'.Str::uuid();
        $eventCreatedAt = 700;
        config([
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);
        $organization = Organization::query()->create([
            'name' => 'Stripe Reconciliation Unavailable '.Str::random(8),
            'slug' => 'stripe-reconciliation-unavailable-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => 'premium',
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_last_event_created_at' => $eventCreatedAt,
        ]);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$subscriptionId => Http::response([], 503),
        ]);
        $eventId = 'evt_same_second_unavailable_'.Str::uuid();

        $this->postSignedStripeEvent([
            'id' => $eventId,
            'type' => 'invoice.payment_failed',
            'created' => $eventCreatedAt,
            'data' => ['object' => [
                'customer' => $organization->stripe_customer_id,
                'subscription' => $subscriptionId,
            ]],
        ])->assertInternalServerError();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertNull($organization->billing_failure_event_id);
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => $eventId,
            'processing_error' => 'event_processing_failed',
            'processed_at' => null,
        ]);
    }

    public function test_payment_recovery_clears_grace_start_and_preserves_premium_plan(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $subscriptionId = 'sub_recovery_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe Recovery '.Str::random(8), 'slug' => 'stripe-recovery-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => 'active', 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => $subscriptionId]);

        $this->postSignedStripeEvent(['id' => 'evt_failed_recovery_'.Str::uuid(), 'type' => 'invoice.payment_failed', 'created' => 400, 'data' => ['object' => ['customer' => $organization->stripe_customer_id, 'subscription' => $subscriptionId]]])->assertNoContent();
        $firstFailureTime = $organization->refresh()->billing_past_due_since;
        $this->assertNotNull($firstFailureTime);

        $this->postSignedStripeEvent(['id' => 'evt_repeat_failure_'.Str::uuid(), 'type' => 'invoice.payment_failed', 'created' => 401, 'data' => ['object' => ['customer' => $organization->stripe_customer_id, 'subscription' => $subscriptionId]]])->assertNoContent();
        $this->assertEquals($firstFailureTime, $organization->refresh()->billing_past_due_since);

        $this->postSignedStripeEvent(['id' => 'evt_active_recovery_'.Str::uuid(), 'type' => 'customer.subscription.updated', 'created' => 402, 'data' => ['object' => ['id' => $subscriptionId, 'customer' => $organization->stripe_customer_id, 'status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]]]]])->assertNoContent();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertNull($organization->billing_past_due_since);
        $this->assertNull($organization->billing_failure_event_id);
    }

    public function test_paid_premium_invoice_restores_access_after_grace_downgrade(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $subscriptionId = 'sub_invoice_recovery_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe Invoice Recovery '.Str::random(8), 'slug' => 'stripe-invoice-recovery-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'free', 'billing_status' => 'past_due', 'billing_past_due_since' => now()->subDays(8), 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => $subscriptionId]);

        $event = ['id' => 'evt_invoice_recovered_'.Str::uuid(), 'type' => 'invoice.paid', 'created' => 500, 'data' => ['object' => ['customer' => $organization->stripe_customer_id, 'subscription' => $subscriptionId, 'lines' => ['data' => [['pricing' => ['price_details' => ['price' => 'price_premium_test']]]]]]]];
        $this->postSignedStripeEvent($event)->assertNoContent();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertNull($organization->billing_past_due_since);
    }

    public function test_paid_invoice_after_grace_downgrade_restores_storage_addon_without_duplicate_or_old_event_regression(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_addon_test',
        ]);
        $subscriptionId = 'sub_invoice_addon_recovery_'.Str::uuid();
        $organization = Organization::query()->create([
            'name' => 'Stripe Add-on Recovery '.Str::random(8),
            'slug' => 'stripe-addon-recovery-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => 'free',
            'billing_status' => 'past_due',
            'billing_past_due_since' => now()->subDays(8),
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => $subscriptionId,
            'storage_addon_gib' => 1,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => now()->addMonth(),
        ]);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/'.$subscriptionId => Http::response([
                'id' => $subscriptionId,
                'customer' => $organization->stripe_customer_id,
                'status' => 'active',
                'items' => ['data' => [
                    ['id' => 'si_premium', 'price' => ['id' => 'price_premium_test'], 'quantity' => 1],
                    ['id' => 'si_storage_addon', 'price' => ['id' => 'price_storage_addon_test'], 'quantity' => 3],
                ]],
            ], 200),
        ]);

        $paidEvent = [
            'id' => 'evt_invoice_addon_recovered_'.Str::uuid(),
            'type' => 'invoice.paid',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'customer' => $organization->stripe_customer_id,
                'subscription' => $subscriptionId,
                'lines' => ['data' => [
                    ['price' => ['id' => 'price_premium_test']],
                    ['price' => ['id' => 'price_storage_addon_test']],
                ]],
            ]],
        ];
        $this->postSignedStripeEvent($paidEvent)->assertNoContent();
        $this->postSignedStripeEvent($paidEvent)->assertNoContent();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertNull($organization->billing_past_due_since);
        $this->assertSame(3, $organization->storage_addon_gib);
        $this->assertNull($organization->storage_addon_pending_gib);
        $this->assertNull($organization->storage_addon_effective_at);
        $this->assertSame('5 GiB', app(OrganizationStorageQuotaService::class)->summary($organization)['limit_display']);

        $olderPaymentFailureEvent = [
            'id' => 'evt_old_payment_failure_after_recovery_'.Str::uuid(),
            'type' => 'invoice.payment_failed',
            'created' => max(0, $paidEvent['created'] - 1),
            'data' => ['object' => [
                'customer' => $organization->stripe_customer_id,
                'subscription' => $subscriptionId,
            ]],
        ];
        $this->postSignedStripeEvent($olderPaymentFailureEvent)->assertNoContent();

        $organization->refresh();
        $this->assertSame('active', $organization->billing_status);
        $this->assertSame('premium', $organization->plan_code);
        $this->assertSame(3, $organization->storage_addon_gib);
        $this->assertNull($organization->storage_addon_pending_gib);
        $this->assertSame($paidEvent['created'], $organization->stripe_last_event_created_at);
        Http::assertSentCount(2);
        Http::assertSent(fn (StripeHttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/'.$subscriptionId);
    }

    public function test_past_due_subscription_update_does_not_regrant_expired_premium_plan(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.prices.premium' => 'price_premium_test']);
        $subscriptionId = 'sub_past_due_after_expiry_'.Str::uuid();
        $organization = Organization::query()->create(['name' => 'Stripe Past Due Expired '.Str::random(8), 'slug' => 'stripe-past-due-expired-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'free', 'billing_status' => 'past_due', 'billing_past_due_since' => now()->subDays(8), 'stripe_customer_id' => 'cus_'.Str::uuid(), 'stripe_subscription_id' => $subscriptionId]);

        $event = ['id' => 'evt_past_due_after_expiry_'.Str::uuid(), 'type' => 'customer.subscription.updated', 'created' => now()->timestamp, 'data' => ['object' => ['id' => $subscriptionId, 'customer' => $organization->stripe_customer_id, 'status' => 'past_due', 'items' => ['data' => [['price' => ['id' => 'price_premium_test']]]]]]];
        $this->postSignedStripeEvent($event)->assertNoContent();

        $organization->refresh();
        $this->assertSame('free', $organization->plan_code);
        $this->assertSame('past_due', $organization->billing_status);
        $this->assertSame('free', app(PlanAccessService::class)->effectivePlanCode($organization));
    }

    /** @param array<string, mixed> $event */
    private function postSignedStripeEvent(array $event): TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        return $this->call('POST', route('billing.stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json'], $payload);
    }
}
