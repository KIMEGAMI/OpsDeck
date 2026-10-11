<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\RealizedSaving;
use Illuminate\Database\Eloquent\Collection;

class RealizedSavingsSummary
{
    /** @return Collection<int, RealizedSaving> */
    public function totalsByCurrencyAndPeriod(Organization $organization): Collection
    {
        return RealizedSaving::query()
            ->where('organization_id', $organization->id)
            ->whereNull('voided_at')
            ->select('currency', 'period')
            ->selectRaw('SUM(amount) AS amount_total')
            ->groupBy('currency', 'period')
            ->orderBy('currency')
            ->orderBy('period')
            ->get();
    }
}
