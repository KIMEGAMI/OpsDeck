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
     * @return array{renewal_negotiation_jpy: int, renewal_candidate_count: int, renewal_candidates: list<array{contract_id: int, vendor_name: string, contract_name: string, renewal_date: string, annual_cost_jpy: float, estimated_savings_jpy: int}>, unused_saas_jpy: int, unused_saas_candidates: list<array{service_id: int, service_name: string, contract_names: list<string>, annual_cost_jpy: float}>, unused_assets_jpy: int, unused_asset_candidate_count: int, unused_asset_candidates: list<array{asset_id: int, asset_number: string, model: string|null, status: string, annual_cost_jpy: int}>, annual_total_jpy: int, assumptions: array{renewal_rate: float, renewal_rate_source: string, unused_asset_monthly_jpy: int, unused_asset_monthly_jpy_source: string, unused_asset_statuses: list<string>, unconverted_foreign_contracts_by_currency: array<string, int>, warnings: list<string>}}
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
            ->filter(fn (Contract $contract): bool => $contract->status === 'active' && $contract->currency !== 'JPY' && $monthlyCalculator->annualAmountInCents($contract) !== null)
            ->countBy('currency')
            ->all();
        $today = CarbonImmutable::today($organization->timezone);
        $unusedSaasServices = SaasService::query()
            ->where('organization_id', $organization->id)
            ->whereDoesntHave('assignments', fn ($query) => $query->whereNull('ended_at'))
            ->with(['contracts' => fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->whereIn('billing_cycle', ContractMonthlyCostCalculator::RECURRING_BILLING_CYCLES)])
            ->get();
        $unusedSaasContracts = $unusedSaasServices
            ->flatMap(fn (SaasService $service): Collection => $service->contracts)
            ->filter(fn (Contract $contract): bool => $contract->currency === 'JPY');
        $unusedSaasCandidates = $unusedSaasServices
            ->map(function (SaasService $service) use ($monthlyCalculator): ?array {
                $contracts = $service->contracts->filter(fn (Contract $contract): bool => $contract->currency === 'JPY');
                $annualCostInCents = $contracts->sum(fn (Contract $contract): int => $monthlyCalculator->annualAmountInCents($contract) ?? 0);
                if ($annualCostInCents <= 0) {
                    return null;
                }

                return [
                    'service_id' => (int) $service->id,
                    'service_name' => (string) $service->name,
                    'contract_names' => $contracts->pluck('contract_name')->map(static fn (string $name): string => $name)->values()->all(),
                    'annual_cost_jpy' => $monthlyCalculator->currencyUnitsFromCents($annualCostInCents),
                ];
            })
            ->filter(static fn (?array $candidate): bool => $candidate !== null)
            ->values()
            ->all();
        $unusedSaasContractIds = array_fill_keys($unusedSaasContracts->pluck('id')->all(), true);
        $renewalCandidates = $contracts
            ->filter(static fn (Contract $contract): bool => $contract->status === 'active' && $contract->auto_renew)
            ->map(function (Contract $contract) use ($today, $unusedSaasContractIds, $renewalWindow, $monthlyCalculator, $renewalRate): ?array {
                $annualAmountInCents = $monthlyCalculator->annualAmountInCents($contract);
                $renewalDate = $renewalWindow->dateWithin($contract, $today, $today->addDays(ContractRenewalWindow::SAVINGS_WINDOW_DAYS));
                if ($contract->currency !== 'JPY' || isset($unusedSaasContractIds[$contract->id]) || $annualAmountInCents === null || $renewalDate === null) {
                    return null;
                }

                return [
                    'contract_id' => (int) $contract->id,
                    'vendor_name' => (string) $contract->vendor_name,
                    'contract_name' => (string) $contract->contract_name,
                    'renewal_date' => $renewalDate->toDateString(),
                    'annual_cost_jpy' => $monthlyCalculator->currencyUnitsFromCents($annualAmountInCents),
                    'estimated_savings_jpy' => (int) round($monthlyCalculator->currencyUnitsFromCents($annualAmountInCents) * $renewalRate),
                ];
            })
            ->filter(static fn (?array $candidate): bool => $candidate !== null)
            ->sortBy('renewal_date')
            ->values();
        $renewalNegotiationJpy = (int) $renewalCandidates->sum('estimated_savings_jpy');
        $renewalCandidateCount = $renewalCandidates->count();
        $renewalCandidateRows = $renewalCandidates
            ->take(max(1, (int) config('opsdeck.pagination.per_page')))
            ->all();
        $unusedSaasJpy = (int) round($monthlyCalculator->currencyUnitsFromCents($unusedSaasContracts->sum(fn (Contract $contract): int => $monthlyCalculator->annualAmountInCents($contract) ?? 0)));
        $unusedAssetsQuery = Asset::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', array_keys($unusedAssetStatuses))
            ->whereDoesntHave('assignments', fn ($query) => $query->whereNull('returned_at'));
        $unusedAssetCandidateCount = (clone $unusedAssetsQuery)->count();
        $unusedAssetCandidates = (clone $unusedAssetsQuery)
            ->orderBy('asset_number')
            ->limit(max(1, (int) config('opsdeck.pagination.per_page')))
            ->get(['id', 'asset_number', 'model', 'status'])
            ->map(fn (Asset $asset): array => [
                'asset_id' => (int) $asset->id,
                'asset_number' => (string) $asset->asset_number,
                'model' => $asset->model,
                'status' => (string) $asset->status,
                'annual_cost_jpy' => $unusedAssetMonthlyJpy * ContractMonthlyCostCalculator::MONTHS_PER_YEAR,
            ])
            ->all();
        $unusedAssetsJpy = $unusedAssetCandidateCount * $unusedAssetMonthlyJpy * ContractMonthlyCostCalculator::MONTHS_PER_YEAR;

        return [
            'renewal_negotiation_jpy' => $renewalNegotiationJpy,
            'renewal_candidate_count' => $renewalCandidateCount,
            'renewal_candidates' => $renewalCandidateRows,
            'unused_saas_jpy' => $unusedSaasJpy,
            'unused_saas_candidates' => $unusedSaasCandidates,
            'unused_assets_jpy' => $unusedAssetsJpy,
            'unused_asset_candidate_count' => $unusedAssetCandidateCount,
            'unused_asset_candidates' => $unusedAssetCandidates,
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
