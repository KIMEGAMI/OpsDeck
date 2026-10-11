<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\QuestionnaireAnswer;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Services\ContractMonthlyCostCalculator;
use App\Services\ContractRenewalWindow;
use App\Services\PlanFeatureService;
use App\Services\PlanUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        $metrics = [
            'employees' => Employee::query()->where('organization_id', $organization->id)->count(),
            'assets' => Asset::query()->where('organization_id', $organization->id)->count(),
            'saasServices' => SaasService::query()->where('organization_id', $organization->id)->count(),
        ];
        $contracts = Contract::query()->where('organization_id', $organization->id)->whereIn('status', ['active', 'pending_cancel'])->get();
        $monthlyCalculator = app(ContractMonthlyCostCalculator::class);
        $monthlyCents = $monthlyCalculator->monthlyTotalInCents($contracts, 'JPY');
        $today = CarbonImmutable::today($organization->timezone);
        $renewalWindow = app(ContractRenewalWindow::class);
        $renewalAlerts = $contracts->filter(function (Contract $contract) use ($today, $renewalWindow): bool {
            return $renewalWindow->dateOnOrBefore($contract, $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS)) !== null;
        })->count();
        $metrics['contracts'] = $contracts->count();
        $metrics['monthlyJpy'] = $monthlyCalculator->currencyUnitsFromCents($monthlyCents);
        $planFeatures = [
            'renewal_alerts' => app(PlanFeatureService::class)->isAvailable($organization, 'renewal_alerts'),
            'security_controls' => app(PlanFeatureService::class)->isAvailable($organization, 'security_controls'),
            'evidence' => app(PlanFeatureService::class)->isAvailable($organization, 'evidence'),
            'questionnaires' => app(PlanFeatureService::class)->isAvailable($organization, 'questionnaires'),
            'impact_report' => app(PlanFeatureService::class)->isAvailable($organization, 'impact_report'),
        ];
        $metrics['renewalAlerts'] = $planFeatures['renewal_alerts'] ? $renewalAlerts : 0;
        $metrics['securityOpen'] = $planFeatures['security_controls'] ? SecurityControl::query()->where('organization_id', $organization->id)->whereIn('status', ['partial', 'not_implemented', 'unknown'])->count() : 0;
        $metrics['evidenceExpired'] = $planFeatures['evidence'] ? Evidence::query()->where('organization_id', $organization->id)->whereNotNull('valid_until')->whereDate('valid_until', '<', $today)->count() : 0;
        $metrics['importErrors'] = (int) ImportBatch::query()->where('organization_id', $organization->id)->whereNull('errors_reviewed_at')->where('status', '!=', 'failed')->sum('error_rows');
        $metrics['importFailedBatches'] = (int) ImportBatch::query()->where('organization_id', $organization->id)->whereNull('errors_reviewed_at')->where('status', 'failed')->count();
        $metrics['questionnairePending'] = $planFeatures['questionnaires'] ? QuestionnaireAnswer::query()->where('answer_status', '!=', 'approved')->whereHas('question.questionnaire', fn ($query) => $query->where('organization_id', $organization->id))->count() : 0;
        $metrics['assetAttention'] = Asset::query()->where('organization_id', $organization->id)->whereNotIn('status', config('opsdeck.asset_non_operational_statuses', []))->where(function ($query): void {
            $query->whereDoesntHave('assignments', fn ($assignmentQuery) => $assignmentQuery->whereNull('returned_at'))
                ->orWhereHas('assignments', fn ($assignmentQuery) => $assignmentQuery->whereNull('returned_at')->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('employment_status', '!=', config('opsdeck.employee_status.active'))));
        })->count();
        $metrics['saasAttention'] = SaasService::query()->where('organization_id', $organization->id)->where('status', config('opsdeck.saas_service_active_status'))->where(function ($query): void {
            $query->whereDoesntHave('assignments', fn ($assignmentQuery) => $assignmentQuery->whereNull('ended_at'))
                ->orWhereHas('assignments', fn ($assignmentQuery) => $assignmentQuery->whereNull('ended_at')->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('employment_status', '!=', config('opsdeck.employee_status.active'))));
        })->count();
        $metrics['limitWarnings'] = array_values(array_filter(app(PlanUsageService::class)->forOrganization($organization), fn (array $usage): bool => $usage['status'] !== '通常'));
        $userId = $request->user()->id;
        $announcements = Announcement::query()->whereNotNull('published_at')->where('published_at', '<=', now())->where(function ($query) use ($organization): void {
            $query->whereDoesntHave('organizations')->orWhereHas('organizations', fn ($organizationQuery) => $organizationQuery->whereKey($organization->id));
        })->with(['reads' => fn ($query) => $query->where('user_id', $userId)])->latest('published_at')->limit(config('opsdeck.pagination.per_page'))->get();

        return view('dashboard', compact('organization', 'metrics', 'announcements', 'planFeatures'));
    }
}
