<?php

namespace App\Services;

use App\Models\Organization;

class StorageAddonCapacityService
{
    private const BYTES_PER_GIBIBYTE = 1073741824;

    public function __construct(private PlanAccessService $planAccessService) {}

    public function maximumQuantity(): ?int
    {
        if (config('opsdeck.release_features.storage_addon') !== true) {
            return null;
        }

        $maximumQuantity = config('opsdeck.storage_quotas.storage_addon_max_gib');
        if (! is_int($maximumQuantity) || $maximumQuantity < 1) {
            return null;
        }

        return min($maximumQuantity, intdiv(PHP_INT_MAX, self::BYTES_PER_GIBIBYTE));
    }

    public function isConfigured(): bool
    {
        $platformLimitMegabytes = config('opsdeck.storage_quotas.platform_limit_megabytes');

        return $this->maximumQuantity() !== null
            && is_int($platformLimitMegabytes)
            && $platformLimitMegabytes > 0;
    }

    public function canSchedule(Organization $targetOrganization, int $targetQuantity): bool
    {
        $maximumQuantity = $this->maximumQuantity();
        $platformLimitMegabytes = config('opsdeck.storage_quotas.platform_limit_megabytes');
        if (! $this->isConfigured()
            || $maximumQuantity === null
            || $targetQuantity < 0
            || $targetQuantity > $maximumQuantity
            || ! is_int($platformLimitMegabytes)) {
            return false;
        }

        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $basePremiumBytes = (int) config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code')) * $bytesPerMegabyte;
        $platformLimitBytes = $platformLimitMegabytes * $bytesPerMegabyte;
        $reservedBytes = 0;

        $organizations = Organization::query()
            ->where('plan_code', config('opsdeck.premium_plan_code'))
            ->select([
                'id',
                'plan_code',
                'billing_status',
                'billing_past_due_since',
                'stripe_last_event_created_at',
                'storage_addon_gib',
                'storage_addon_pending_gib',
            ])
            ->cursor();

        foreach ($organizations as $organization) {
            if ($this->planAccessService->effectivePlanCode($organization) !== config('opsdeck.premium_plan_code')) {
                continue;
            }

            $currentQuantity = max(0, (int) $organization->storage_addon_gib);
            $committedQuantity = $organization->storage_addon_pending_gib === null
                ? $currentQuantity
                : max($currentQuantity, (int) $organization->storage_addon_pending_gib);

            if ((int) $organization->getKey() === (int) $targetOrganization->getKey()) {
                $committedQuantity = max($currentQuantity, $targetQuantity);
            }

            $organizationReservation = $basePremiumBytes + ($committedQuantity * self::BYTES_PER_GIBIBYTE);
            if ($organizationReservation > PHP_INT_MAX - $reservedBytes) {
                return false;
            }

            $reservedBytes += $organizationReservation;
        }

        return $reservedBytes <= $platformLimitBytes;
    }
}
