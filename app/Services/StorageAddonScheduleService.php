<?php

namespace App\Services;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StorageAddonScheduleService
{
    public function __construct(private StorageAddonCapacityService $capacityService) {}

    public function isAvailableFor(Organization $organization): bool
    {
        return (bool) config('opsdeck.billing.enabled')
            && (bool) config('opsdeck.release_features.storage_addon')
            && $this->isConfigured()
            && $organization->plan_code === config('opsdeck.premium_plan_code')
            && $organization->billing_status === 'active'
            && ! $organization->billing_cancel_at_period_end
            && $organization->billing_cancel_at === null
            && is_string($organization->stripe_customer_id)
            && $organization->stripe_customer_id !== ''
            && is_string($organization->stripe_subscription_id)
            && $organization->stripe_subscription_id !== '';
    }

    public function maximumQuantity(): ?int
    {
        return $this->capacityService->maximumQuantity();
    }

    public function schedule(Organization $organization, int $targetQuantity): CarbonImmutable
    {
        if (! $this->isAvailableFor($organization)) {
            throw new RuntimeException('Storage add-on is not available.');
        }

        $maximumQuantity = $this->maximumQuantity();
        if ($maximumQuantity === null || $targetQuantity < 0 || $targetQuantity > $maximumQuantity) {
            throw new RuntimeException('Storage add-on quantity is outside the configured range.');
        }

        try {
            return Cache::lock(
                'opsdeck:organization-storage-quota:'.hash('sha256', (string) $organization->getKey()),
                (int) config('opsdeck.storage_quotas.lock_seconds'),
            )->block((int) config('opsdeck.storage_quotas.lock_wait_seconds'), function () use ($organization, $targetQuantity): CarbonImmutable {
                $freshOrganization = Organization::query()->findOrFail($organization->getKey());
                if (! $this->isAvailableFor($freshOrganization)) {
                    throw new RuntimeException('Storage add-on is not available.');
                }

                return Cache::lock(
                    'opsdeck:platform-storage-quota',
                    (int) config('opsdeck.storage_quotas.lock_seconds'),
                )->block((int) config('opsdeck.storage_quotas.lock_wait_seconds'), function () use ($freshOrganization, $targetQuantity): CarbonImmutable {
                    if (! $this->capacityService->canSchedule($freshOrganization, $targetQuantity)) {
                        throw new RuntimeException('Platform storage capacity is unavailable.');
                    }

                    $effectiveAt = $this->scheduleWithStripe($freshOrganization, $targetQuantity);
                    $freshOrganization->forceFill([
                        'storage_addon_pending_gib' => $targetQuantity,
                        'storage_addon_effective_at' => $effectiveAt,
                    ])->save();

                    return $effectiveAt;
                });
            });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Storage add-on scheduling is busy.', previous: $exception);
        }
    }

    private function isConfigured(): bool
    {
        return is_string(config('services.stripe.secret'))
            && trim((string) config('services.stripe.secret')) !== ''
            && is_string(config('services.stripe.prices.premium'))
            && trim((string) config('services.stripe.prices.premium')) !== ''
            && is_string(config('services.stripe.prices.storage_addon_gib'))
            && trim((string) config('services.stripe.prices.storage_addon_gib')) !== ''
            && $this->capacityService->isConfigured();
    }

