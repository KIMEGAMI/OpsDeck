<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use App\Services\ProductEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $this->verifySignature($payload, (string) $request->header('Stripe-Signature'));
        $decoded = json_decode($payload, true);
        abort_unless(is_array($decoded) && is_string($decoded['id'] ?? null) && is_string($decoded['type'] ?? null), 400, 'Webhook payload is invalid.');
        abort_unless(is_int($decoded['created'] ?? null) && $decoded['created'] >= 0, 400, 'Webhook event timestamp is invalid.');
        $queueSafeEvent = $this->queueSafeEvent($decoded);
        $payloadHash = hash('sha256', $payload);
        $organization = $this->organizationForEvent($decoded);
        try {
            $event = StripeWebhookEvent::query()->firstOrCreate(
                ['stripe_event_id' => $decoded['id']],
                ['organization_id' => $organization?->id, 'event_type' => $decoded['type'], 'payload_hash' => $payloadHash, 'stripe_created_at' => $decoded['created']],
            );
        } catch (QueryException) {
            $event = StripeWebhookEvent::query()->where('stripe_event_id', $decoded['id'])->first();
            abort_unless($event !== null, 409, 'Webhook event could not be recorded.');
        }
        abort_if($event->payload_hash !== $payloadHash, 400, 'Webhook event payload mismatch.');
        $missingAttributes = [];
        if ($event->organization_id === null && $organization !== null) {
            $missingAttributes['organization_id'] = $organization->id;
        }
        if ($event->stripe_created_at === null) {
            $missingAttributes['stripe_created_at'] = $decoded['created'];
        }
        if ($missingAttributes !== []) {
            $event->update($missingAttributes);
        }
        if ($event->processed_at !== null) {
            return response()->noContent();
        }
        try {
            ProcessStripeWebhookEvent::dispatch($event->id, $queueSafeEvent);
        } catch (\Throwable $exception) {
            $event->update(['processing_error' => 'event_processing_failed']);

            throw $exception;
        }

        return response()->noContent();
    }

    /** @param array<string, mixed> $event */
    public function processRecordedEvent(int $eventId, array $event): void
    {
        $recordedEvent = StripeWebhookEvent::query()->findOrFail($eventId);
        if ($recordedEvent->processed_at !== null) {
            return;
        }

        $organization = $this->organizationForEvent($event);
        $processEvent = function () use ($event, $recordedEvent, $organization): void {
            $recordedEvent->refresh();
            if ($recordedEvent->processed_at !== null) {
                return;
            }

            $latestSubscription = $organization === null
                ? null
                : $this->latestStripeSubscriptionForEvent($event, $organization);
            DB::transaction(function () use ($event, $recordedEvent, $latestSubscription): void {
                $lockedEvent = StripeWebhookEvent::query()->lockForUpdate()->findOrFail($recordedEvent->id);
                if ($lockedEvent->processed_at !== null) {
                    return;
                }

                $this->applyEvent($event, $latestSubscription);
                $lockedEvent->update(['processed_at' => now(), 'processing_error' => null]);
            });
        };

        if ($organization === null) {
            $processEvent();
        } else {
            Cache::lock(
                'opsdeck:stripe-webhook:organization:'.$organization->id,
                (int) config('services.stripe.webhook_lock_seconds'),
            )->block((int) config('services.stripe.webhook_lock_wait_seconds'), $processEvent);
        }
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function queueSafeEvent(array $event): array
    {
        $object = data_get($event, 'data.object', []);
        abort_unless(is_array($object), 400, 'Webhook event object is invalid.');

        $safeObject = [];
        foreach (['id', 'customer', 'subscription', 'status', 'client_reference_id'] as $key) {
            if (is_string($object[$key] ?? null)) {
                $safeObject[$key] = $object[$key];
            }
        }
        if (is_bool($object['cancel_at_period_end'] ?? null)) {
            $safeObject['cancel_at_period_end'] = $object['cancel_at_period_end'];
        }
        if (is_int($object['cancel_at'] ?? null) && $object['cancel_at'] > 0) {
            $safeObject['cancel_at'] = $object['cancel_at'];
        }
        $organizationId = data_get($object, 'metadata.organization_id');
        if (is_string($organizationId) || is_int($organizationId)) {
            $safeObject['metadata'] = ['organization_id' => (string) $organizationId];
        }
        $parentSubscription = data_get($object, 'parent.subscription_details.subscription');
        if (is_string($parentSubscription)) {
            $safeObject['parent'] = ['subscription_details' => ['subscription' => $parentSubscription]];
        }
        $subscriptionItems = data_get($object, 'items.data', []);
        if (is_array($subscriptionItems)) {
            $safeSubscriptionItems = [];
            foreach ($subscriptionItems as $subscriptionItem) {
                if (! is_array($subscriptionItem)) {
                    continue;
                }

                $safeItem = [];
                $itemId = data_get($subscriptionItem, 'id');
                $priceId = data_get($subscriptionItem, 'price.id') ?? data_get($subscriptionItem, 'price');
                $quantity = data_get($subscriptionItem, 'quantity');
                if (is_string($itemId)) {
                    $safeItem['id'] = $itemId;
                }
                if (is_string($priceId)) {
                    $safeItem['price'] = ['id' => $priceId];
                }
                if (is_int($quantity) && $quantity >= 0) {
                    $safeItem['quantity'] = $quantity;
                }
                $currentPeriodEnd = data_get($subscriptionItem, 'current_period_end');
                if (is_int($currentPeriodEnd) && $currentPeriodEnd > 0) {
                    $safeItem['current_period_end'] = $currentPeriodEnd;
                }
                if ($safeItem !== []) {
                    $safeSubscriptionItems[] = $safeItem;
                }
            }
            $safeObject['items'] = ['data' => $safeSubscriptionItems];
        }
        $lineItems = data_get($object, 'lines.data', []);
        if (is_array($lineItems)) {
            $safeLineItems = [];
            foreach ($lineItems as $lineItem) {
                $priceId = data_get($lineItem, 'price.id') ?? data_get($lineItem, 'pricing.price_details.price');
                if (is_string($priceId)) {
                    $safeLineItems[] = ['price' => ['id' => $priceId]];
                }
            }
            $safeObject['lines'] = ['data' => $safeLineItems];
        }

        return [
            'id' => $event['id'],
            'type' => $event['type'],
            'created' => $event['created'],
            'data' => ['object' => $safeObject],
        ];
    }

    private function verifySignature(string $payload, string $header): void
    {
        abort_unless(is_string(config('services.stripe.webhook_secret')) && config('services.stripe.webhook_secret') !== '', 503, 'Webhook secret is not configured.');
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($key === 't') {
                $timestamp = is_numeric($value) ? (int) $value : null;
            } elseif ($key === 'v1' && is_string($value)) {
                $signatures[] = $value;
            }
        }
        abort_unless($timestamp !== null && $signatures !== [], 400, 'Webhook signature is missing.');
        abort_if(abs(now()->timestamp - $timestamp) > config('services.stripe.signature_tolerance_seconds'), 400, 'Webhook signature is expired.');
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, (string) config('services.stripe.webhook_secret'));
        abort_unless(collect($signatures)->contains(fn (string $signature): bool => hash_equals($expected, $signature)), 400, 'Webhook signature is invalid.');
    }

    private function applyEvent(array $event, ?array $latestSubscription): void
    {
        $organization = $this->organizationForEvent($event, true);
        if ($organization === null) {
            return;
        }
        $eventCreatedAt = $event['created'];
        $isInvoiceEvent = in_array($event['type'], ['invoice.paid', 'invoice.payment_failed'], true);
        $subscriptionId = $isInvoiceEvent
            ? data_get($event, 'data.object.subscription') ?? data_get($event, 'data.object.parent.subscription_details.subscription')
            : data_get($event, 'data.object.id');
        if ($isInvoiceEvent && ! is_string($subscriptionId)) {
            return;
        }
        $mayReactivateSubscription = in_array($event['type'], [
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'invoice.paid',
            'invoice.payment_failed',
        ], true);
        if ($organization->billing_status === 'cancelled'
            && $mayReactivateSubscription
            && is_string($subscriptionId)
            && is_string($organization->stripe_subscription_id)
            && hash_equals($organization->stripe_subscription_id, $subscriptionId)) {
            return;
        }
        if (is_string($subscriptionId)
            && is_string($organization->stripe_subscription_id)
            && ! hash_equals($organization->stripe_subscription_id, $subscriptionId)
            && in_array($organization->billing_status, ['active', 'past_due'], true)) {
            return;
        }
        $status = match ($event['type']) {
            'invoice.paid' => 'active',
            'customer.subscription.created', 'customer.subscription.updated' => $this->subscriptionBillingStatus(data_get($event, 'data.object.status')),
            'invoice.payment_failed' => 'past_due',
            'customer.subscription.deleted' => 'cancelled',
            default => null,
        };
        if ($latestSubscription !== null) {
            $status = $this->subscriptionBillingStatus($latestSubscription['status'] ?? null);
        }
        if ($status !== null) {
            $attributes = [
                'billing_status' => $status,
                'stripe_last_event_created_at' => max((int) ($organization->stripe_last_event_created_at ?? 0), $eventCreatedAt),
            ];
            if ($status === 'inactive') {
                $attributes['plan_code'] = config('opsdeck.free_plan_code');
            }
            if ($status === 'past_due'
                && ($organization->billing_status !== 'past_due' || $organization->billing_past_due_since === null)) {
                $attributes['billing_past_due_since'] = CarbonImmutable::createFromTimestamp($eventCreatedAt);
            } elseif (in_array($status, ['active', 'cancelled', 'inactive'], true)) {
                $attributes['billing_past_due_since'] = null;
            }
            if ($status === 'past_due') {
                if ($event['type'] === 'invoice.payment_failed'
                    || ($event['type'] !== 'invoice.paid' && $organization->billing_failure_event_id === null)) {
                    $attributes['billing_failure_event_id'] = $event['id'];
                }
            } elseif (in_array($status, ['active', 'cancelled', 'inactive'], true)) {
                $attributes['billing_failure_event_id'] = null;
            }
            if (in_array($event['type'], ['customer.subscription.created', 'customer.subscription.updated'], true) && is_string($subscriptionId)) {
                $attributes['stripe_subscription_id'] = $subscriptionId;
                $subscription = $latestSubscription ?? data_get($event, 'data.object', []);
                $stripeStatus = $subscription['status'] ?? data_get($event, 'data.object.status');
                $planCode = $this->planCodeForSubscription($subscription);
                if (in_array($stripeStatus, ['active', 'trialing'], true)) {
                    $attributes['plan_code'] = $planCode ?? config('opsdeck.default_plan_code');
                }
                $cancellationAttributes = $this->scheduledCancellationAttributes($subscription);
                if ($cancellationAttributes !== null) {
                    $attributes = array_merge($attributes, $cancellationAttributes);
                }
            }
            if ($event['type'] === 'invoice.paid') {
                $planCode = $latestSubscription === null
                    ? $this->planCodeForInvoice($event)
                    : $this->planCodeForSubscription($latestSubscription);
                $invoicePriceIds = $this->invoicePriceIds($event);
                $storageAddonQuantity = $latestSubscription === null
                    ? null
                    : $this->storageAddonQuantityForSubscription($latestSubscription);
                if (($latestSubscription !== null || $invoicePriceIds !== []) && is_string($subscriptionId)) {
                    $attributes['plan_code'] = $planCode ?? config('opsdeck.default_plan_code');
                    $attributes['stripe_subscription_id'] ??= $subscriptionId;
                }
                $pendingStorageAddonQuantity = $organization->storage_addon_pending_gib;
                $currentStorageAddonQuantity = max(0, (int) $organization->storage_addon_gib);
                $storageAddonPriceId = config('services.stripe.prices.storage_addon_gib');
                $increasedStorageAddonWasIncludedOnPaidInvoice = $storageAddonQuantity === null
                    || $storageAddonQuantity <= $currentStorageAddonQuantity
                    || (is_string($storageAddonPriceId) && in_array($storageAddonPriceId, $invoicePriceIds, true));
                if ($storageAddonQuantity !== null
                    && $increasedStorageAddonWasIncludedOnPaidInvoice
                    && ($pendingStorageAddonQuantity === null || $storageAddonQuantity === (int) $pendingStorageAddonQuantity)) {
                    $attributes['storage_addon_gib'] = $storageAddonQuantity;
                    $attributes['storage_addon_pending_gib'] = null;
                    $attributes['storage_addon_effective_at'] = null;
                }
            }
            if ($event['type'] === 'customer.subscription.deleted') {
                if (is_string($organization->stripe_subscription_id)
                    && is_string($subscriptionId)
                    && $organization->stripe_subscription_id !== $subscriptionId) {
                    return;
                }
                $attributes['plan_code'] = config('opsdeck.default_plan_code');
                $attributes['billing_cancel_at_period_end'] = false;
                $attributes['billing_cancel_at'] = null;
                $attributes['storage_addon_gib'] = 0;
                $attributes['storage_addon_pending_gib'] = null;
                $attributes['storage_addon_effective_at'] = null;
            }
            $organization->update($attributes);
            if ($status === 'active' && $organization->plan_code !== config('opsdeck.default_plan_code')) {
                app(ProductEventRecorder::class)->recordOnce('billing_active', $organization);
            }
            if ($status === 'cancelled') {
                app(ProductEventRecorder::class)->recordOnce('billing_cancelled', $organization);
            }
        }
    }

    /** @param array<string, mixed> $subscription
     * @return array{billing_cancel_at_period_end: bool, billing_cancel_at: CarbonImmutable|null}|null
     */
    private function scheduledCancellationAttributes(array $subscription): ?array
    {
        if (! is_bool($subscription['cancel_at_period_end'] ?? null)
            && ! is_int($subscription['cancel_at'] ?? null)) {
            return null;
        }

        $cancelAtPeriodEnd = ($subscription['cancel_at_period_end'] ?? false) === true;
        $cancelAt = $subscription['cancel_at'] ?? null;
        if ($cancelAtPeriodEnd && (! is_int($cancelAt) || $cancelAt < 1)) {
            $premiumPriceId = config('services.stripe.prices.premium');
            $items = data_get($subscription, 'items.data', []);
            if (is_string($premiumPriceId) && $premiumPriceId !== '' && is_array($items)) {
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $priceId = data_get($item, 'price.id') ?? data_get($item, 'price');
                    $currentPeriodEnd = $item['current_period_end'] ?? null;
                    if (is_string($priceId) && hash_equals($premiumPriceId, $priceId)
                        && is_int($currentPeriodEnd) && $currentPeriodEnd > 0) {
                        $cancelAt = $currentPeriodEnd;

                        break;
                    }
                }
            }
        }

        return [
            'billing_cancel_at_period_end' => $cancelAtPeriodEnd,
            'billing_cancel_at' => is_int($cancelAt) && $cancelAt > 0
                ? CarbonImmutable::createFromTimestamp($cancelAt)
                : null,
        ];
    }

    private function organizationForEvent(array $event, bool $lockForUpdate = false): ?Organization
    {
        $organizationId = data_get($event, 'data.object.metadata.organization_id') ?? data_get($event, 'data.object.client_reference_id');
        $customerId = data_get($event, 'data.object.customer');
        if (is_numeric($organizationId)) {
            $organizationQuery = Organization::query()->whereKey((int) $organizationId);
        } elseif (is_string($customerId)) {
            $organizationQuery = Organization::query()->where('stripe_customer_id', $customerId);
        } else {
            return null;
        }

        if ($lockForUpdate) {
            $organizationQuery->lockForUpdate();
        }

        return $organizationQuery->first();
    }

    private function subscriptionBillingStatus(mixed $stripeStatus): ?string
    {
        return match ($stripeStatus) {
            'active', 'trialing' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'incomplete_expired' => 'cancelled',
            'incomplete', 'paused' => 'inactive',
            default => null,
        };
    }

    /** @param array<string, mixed> $event */
    private function latestStripeSubscriptionForEvent(array $event, Organization $organization): ?array
    {
        $eventType = $event['type'];
        if (! in_array($eventType, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.paid',
            'invoice.payment_failed',
        ], true)) {
            return null;
        }

        $subscriptionId = in_array($eventType, ['invoice.paid', 'invoice.payment_failed'], true)
            ? data_get($event, 'data.object.subscription') ?? data_get($event, 'data.object.parent.subscription_details.subscription')
            : data_get($event, 'data.object.id');
        if (is_string($subscriptionId)
            && is_string($organization->stripe_subscription_id)
            && ! hash_equals($organization->stripe_subscription_id, $subscriptionId)
            && in_array($organization->billing_status, ['active', 'past_due'], true)) {
            return null;
        }

        return is_string($subscriptionId) && $subscriptionId !== ''
            ? $this->latestStripeSubscription($subscriptionId)
            : null;
    }

    /** @return array<string, mixed> */
    private function latestStripeSubscription(string $subscriptionId): ?array
    {
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || $secret === '') {
            if (app()->environment('testing')) {
                return null;
            }

            throw new RuntimeException('Stripe subscription state could not be confirmed.');
        }

        $response = Http::withBasicAuth($secret, '')
            ->connectTimeout((int) config('services.stripe.connect_timeout_seconds'))
            ->timeout((int) config('services.stripe.timeout_seconds'))
            ->acceptJson()
            ->get('https://api.stripe.com/v1/subscriptions/'.rawurlencode($subscriptionId));
        $subscription = $response->json();
        if (! $response->successful() || ! is_array($subscription) || ! is_string($subscription['status'] ?? null)) {
            throw new RuntimeException('Stripe subscription state could not be confirmed.');
        }

        return $subscription;
    }

    private function planCodeForPrice(mixed $priceId): ?string
    {
        if (! is_string($priceId) || $priceId === '') {
            return null;
        }

        foreach (config('services.stripe.prices', []) as $planCode => $configuredPriceId) {
            if (array_key_exists($planCode, config('opsdeck.plans', []))
                && is_string($configuredPriceId)
                && $configuredPriceId !== ''
                && hash_equals($configuredPriceId, $priceId)) {
                return $planCode;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $subscription */
    private function planCodeForSubscription(array $subscription): ?string
    {
        $items = data_get($subscription, 'items.data', []);
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $priceId = data_get($item, 'price.id') ?? data_get($item, 'price');
            $planCode = $this->planCodeForPrice($priceId);
            if ($planCode !== null) {
                return $planCode;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $subscription */
    private function storageAddonQuantityForSubscription(array $subscription): ?int
    {
        $storageAddonPriceId = config('services.stripe.prices.storage_addon_gib');
        if (! is_string($storageAddonPriceId) || $storageAddonPriceId === '') {
            return null;
        }

        $items = data_get($subscription, 'items.data', []);
        if (! is_array($items)) {
            return null;
        }

        $quantity = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $priceId = data_get($item, 'price.id') ?? data_get($item, 'price');
            if (! is_string($priceId) || ! hash_equals($storageAddonPriceId, $priceId)) {
                continue;
            }

            $itemQuantity = data_get($item, 'quantity');
            if (! is_int($itemQuantity) || $itemQuantity < 0 || $quantity > PHP_INT_MAX - $itemQuantity) {
                return null;
            }

            $quantity += $itemQuantity;
        }

        return $quantity;
    }

    private function planCodeForInvoice(array $event): ?string
    {
        foreach ($this->invoicePriceIds($event) as $priceId) {
            $planCode = $this->planCodeForPrice($priceId);
            if ($planCode !== null) {
                return $planCode;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function invoicePriceIds(array $event): array
    {
        $lineItems = data_get($event, 'data.object.lines.data', []);
        if (! is_array($lineItems)) {
            return [];
        }

        $priceIds = [];
        foreach ($lineItems as $lineItem) {
            $priceId = data_get($lineItem, 'price.id') ?? data_get($lineItem, 'pricing.price_details.price');
            if (is_string($priceId) && $priceId !== '') {
                $priceIds[] = $priceId;
            }
        }

        return $priceIds;
    }
}
