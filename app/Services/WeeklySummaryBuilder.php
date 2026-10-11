<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\SaasAssignment;
use App\Models\SecurityControl;
use Carbon\CarbonImmutable;

class WeeklySummaryBuilder
{
    /** @return array<string, int|string|bool> */
    public function build(int $organizationId): array
    {
        $organization = Organization::query()->findOrFail($organizationId);
        $periodEnd = CarbonImmutable::now($organization->timezone);
        $periodStart = $periodEnd->startOfDay()->subDays((int) config('opsdeck.weekly_summary_period_days') - 1);
        $periodEndExclusive = $periodEnd->startOfDay()->addDay();
        $periodStartUtc = $periodStart->utc()->format('Y-m-d H:i:s');
        $periodEndExclusiveUtc = $periodEndExclusive->utc()->format('Y-m-d H:i:s');
        $today = CarbonImmutable::today($organization->timezone);
        $renewalEnd = $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS);
        $planFeatureService = app(PlanFeatureService::class);
        $renewalAlertsEnabled = $planFeatureService->isAvailable($organization, 'renewal_alerts');
        $securityAlertsEnabled = $planFeatureService->isAvailable($organization, 'security_controls');

        return [
            'as_of' => $periodEnd->format('Y-m-d H:i'),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'import_errors' => (int) ImportBatch::query()->where('organization_id', $organizationId)->where('created_at', '>=', $periodStartUtc)->where('created_at', '<', $periodEndExclusiveUtc)->sum('error_rows'),
            'failed_import_batches' => (int) ImportBatch::query()->where('organization_id', $organizationId)->where('status', 'failed')->where('created_at', '>=', $periodStartUtc)->where('created_at', '<', $periodEndExclusiveUtc)->count(),
            'renewal_alerts_enabled' => $renewalAlertsEnabled,
            'renewals' => $renewalAlertsEnabled ? Contract::query()->where('organization_id', $organizationId)->whereIn('status', ['active', 'pending_cancel'])->where(function ($query) use ($today, $renewalEnd): void {
                $query->whereBetween('renewal_date', [$today->toDateString(), $renewalEnd->toDateString()])->orWhereBetween('ends_at', [$today->toDateString(), $renewalEnd->toDateString()]);
            })->count() : 0,
            'security_alerts_enabled' => $securityAlertsEnabled,
            'security_open' => $securityAlertsEnabled ? SecurityControl::query()->where('organization_id', $organizationId)->whereIn('status', ['partial', 'not_implemented', 'unknown'])->count() : 0,
            'mfa_unknown' => $securityAlertsEnabled ? SaasAssignment::query()->where('organization_id', $organizationId)->whereNull('ended_at')->where('mfa_status', 'unknown')->count() : 0,
        ];
    }
}
