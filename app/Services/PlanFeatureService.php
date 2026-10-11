<?php

namespace App\Services;

use App\Models\Organization;

class PlanFeatureService
{
    public function __construct(private PlanAccessService $planAccessService) {}

    public function isAvailable(Organization $organization, string $feature): bool
    {
        $planCode = $this->planAccessService->effectivePlanCode($organization);
        $legacyPlan = $planCode === config('opsdeck.legacy_plan_code');

        return (bool) config("opsdeck.plans.{$planCode}.capabilities.{$feature}", $legacyPlan);
    }

    public function assertAvailable(Organization $organization, string $feature, string $message): void
    {
        abort_unless($this->isAvailable($organization, $feature), 403, $message);
    }
}
