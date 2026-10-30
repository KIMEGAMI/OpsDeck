<?php

namespace App\Services;

use App\Models\Organization;

class StorageAddonCapacityService
{
    private const BYTES_PER_GIBIBYTE = 1073741824;

    public function __construct(
        private PlanAccessService $planAccessService,
        private OrganizationStorageQuotaService $storageQuotaService,
    ) {}

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

    public function maximumCheckoutQuantity(?Organization $newPremiumOrganization = null): ?int
    {
        $maximumQuantity = $this->maximumQuantity();
        $platformLimitMegabytes = config('opsdeck.storage_quotas.platform_limit_megabytes');
        $premiumLimitMegabytes = config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code'));
        $bytesPerMegabyte = config('opsdeck.storage_quotas.bytes_per_megabyte');

        if ($maximumQuantity === null
            || ! is_int($platformLimitMegabytes)
            || $platformLimitMegabytes < 1
            || ! is_int($premiumLimitMegabytes)
            || $premiumLimitMegabytes < 1
            || ! is_int($bytesPerMegabyte)
            || $bytesPerMegabyte < 1
            || $platformLimitMegabytes > intdiv(PHP_INT_MAX, $bytesPerMegabyte)
            || $premiumLimitMegabytes > intdiv(PHP_INT_MAX, $bytesPerMegabyte)) {
            return null;
        }

        $basePremiumBytes = $premiumLimitMegabytes * $bytesPerMegabyte;
        $committedCapacityBytes = $this->committedPlatformCapacityBytes($newPremiumOrganization, null, true, $basePremiumBytes);
        if ($committedCapacityBytes === null) {
            return null;
        }

        $platformLimitBytes = $platformLimitMegabytes * $bytesPerMegabyte;
        if ($committedCapacityBytes > $platformLimitBytes) {
            return null;
        }

        return min($maximumQuantity, intdiv($platformLimitBytes - $committedCapacityBytes, self::BYTES_PER_GIBIBYTE));
    }

    public function canStartPremiumSubscriptionWithAddon(int $targetQuantity, ?Organization $newPremiumOrganization = null): bool
    {
        $maximumCheckoutQuantity = $this->maximumCheckoutQuantity($newPremiumOrganization);

        return $maximumCheckoutQuantity !== null
            && $targetQuantity >= 0
            && $targetQuantity <= $maximumCheckoutQuantity;
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
        $premiumLimitMegabytes = (int) config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code'));
        if ($premiumLimitMegabytes < 1 || $premiumLimitMegabytes > intdiv(PHP_INT_MAX, $bytesPerMegabyte)) {
            return false;
        }

        $platformLimitBytes = $platformLimitMegabytes * $bytesPerMegabyte;
        $basePremiumBytes = $premiumLimitMegabytes * $bytesPerMegabyte;
        $committedCapacityBytes = $this->committedPlatformCapacityBytes($targetOrganization, $targetQuantity, false, $basePremiumBytes);

        return $committedCapacityBytes !== null && $committedCapacityBytes <= $platformLimitBytes;
    }

    private function committedPlatformCapacityBytes(?Organization $targetOrganization, ?int $targetAddonQuantity, bool $targetIsNewPremium, int $basePremiumBytes): ?int
    {
        $premiumPlanCode = (string) config('opsdeck.premium_plan_code');
        $now = now();
        $organizations = Organization::query()
            ->where(function ($query) use ($premiumPlanCode, $now): void {
                $query->where('plan_code', $premiumPlanCode)
                    ->orWhere(function ($reservationQuery) use ($now): void {
                        $reservationQuery->whereNotNull('storage_addon_checkout_reserved_gib')
                            ->where('storage_addon_checkout_reservation_expires_at', '>', $now);
                    });
            })
            ->select([
                'id',
                'plan_code',
                'billing_status',
                'billing_past_due_since',
                'stripe_last_event_created_at',
                'storage_addon_gib',
                'storage_addon_pending_gib',
                'storage_addon_checkout_reserved_gib',
                'storage_addon_checkout_reservation_expires_at',
            ])
            ->cursor();
        $committedCapacityBytes = 0;
        $committedUsageBytes = 0;

        foreach ($organizations as $organization) {
            $isTarget = $targetOrganization !== null
                && (int) $organization->getKey() === (int) $targetOrganization->getKey();
            if ($isTarget && $targetIsNewPremium) {
                continue;
            }

            $isPremium = $this->planAccessService->effectivePlanCode($organization) === $premiumPlanCode;
            $hasActiveCheckoutReservation = $organization->storage_addon_checkout_reserved_gib !== null
                && $organization->storage_addon_checkout_reservation_expires_at !== null
                && $organization->storage_addon_checkout_reservation_expires_at->isFuture();
            if (! $isPremium && ! $hasActiveCheckoutReservation) {
                continue;
            }

            $currentQuantity = max(0, (int) $organization->storage_addon_gib);
            $committedQuantity = $isPremium
                ? ($organization->storage_addon_pending_gib === null
                    ? $currentQuantity
                    : max($currentQuantity, (int) $organization->storage_addon_pending_gib))
                : max(0, (int) $organization->storage_addon_checkout_reserved_gib);

            if ($hasActiveCheckoutReservation) {
                $committedQuantity = max($committedQuantity, (int) $organization->storage_addon_checkout_reserved_gib);
            }
            if ($isTarget && $targetAddonQuantity !== null) {
                $committedQuantity = max($currentQuantity, $targetAddonQuantity);
            }
            if ($committedQuantity > intdiv(PHP_INT_MAX - $basePremiumBytes, self::BYTES_PER_GIBIBYTE)) {
                return null;
            }

            $organizationUsedBytes = $this->storageQuotaService->summary($organization)['used_bytes'];
            $organizationCommitmentBytes = max(
                $basePremiumBytes + ($committedQuantity * self::BYTES_PER_GIBIBYTE),
                $organizationUsedBytes,
            );
            if ($organizationCommitmentBytes > PHP_INT_MAX - $committedCapacityBytes
                || $organizationUsedBytes > PHP_INT_MAX - $committedUsageBytes) {
                return null;
            }

            $committedCapacityBytes += $organizationCommitmentBytes;
            $committedUsageBytes += $organizationUsedBytes;
        }

        if ($targetIsNewPremium) {
            $targetUsedBytes = $targetOrganization === null
                ? 0
                : $this->storageQuotaService->summary($targetOrganization)['used_bytes'];
            $targetCommitmentBytes = max($basePremiumBytes, $targetUsedBytes);
            if ($targetCommitmentBytes > PHP_INT_MAX - $committedCapacityBytes
                || $targetUsedBytes > PHP_INT_MAX - $committedUsageBytes) {
                return null;
            }

            $committedCapacityBytes += $targetCommitmentBytes;
            $committedUsageBytes += $targetUsedBytes;
        }

        $platformUsedBytes = $this->storageQuotaService->platformUsageSummary()['used_bytes'];
        if ($committedUsageBytes > $platformUsedBytes) {
            return null;
        }

        $unreservedUsageBytes = $platformUsedBytes - $committedUsageBytes;
        if ($unreservedUsageBytes > PHP_INT_MAX - $committedCapacityBytes) {
            return null;
        }

        return $committedCapacityBytes + $unreservedUsageBytes;
    }
}
