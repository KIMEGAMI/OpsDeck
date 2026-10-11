<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\SaasService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CostSavingsEstimator
{
    /**
     * @return array{renewal_negotiation_jpy: int, unused_saas_jpy: int, unused_assets_jpy: int, annual_total_jpy: int, assumptions: array{renewal_rate: float, renewal_rate_source: string, unused_asset_monthly_jpy: int, unused_asset_monthly_jpy_source: string, unused_asset_statuses: list<string>, unconverted_foreign_contracts_by_currency: array<string, int>, warnings: list<string>}}
     */
    public function estimate(Organization $organization, Collection $contracts): array
    {
        $renewalRateSource = $organization->cost_savings_renewal_rate_percent === null ? 'default' : 'organization';
        $configuredRenewalRate = $organization->cost_savings_renewal_rate_percent === null
            ? (float) config('opsdeck.cost_savings.renewal_negotiation_rate')
            : (float) $organization->cost_savings_renewal_rate_percent / 100;
        $unusedAssetMonthlyJpySource = $organization->cost_savings_unused_asset_monthly_jpy === null ? 'default' : 'organization';
        $configuredUnusedAssetMonthlyJpy = $organization->cost_savings_unused_asset_monthly_jpy
            ?? (int) config('opsdeck.cost_savings.unused_asset_monthly_jpy');
        $assumptionWarnings = [];
        if (! is_finite($configuredRenewalRate) || $configuredRenewalRate < 0 || $configuredRenewalRate > 1) {
            $assumptionWarnings[] = ($renewalRateSource === 'organization' ? '会社別の' : '').'更新見直し率の設定が0〜100%の範囲外のため、更新時の見直し余地を0円として算出しました。';
        }
        if ($configuredUnusedAssetMonthlyJpy < 0) {
            $assumptionWarnings[] = ($unusedAssetMonthlyJpySource === 'organization' ? '会社別の' : '').'未使用端末の月額設定が負数のため、端末の見直し余地を0円として算出しました。';
        }

        $renewalRate = is_finite($configuredRenewalRate) && $configuredRenewalRate >= 0 && $configuredRenewalRate <= 1
            ? $configuredRenewalRate
            : 0.0;
        $unusedAssetMonthlyJpy = max(0, $configuredUnusedAssetMonthlyJpy);
        $unusedAssetStatuses = config('opsdeck.cost_savings.unused_asset_statuses', []);
        $unusedAssetStatuses = is_array($unusedAssetStatuses) ? $unusedAssetStatuses : [];
        $monthlyCalculator = app(ContractMonthlyCostCalculator::class);
        $renewalWindow = app(ContractRenewalWindow::class);
        $unconvertedForeignContractsByCurrency = $contracts
            ->filter(fn (Contract $contract): bool => $contract->currency !== 'JPY' && $monthlyCalculator->annualAmountInCents($contract) !== null)
            ->countBy('currency')
            ->all();
        $today = CarbonImmutable::today($organization->timezone);
        $unusedSaasContracts = SaasService::query()
            ->where('organization_id', $organization->id)
            ->whereDoesntHave('assignments', fn ($query) => $query->whereNull('ended_at'))
            ->with(['contracts' => fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->whereIn('status', ['active', 'pending_cancel'])
                ->whereIn('billing_cycle', ContractMonthlyCostCalculator::RECURRING_BILLING_CYCLES)])
            ->get()
            ->flatMap(fn (SaasService $service): Collection => $service->contracts)
            ->filter(fn (Contract $contract): bool => $contract->currency === 'JPY');
        $unusedSaasContractIds = array_fill_keys($unusedSaasContracts->pluck('id')->all(), true);
        $renewalNegotiationJpy = $contracts->filter(function (Contract $contract) use ($today, $unusedSaasContractIds, $renewalWindow, $monthlyCalculator): bool {
            return $contract->currency === 'JPY'
                && ! isset($unusedSaasContractIds[$contract->id])
                && $monthlyCalculator->annualAmountInCents($contract) !== null
                && $renewalWindow->dateWithin($contract, $today, $today->addDays(ContractRenewalWindow::SAVINGS_WINDOW_DAYS)) !== null;
        })->sum(fn (Contract $contract): int => (int) round($monthlyCalculator->currencyUnitsFromCents(($monthlyCalculator->annualAmountInCents($contract) ?? 0)) * $renewalRate));
        $unusedSaasJpy = (int) round($monthlyCalculator->currencyUnitsFromCents($unusedSaasContracts->sum(fn (Contract $contract): int => $monthlyCalculator->annualAmountInCents($contract) ?? 0)));
        $unusedAssets = Asset::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', array_keys($unusedAssetStatuses))
            ->whereDoesntHave('assignments', fn ($query) => $query->whereNull('returned_at'))
            ->count();
        $unusedAssetsJpy = $unusedAssets * $unusedAssetMonthlyJpy * ContractMonthlyCostCalculator::MONTHS_PER_YEAR;

        return [
            'renewal_negotiation_jpy' => $renewalNegotiationJpy,
            'unused_saas_jpy' => $unusedSaasJpy,
            'unused_assets_jpy' => $unusedAssetsJpy,
            'annual_total_jpy' => $renewalNegotiationJpy + $unusedSaasJpy + $unusedAssetsJpy,
            'assumptions' => [
                'renewal_rate' => $renewalRate,
                'renewal_rate_source' => $renewalRateSource,
                'unused_asset_monthly_jpy' => $unusedAssetMonthlyJpy,
                'unused_asset_monthly_jpy_source' => $unusedAssetMonthlyJpySource,
                'unused_asset_statuses' => array_values($unusedAssetStatuses),
                'unconverted_foreign_contracts_by_currency' => $unconvertedForeignContractsByCurrency,
                'warnings' => $assumptionWarnings,
            ],
        ];
    }
}