    private function scheduleWithStripe(Organization $organization, int $targetQuantity): CarbonImmutable
    {
        $secret = (string) config('services.stripe.secret');
        $premiumPriceId = (string) config('services.stripe.prices.premium');
        $addonPriceId = (string) config('services.stripe.prices.storage_addon_gib');
        $subscriptionId = (string) $organization->stripe_subscription_id;
        $headers = ['Idempotency-Key' => $this->idempotencyKey('schedule', $organization, ['subscription' => $subscriptionId])];

        $addonPrice = $this->stripeRequest('GET', '/prices/'.rawurlencode($addonPriceId), $secret, ['expand[]' => 'product']);
        $this->assertAddonPriceIsValid($addonPrice, $addonPriceId);

        $subscription = $this->stripeRequest('GET', '/subscriptions/'.rawurlencode($subscriptionId), $secret);
        $this->assertSubscriptionBelongsToOrganization($subscription, $organization, $premiumPriceId, $addonPriceId);

        $scheduleId = $subscription['schedule'] ?? null;
        if (is_array($scheduleId)) {
            $scheduleId = $scheduleId['id'] ?? null;
        }
        if (! is_string($scheduleId) || $scheduleId === '') {
            $schedule = $this->stripeRequest('POST', '/subscription_schedules', $secret, [
                'from_subscription' => $subscriptionId,
                'metadata[organization_id]' => (string) $organization->getKey(),
                'metadata[purpose]' => 'storage_capacity_addon',
            ], $headers);
            $scheduleId = $schedule['id'] ?? null;
        }
        if (! is_string($scheduleId) || $scheduleId === '') {
            throw new RuntimeException('Stripe did not return a subscription schedule.');
        }

        $schedule = $this->stripeRequest('GET', '/subscription_schedules/'.rawurlencode($scheduleId), $secret);
        $this->assertScheduleBelongsToOrganization($schedule, $organization, $subscriptionId);

        $currentPhase = $schedule['current_phase'] ?? null;
        $phases = $schedule['phases'] ?? null;
        if (! is_array($currentPhase)
            || ! is_int($currentPhase['start_date'] ?? null)
            || ! is_int($currentPhase['end_date'] ?? null)
            || ! is_array($phases)
            || count($phases) < 1
            || count($phases) > 2) {
            throw new RuntimeException('Stripe subscription schedule has an unsupported phase configuration.');
        }

        $currentPhaseData = $this->editablePhase($phases[0] ?? []);
        $currentItems = $currentPhaseData['items'] ?? null;
        if (! is_array($currentItems) || ! $this->containsPrice($currentItems, $premiumPriceId)) {
            throw new RuntimeException('Stripe subscription schedule does not contain the expected Premium price.');
        }

        $nextItems = $currentItems;
        $addonItemIndex = $this->findPriceIndex($nextItems, $addonPriceId);
        if ($targetQuantity === 0) {
            if ($addonItemIndex !== null) {
                array_splice($nextItems, $addonItemIndex, 1);
            }
        } elseif ($addonItemIndex === null) {
            $nextItems[] = ['price' => $addonPriceId, 'quantity' => $targetQuantity];
        } else {
            $nextItems[$addonItemIndex]['quantity'] = $targetQuantity;
        }

        $nextPhase = $this->editablePhase($phases[1] ?? $phases[0]);
        if (isset($phases[1]) && ! $this->sameNonAddonItems($currentItems, $nextPhase['items'], $addonPriceId)) {
            throw new RuntimeException('Stripe subscription schedule changes additional items outside this feature.');
        }
        $nextPhase['start_date'] = $currentPhase['end_date'];
        $nextPhase['iterations'] = 1;
        $nextPhase['items'] = $nextItems;

        $scheduleParameters = [
            'end_behavior' => 'release',
            'proration_behavior' => 'none',
            'phases' => [$currentPhaseData, $nextPhase],
            'metadata' => [
                'organization_id' => (string) $organization->getKey(),
                'purpose' => 'storage_capacity_addon',
            ],
        ];
        $scheduleParameters['phases'][0]['start_date'] = $currentPhase['start_date'];
        $scheduleParameters['phases'][0]['end_date'] = $currentPhase['end_date'];
        $scheduleParameters['phases'][0]['proration_behavior'] = 'none';
        $scheduleParameters['phases'][1]['proration_behavior'] = 'none';

        $updatedSchedule = $this->stripeRequest('POST', '/subscription_schedules/'.rawurlencode($scheduleId), $secret, $scheduleParameters, [
            'Idempotency-Key' => $this->idempotencyKey('storage-addon-update', $organization, [
                'schedule' => $scheduleId,
                'quantity' => $targetQuantity,
                'period_end' => $currentPhase['end_date'],
            ]),
        ]);

        $updatedPhases = $updatedSchedule['phases'] ?? null;
        $updatedCurrentPhase = $updatedSchedule['current_phase'] ?? null;
        if (($updatedSchedule['id'] ?? null) !== $scheduleId
            || ($updatedSchedule['subscription'] ?? null) !== $subscriptionId
            || ! is_array($updatedPhases)
            || count($updatedPhases) !== 2
            || ! is_array($updatedPhases[0] ?? null)
            || ! is_array($updatedPhases[1] ?? null)
            || ! is_array($updatedCurrentPhase)
            || ($updatedCurrentPhase['end_date'] ?? null) !== $currentPhase['end_date']
            || ($updatedPhases[1]['start_date'] ?? null) !== $currentPhase['end_date']
            || ! is_int($updatedPhases[1]['end_date'] ?? null)
            || $updatedPhases[1]['end_date'] <= $currentPhase['end_date']
            || ! is_array($updatedPhases[0]['items'] ?? null)
            || ! is_array($updatedPhases[1]['items'] ?? null)
            || ! $this->containsPrice($updatedPhases[0]['items'], $premiumPriceId)
            || ! $this->containsPrice($updatedPhases[1]['items'], $premiumPriceId)
            || $this->quantityForPrice($updatedPhases[0]['items'], $addonPriceId) !== max(0, (int) $organization->storage_addon_gib)
            || $this->quantityForPrice($updatedPhases[1]['items'], $addonPriceId) !== $targetQuantity) {
            throw new RuntimeException('Stripe did not confirm the requested storage add-on schedule.');
        }
        $this->assertScheduleBelongsToOrganization($updatedSchedule, $organization, $subscriptionId);

        return CarbonImmutable::createFromTimestamp($updatedCurrentPhase['end_date'], 'UTC');
    }

