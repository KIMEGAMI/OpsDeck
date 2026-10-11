<?php

namespace App\Services;

use App\Models\Organization;
use Carbon\CarbonImmutable;

class PlanAccessService
{
    public function effectivePlanCode(Organization $organization): string
    {
        if ($organization->plan_code !== config('opsdeck.premium_plan_code')) {
            return $organization->plan_code;
        }

        if ($organization->billing_status === 'cancelled') {
            return config('opsdeck.free_plan_code');
        }

        return $this->pastDueGraceExpired($organization)
            ? config('opsdeck.free_plan_code')
            : $organization->plan_code;
    }

    public function pastDueGraceExpired(Organization $organization): bool
    {
        if ($organization->plan_code !== config('opsdeck.premium_plan_code') || $organization->billing_status !== 'past_due') {
            return false;
        }

        $pastDueSince = $organization->billing_past_due_since;
        if ($pastDueSince === null && $organization->stripe_last_event_created_at !== null) {
            $pastDueSince = CarbonImmutable::createFromTimestamp($organization->stripe_last_event_created_at);
        }
        if ($pastDueSince === null) {
            return false;
        }

        $graceDays = max(0, (int) config('opsdeck.billing.past_due_grace_days'));

        return $pastDueSince->lessThanOrEqualTo(now()->subDays($graceDays));
    }
}
