<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\RealizedSaving;
use App\Services\ContractMonthlyCostCalculator;
use App\Services\ContractRenewalWindow;
use App\Services\CostSavingsEstimator;
use App\Services\PlanFeatureService;
use App\Services\RealizedSavingsSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CostDashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, RealizedSavingsSummary $realizedSavingsSummary): View
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('view', $organization);
        $planFeatureService = app(PlanFeatureService::class);
        $contracts = Contract::query()->where('organization_id', $organization->id)->whereIn('status', ['active', 'pending_cancel'])->orderBy('contract_name')->get();
        $availableContracts = Contract::query()->where('organization_id', $organization->id)->orderBy('contract_name')->get();
        $monthlyCosts = app(ContractMonthlyCostCalculator::class)->monthlyTotalsInCents($contracts);
        $today = CarbonImmutable::today($organization->timezone);
        $renewalWindow = app(ContractRenewalWindow::class);
        $renewalAlertsAvailable = $planFeatureService->isAvailable($organization, 'renewal_alerts');
        $savingsOpportunitiesAvailable = $planFeatureService->isAvailable($organization, 'savings_opportunities');
        $realizedSavingsAvailable = $planFeatureService->isAvailable($organization, 'realized_savings');
        $renewals = $renewalAlertsAvailable
            ? $contracts->map(fn (Contract $contract): array => [
                'contract' => $contract,
                'date' => $renewalWindow->dateWithin($contract, $today, $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS)),
            ])->filter(fn (array $renewal): bool => $renewal['date'] !== null)
                ->sortBy(fn (array $renewal) => $renewal['date'])
            : collect();

        $savingsEstimate = $savingsOpportunitiesAvailable
            ? app(CostSavingsEstimator::class)->estimate($organization, $contracts)
            : null;
        $realizedSavingsTotals = $realizedSavingsAvailable
            ? $realizedSavingsSummary->totalsByCurrencyAndPeriod($organization)
            : collect();
        $realizedSavings = $realizedSavingsAvailable
            ? RealizedSaving::query()
                ->with(['contract', 'creator', 'voidingUser', 'evidence'])
                ->where('organization_id', $organization->id)
                ->latest('realized_on')
                ->latest('id')
                ->paginate(config('opsdeck.pagination.per_page'))
            : collect();
        $evidenceAvailable = $planFeatureService->isAvailable($organization, 'evidence');
        $availableEvidence = $evidenceAvailable
            ? Evidence::query()
                ->where('organization_id', $organization->id)
                ->where('storage_path', '<>', 'metadata-only')
                ->orderBy('title')
                ->get(['id', 'title'])
            : collect();

        return view('costs.dashboard', compact('organization', 'monthlyCosts', 'renewals', 'savingsEstimate', 'availableContracts', 'realizedSavingsTotals', 'realizedSavings', 'evidenceAvailable', 'availableEvidence', 'renewalAlertsAvailable', 'savingsOpportunitiesAvailable', 'realizedSavingsAvailable'));
    }
}
