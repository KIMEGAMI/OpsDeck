<?php

namespace App\Services;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class StorageAddonReconciliationService
{
    private const PAGE_SIZE = 100;

    /** @return array{status: string, organizations_checked: int, mismatch_count: int, unavailable_count: int, mismatch_organization_ids: list<int>, unavailable_organization_ids: list<int>, checked_at: CarbonImmutable} */
    public function reconcile(): array
    {
        $checkedAt = CarbonImmutable::now();
        $mismatchOrganizationIds = [];
        $unavailableOrganizationIds = [];
        $organizationsChecked = 0;
        $secret = config('services.stripe.secret');
        $premiumPriceId = config('services.stripe.prices.premium');
        $addonPriceId = config('services.stripe.prices.storage_addon_gib');
        $isConfigured = is_string($secret) && trim($secret) !== ''
            && is_string($premiumPriceId) && trim($premiumPriceId) !== ''
            && is_string($addonPriceId) && trim($addonPriceId) !== '';

        if (! Schema::hasTable('storage_addon_reconciliation_runs')) {
            throw new \RuntimeException('Storage add-on reconciliation table is missing.');
        }

        if (! $isConfigured) {
            $unavailableOrganizationIds = Organization::query()
                ->where(function ($query): void {
                    $query->where('storage_addon_gib', '>', 0)
                        ->orWhereNotNull('storage_addon_pending_gib');
                })
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            return $this->recordRun($checkedAt, 0, [], $unavailableOrganizationIds, $unavailableOrganizationIds === [] ? 'not_configured' : 'unavailable');
        }

        Organization::query()
            ->whereNotNull('stripe_subscription_id')
            ->where(function ($query): void {
                $query->where(function ($activePremiumQuery): void {
                    $activePremiumQuery
                        ->where('plan_code', config('opsdeck.premium_plan_code'))
                        ->whereIn('billing_status', ['active', 'past_due']);
                })->orWhere('storage_addon_gib', '>', 0)
                    ->orWhereNotNull('storage_addon_pending_gib');
            })
            ->orderBy('id')
            ->chunkById(self::PAGE_SIZE, function ($organizations) use (&$organizationsChecked, &$mismatchOrganizationIds, &$unavailableOrganizationIds, $secret, $premiumPriceId, $addonPriceId): void {
                foreach ($organizations as $organization) {
                    $organizationsChecked++;
                    try {
                        $result = $this->organizationMatchesStripe($organization, (string) $secret, (string) $premiumPriceId, (string) $addonPriceId);
                    } catch (Throwable) {
                        $result = 'unavailable';
                    }

                    if ($result === 'mismatch') {
                        $mismatchOrganizationIds[] = (int) $organization->getKey();
                    } elseif ($result === 'unavailable') {
                        $unavailableOrganizationIds[] = (int) $organization->getKey();
                    }
                }
            });

        $status = match (true) {
            $unavailableOrganizationIds !== [] => 'unavailable',
            $mismatchOrganizationIds !== [] => 'mismatch',
            default => 'verified',
        };

        return $this->recordRun($checkedAt, $organizationsChecked, $mismatchOrganizationIds, $unavailableOrganizationIds, $status);
    }

    /** @return array{status: string, organizations_checked: int, mismatch_count: int, unavailable_count: int, mismatch_organization_ids: list<int>, unavailable_organization_ids: list<int>, checked_at: CarbonImmutable} */
    private function recordRun(CarbonImmutable $checkedAt, int $organizationsChecked, array $mismatchOrganizationIds, array $unavailableOrganizationIds, string $status): array
    {
        $result = [
            'status' => $status,
            'organizations_checked' => $organizationsChecked,
            'mismatch_count' => count($mismatchOrganizationIds),
            'unavailable_count' => count($unavailableOrganizationIds),
            'mismatch_organization_ids' => $mismatchOrganizationIds,
            'unavailable_organization_ids' => $unavailableOrganizationIds,
            'checked_at' => $checkedAt,
        ];

        DB::table('storage_addon_reconciliation_runs')->insert([
            'status' => $status,
            'organizations_checked' => $organizationsChecked,
            'mismatch_count' => count($mismatchOrganizationIds),
            'unavailable_count' => count($unavailableOrganizationIds),
            'mismatch_organization_ids' => json_encode($mismatchOrganizationIds, JSON_THROW_ON_ERROR),
            'unavailable_organization_ids' => json_encode($unavailableOrganizationIds, JSON_THROW_ON_ERROR),
            'checked_at' => $checkedAt,
            'created_at' => $checkedAt,
            'updated_at' => $checkedAt,
        ]);

        return $result;
    }

