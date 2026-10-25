<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\WorkTimeMeasurement;

class WorkTimeMeasurementSummary
{
    /** @return array{monthly_minutes: int, monthly_hours: float, monthly_labor_equivalent_by_currency: array<string, float>} */
    public function forOrganization(Organization $organization): array
    {
        $measurements = WorkTimeMeasurement::query()
            ->where('organization_id', $organization->id)
            ->whereNull('voided_at');
        $monthlyMinutes = (int) $measurements
            ->selectRaw('COALESCE(SUM((CAST(before_minutes AS SIGNED) - CAST(after_minutes AS SIGNED)) * CAST(monthly_occurrences AS SIGNED)), 0) AS total_minutes')
            ->value('total_minutes');

        $equivalentByCurrency = WorkTimeMeasurement::query()
            ->where('organization_id', $organization->id)
            ->whereNull('voided_at')
            ->whereNotNull('hourly_rate')
            ->select('currency')
            ->selectRaw('SUM((CAST(before_minutes AS SIGNED) - CAST(after_minutes AS SIGNED)) * CAST(monthly_occurrences AS SIGNED) * hourly_rate / ?) AS monthly_equivalent', [config('opsdeck.work_time.minutes_per_hour')])
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->mapWithKeys(fn (WorkTimeMeasurement $measurement): array => [$measurement->currency => round((float) $measurement->monthly_equivalent, (int) config('opsdeck.work_time.decimal_places'))])
            ->all();

        return [
            'monthly_minutes' => $monthlyMinutes,
            'monthly_hours' => round($monthlyMinutes / (int) config('opsdeck.work_time.minutes_per_hour'), (int) config('opsdeck.work_time.decimal_places')),
            'monthly_labor_equivalent_by_currency' => $equivalentByCurrency,
        ];
    }
}