    /**
     * @param  array<string, mixed>  $price
     */
    private function assertAddonPriceIsValid(array $price, string $expectedPriceId): void
    {
        if (($price['id'] ?? null) !== $expectedPriceId
            || ($price['active'] ?? null) !== true
            || ($price['type'] ?? null) !== 'recurring'
            || ($price['billing_scheme'] ?? null) !== 'per_unit'
            || ($price['currency'] ?? null) !== 'jpy'
            || ($price['unit_amount'] ?? null) !== (int) config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy')
            || ($price['tax_behavior'] ?? null) !== 'exclusive'
            || ($price['recurring']['interval'] ?? null) !== 'month'
            || (int) ($price['recurring']['interval_count'] ?? 0) !== 1) {
            throw new RuntimeException('Stripe storage add-on price configuration is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function assertSubscriptionBelongsToOrganization(array $subscription, Organization $organization, string $premiumPriceId, string $addonPriceId): void
    {
        $customerId = $subscription['customer'] ?? null;
        $status = $subscription['status'] ?? null;
        $metadataOrganizationId = $subscription['metadata']['organization_id'] ?? null;
        $items = $subscription['items']['data'] ?? null;
        if (($subscription['id'] ?? null) !== $organization->stripe_subscription_id
            || $customerId !== $organization->stripe_customer_id
            || $status !== 'active'
            || ($metadataOrganizationId !== null && (string) $metadataOrganizationId !== (string) $organization->getKey())
            || ! is_array($items)
            || ! $this->containsPrice($items, $premiumPriceId)
            || $this->quantityForPrice($items, $addonPriceId) !== max(0, (int) $organization->storage_addon_gib)) {
            throw new RuntimeException('Stripe subscription does not match the organization billing record.');
        }
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function assertScheduleBelongsToOrganization(array $schedule, Organization $organization, string $subscriptionId): void
    {
        if (($schedule['subscription'] ?? null) !== $subscriptionId
            || (string) ($schedule['metadata']['organization_id'] ?? '') !== (string) $organization->getKey()
            || ($schedule['metadata']['purpose'] ?? null) !== 'storage_capacity_addon'
            || ! in_array($schedule['status'] ?? null, ['active', 'not_started'], true)) {
            throw new RuntimeException('Stripe subscription schedule is already managed elsewhere.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function containsPrice(array $items, string $priceId): bool
    {
        return $this->findPriceIndex($items, $priceId) !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function findPriceIndex(array $items, string $priceId): ?int
    {
        foreach ($items as $index => $item) {
            $itemPriceId = $item['price'] ?? null;
            if (is_array($itemPriceId)) {
                $itemPriceId = $itemPriceId['id'] ?? null;
            }
            if ($itemPriceId === $priceId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function quantityForPrice(array $items, string $priceId): int
    {
        $quantity = 0;
        foreach ($items as $item) {
            $itemPriceId = $item['price'] ?? null;
            if (is_array($itemPriceId)) {
                $itemPriceId = $itemPriceId['id'] ?? null;
            }
            if ($itemPriceId === $priceId) {
                $itemQuantity = max(0, (int) ($item['quantity'] ?? 1));
                if ($quantity > PHP_INT_MAX - $itemQuantity) {
                    throw new RuntimeException('Stripe storage add-on quantity is invalid.');
                }
                $quantity += $itemQuantity;
            }
        }

        return $quantity;
    }

    /**
     * @param  array<int, array<string, mixed>>  $currentItems
     * @param  array<int, array<string, mixed>>  $nextItems
     */
    private function sameNonAddonItems(array $currentItems, array $nextItems, string $addonPriceId): bool
    {
        $withoutAddon = static function (array $items) use ($addonPriceId): array {
            $items = array_filter($items, static function (array $item) use ($addonPriceId): bool {
                $price = $item['price'] ?? null;
                if (is_array($price)) {
                    $price = $price['id'] ?? null;
                }

                return $price !== $addonPriceId;
            });
            usort($items, static function (array $left, array $right): int {
                $leftPrice = $left['price']['id'] ?? $left['price'] ?? '';
                $rightPrice = $right['price']['id'] ?? $right['price'] ?? '';

                return strcmp((string) $leftPrice, (string) $rightPrice);
            });

            return array_values($items);
        };

        return $withoutAddon($currentItems) === $withoutAddon($nextItems);
    }

    /**
     * @param  array<string, mixed>  $phase
     * @return array<string, mixed>
     */
    private function editablePhase(array $phase): array
    {
        if (isset($phase['discounts']) && $phase['discounts'] !== []) {
            throw new RuntimeException('Discounted subscription schedules need manual review.');
        }
        if (isset($phase['trial_end']) && $phase['trial_end'] !== null) {
            throw new RuntimeException('Trial subscription schedules need manual review.');
        }
        if (isset($phase['add_invoice_items']) && $phase['add_invoice_items'] !== []) {
            throw new RuntimeException('Subscription schedule invoice items need manual review.');
        }

        $editablePhase = [];
        foreach (['automatic_tax', 'billing_cycle_anchor', 'collection_method', 'default_payment_method', 'default_tax_rates', 'description', 'invoice_settings', 'metadata', 'on_behalf_of', 'proration_behavior', 'transfer_data', 'application_fee_percent'] as $key) {
            if (array_key_exists($key, $phase)) {
                $editablePhase[$key] = $phase[$key];
            }
        }
        $items = $phase['items'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('Stripe subscription schedule phase items are invalid.');
        }
        $editablePhase['items'] = array_map(function (array $item): array {
            $price = $item['price'] ?? null;
            if (is_array($price)) {
                $price = $price['id'] ?? null;
            }
            if (! is_string($price) || $price === '') {
                throw new RuntimeException('Stripe subscription schedule contains an invalid price.');
            }

            if (isset($item['discounts']) && $item['discounts'] !== []) {
                throw new RuntimeException('Discounted subscription items need manual review.');
            }

            $editableItem = ['price' => $price, 'quantity' => max(1, (int) ($item['quantity'] ?? 1))];
            foreach (['metadata', 'tax_rates'] as $key) {
                if (array_key_exists($key, $item)) {
                    $editableItem[$key] = $item[$key];
                }
            }

            return $editableItem;
        }, $items);

        return $editablePhase;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function stripeRequest(string $method, string $path, string $secret, array $parameters = [], array $headers = []): array
    {
        try {
            $request = Http::withBasicAuth($secret, '')
                ->connectTimeout((int) config('services.stripe.connect_timeout_seconds'))
                ->timeout((int) config('services.stripe.timeout_seconds'))
                ->withHeaders($headers);
            $response = $method === 'GET'
                ? $request->get('https://api.stripe.com/v1'.$path, $parameters)
                : $request->asForm()->post('https://api.stripe.com/v1'.$path, $parameters);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Stripe storage add-on request failed.', previous: $exception);
        }

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)) {
            throw new RuntimeException('Stripe storage add-on request was rejected.');
        }

        return $payload;
    }

    /**
     * @param  array<string, bool|int|string>  $parameters
     */
    private function idempotencyKey(string $operation, Organization $organization, array $parameters): string
    {
        return 'opsdeck-'.$operation.'-'.$organization->getKey().'-'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }
}