    private function organizationMatchesStripe(Organization $organization, string $secret, string $premiumPriceId, string $addonPriceId): string
    {
        $subscription = $this->stripeGet($secret, '/subscriptions/'.rawurlencode((string) $organization->stripe_subscription_id));
        if (($subscription['id'] ?? null) !== $organization->stripe_subscription_id
            || ($subscription['customer'] ?? null) !== $organization->stripe_customer_id
            || (string) ($subscription['metadata']['organization_id'] ?? '') !== (string) $organization->getKey()) {
            return 'mismatch';
        }

        $status = $subscription['status'] ?? null;
        $stripeIsBillable = in_array($status, ['active', 'trialing', 'past_due', 'unpaid'], true);
        $localIsBillable = in_array($organization->billing_status, ['active', 'past_due'], true);
        if ($stripeIsBillable !== $localIsBillable) {
            return 'mismatch';
        }

        $items = $subscription['items']['data'] ?? null;
        if (! is_array($items)) {
            return 'unavailable';
        }
        $actualAddonQuantity = $this->quantityForPrice($items, $addonPriceId);
        if ($actualAddonQuantity === null) {
            return 'unavailable';
        }
        $actualEntitledQuantity = $stripeIsBillable ? $actualAddonQuantity : 0;
        if ($actualEntitledQuantity !== max(0, (int) $organization->storage_addon_gib)) {
            return 'mismatch';
        }
        if ($stripeIsBillable && ! $this->containsPrice($items, $premiumPriceId)) {
            return 'mismatch';
        }

        $scheduleId = $subscription['schedule'] ?? null;
        if (is_array($scheduleId)) {
            $scheduleId = $scheduleId['id'] ?? null;
        }
        if ($organization->storage_addon_pending_gib === null) {
            if (! is_string($scheduleId) || $scheduleId === '') {
                return 'verified';
            }

            $schedule = $this->stripeGet($secret, '/subscription_schedules/'.rawurlencode($scheduleId));
            if (($schedule['metadata']['purpose'] ?? null) !== 'storage_capacity_addon') {
                return 'verified';
            }
            if (($schedule['subscription'] ?? null) !== $organization->stripe_subscription_id
                || (string) ($schedule['metadata']['organization_id'] ?? '') !== (string) $organization->getKey()) {
                return 'mismatch';
            }
            $currentPhase = $schedule['current_phase'] ?? null;
            $phases = $schedule['phases'] ?? null;
            if (! is_array($currentPhase) || ! is_int($currentPhase['end_date'] ?? null) || ! is_array($phases)) {
                return 'unavailable';
            }
            foreach ($phases as $phase) {
                if (is_array($phase) && ($phase['start_date'] ?? null) === $currentPhase['end_date']) {
                    $phaseItems = $phase['items'] ?? null;

                    return is_array($phaseItems) && $this->quantityForPrice($phaseItems, $addonPriceId) === $actualEntitledQuantity
                        ? 'verified'
                        : 'mismatch';
                }
            }

            return 'verified';
        }

        if (! is_string($scheduleId) || $scheduleId === '') {
            return 'mismatch';
        }
        $schedule = $this->stripeGet($secret, '/subscription_schedules/'.rawurlencode($scheduleId));
        if (($schedule['subscription'] ?? null) !== $organization->stripe_subscription_id
            || (string) ($schedule['metadata']['organization_id'] ?? '') !== (string) $organization->getKey()
            || ($schedule['metadata']['purpose'] ?? null) !== 'storage_capacity_addon') {
            return 'mismatch';
        }
        $currentPhase = $schedule['current_phase'] ?? null;
        $phases = $schedule['phases'] ?? null;
        $effectiveAt = $organization->storage_addon_effective_at;
        if (! is_array($currentPhase) || ! is_int($currentPhase['end_date'] ?? null) || ! is_array($phases) || $effectiveAt === null) {
            return 'unavailable';
        }
        if ($effectiveAt->timestamp !== $currentPhase['end_date']) {
            return 'mismatch';
        }
        foreach ($phases as $phase) {
            if (! is_array($phase) || ($phase['start_date'] ?? null) !== $currentPhase['end_date']) {
                continue;
            }
            $phaseItems = $phase['items'] ?? null;

            return is_array($phaseItems) && $this->quantityForPrice($phaseItems, $addonPriceId) === max(0, (int) $organization->storage_addon_pending_gib)
                ? 'verified'
                : 'mismatch';
        }

        return 'mismatch';
    }

    /** @param array<string, mixed> $subscription */
    private function stripeGet(string $secret, string $path): array
    {
        try {
            $response = Http::withBasicAuth($secret, '')
                ->connectTimeout((int) config('services.stripe.connect_timeout_seconds'))
                ->timeout((int) config('services.stripe.timeout_seconds'))
                ->get('https://api.stripe.com/v1'.$path);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('Stripe reconciliation request failed.', previous: $exception);
        }
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)) {
            throw new \RuntimeException('Stripe reconciliation request was rejected.');
        }

        return $payload;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function quantityForPrice(array $items, string $priceId): ?int
    {
        $quantity = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                return null;
            }
            $itemPriceId = $item['price'] ?? null;
            if (is_array($itemPriceId)) {
                $itemPriceId = $itemPriceId['id'] ?? null;
            }
            if ($itemPriceId !== $priceId) {
                continue;
            }
            $itemQuantity = $item['quantity'] ?? null;
            if (! is_int($itemQuantity) || $itemQuantity < 0 || $quantity > PHP_INT_MAX - $itemQuantity) {
                return null;
            }
            $quantity += $itemQuantity;
        }

        return $quantity;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function containsPrice(array $items, string $priceId): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }
            $itemPriceId = $item['price'] ?? null;
            if (is_array($itemPriceId)) {
                $itemPriceId = $itemPriceId['id'] ?? null;
            }
            if ($itemPriceId === $priceId) {
                return true;
            }
        }

        return false;
    }
}
