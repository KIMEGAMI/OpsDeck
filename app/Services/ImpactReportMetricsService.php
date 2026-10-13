<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Evidence;
use App\Models\ImpactMetricSnapshot;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\QuestionnaireAnswer;
use App\Models\SaasService;
use App\Models\SecurityControl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ImpactReportMetricsService
{
    /** @return array<string, int|float|array<string, float>> */
    public function current(Organization $organization): array
    {
        $today = CarbonImmutable::today($organization->timezone);
        $contracts = Contract::query()->where('organization_id', $organization->id)->whereIn('status', ['active', 'pending_cancel'])->get();
        $monthlyCalculator = app(ContractMonthlyCostCalculator::class);
        $monthlyCostsByCurrency = [];
        foreach ($monthlyCalculator->monthlyTotalsInCents($contracts) as $currency => $monthlyCents) {
            $monthlyCostsByCurrency[$currency] = $monthlyCalculator->currencyUnitsFromCents($monthlyCents);
        }
        ksort($monthlyCostsByCurrency);
        $renewalWindow = app(ContractRenewalWindow::class);

        $metrics = [
            'employees' => Employee::query()->where('organization_id', $organization->id)->count(),
            'assets' => Asset::query()->where('organization_id', $organization->id)->count(),
            'saas_services' => SaasService::query()->where('organization_id', $organization->id)->count(),
            'evidence_expired' => Evidence::query()->where('organization_id', $organization->id)->whereNotNull('valid_until')->whereDate('valid_until', '<', $today)->count(),
            'security_open' => SecurityControl::query()->where('organization_id', $organization->id)->whereIn('status', ['partial', 'not_implemented', 'unknown'])->count(),
            'renewals_30_days' => $contracts->filter(fn (Contract $contract): bool => $renewalWindow->dateWithin($contract, $today, $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS)) !== null)->count(),
            'monthly_costs_by_currency' => $monthlyCostsByCurrency,
            'monthly_jpy' => $monthlyCostsByCurrency['JPY'] ?? 0.0,
            'import_errors' => (int) ImportBatch::query()->where('organization_id', $organization->id)->whereNull('errors_reviewed_at')->where('status', '!=', 'failed')->sum('error_rows'),
            'import_failed_batches' => (int) ImportBatch::query()->where('organization_id', $organization->id)->whereNull('errors_reviewed_at')->where('status', 'failed')->count(),
            'questionnaire_pending' => QuestionnaireAnswer::query()->whereNull('approved_at')->whereHas('question.questionnaire', fn ($query) => $query->where('organization_id', $organization->id))->count(),
            'asset_attention' => Asset::query()->where('organization_id', $organization->id)->whereNotIn('status', config('opsdeck.asset_non_operational_statuses', []))->where(function ($query): void {
                $query->whereDoesntHave('assignments', fn ($assignmentQuery) => $assignmentQuery->whereNull('returned_at'))
                    ->orWhereHas('assignments', fn ($assignmentQuery) => $assignmentQuery->whereNull('returned_at')->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('employment_status', '!=', config('opsdeck.employee_status.active'))));
            })->count(),
            'saas_attention' => SaasService::query()->where('organization_id', $organization->id)->where('status', config('opsdeck.saas_service_active_status'))->where(function ($query): void {
                $query->whereDoesntHave('assignments', fn ($assignmentQuery) => $assignmentQuery->whereNull('ended_at'))
                    ->orWhereHas('assignments', fn ($assignmentQuery) => $assignmentQuery->whereNull('ended_at')->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('employment_status', '!=', config('opsdeck.employee_status.active'))));
            })->count(),
        ];

        $metrics['unresolved_items'] = $metrics['evidence_expired']
            + $metrics['security_open']
            + $metrics['renewals_30_days']
            + $metrics['import_errors']
            + $metrics['import_failed_batches']
            + $metrics['questionnaire_pending']
            + $metrics['asset_attention']
            + $metrics['saas_attention'];

        return $metrics;
    }

    public function capture(Organization $organization, ImportBatch $importBatch, string $stage): ImpactMetricSnapshot
    {
        return ImpactMetricSnapshot::query()->updateOrCreate(
            ['import_batch_id' => $importBatch->id, 'stage' => $stage],
            ['organization_id' => $organization->id, 'metrics_json' => $this->current($organization), 'captured_at' => now()],
        );
    }

    public function captureDaily(Organization $organization): ImpactMetricSnapshot
    {
        $snapshotDate = CarbonImmutable::today($organization->timezone);
        $snapshotAttributes = ['organization_id' => $organization->id, 'stage' => 'daily', 'snapshot_date' => $snapshotDate];
        $existingSnapshot = ImpactMetricSnapshot::query()->where($snapshotAttributes)->first();

        if ($existingSnapshot !== null) {
            return $existingSnapshot;
        }

        return ImpactMetricSnapshot::query()->firstOrCreate(
            $snapshotAttributes,
            ['metrics_json' => $this->current($organization), 'captured_at' => now()],
        );
    }

    public function importComparison(Organization $organization): ?array
    {
        $after = ImpactMetricSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('stage', 'import_after')
            ->whereHas('importBatch', fn ($query) => $query->where('status', 'completed'))
            ->latest('captured_at')
            ->orderByDesc('id')
            ->first();

        if ($after === null) {
            return null;
        }

        $before = ImpactMetricSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('import_batch_id', $after->import_batch_id)
            ->where('stage', 'import_before')
            ->first();

        if ($before === null) {
            return null;
        }

        $beforeCosts = $this->monthlyCostsByCurrency($before);
        $afterCosts = $this->monthlyCostsByCurrency($after);
        $currencies = array_unique(array_merge(array_keys($beforeCosts), array_keys($afterCosts)));
        sort($currencies, SORT_STRING);
        $costComparison = [];

        foreach ($currencies as $currency) {
            $beforeAmount = $beforeCosts[$currency] ?? 0.0;
            $afterAmount = $afterCosts[$currency] ?? 0.0;
            $costComparison[$currency] = [
                'before' => $beforeAmount,
                'after' => $afterAmount,
                'delta' => $afterAmount - $beforeAmount,
            ];
        }

        return ['before' => $before, 'after' => $after, 'monthly_costs_by_currency' => $costComparison];
    }

    /** @return array<string, float> */
    private function monthlyCostsByCurrency(ImpactMetricSnapshot $snapshot): array
    {
        if (array_key_exists('monthly_costs_by_currency', $snapshot->metrics_json)
            && is_array($snapshot->metrics_json['monthly_costs_by_currency'])) {
            $monthlyCosts = [];

            foreach ($snapshot->metrics_json['monthly_costs_by_currency'] as $currency => $amount) {
                if (is_string($currency) && (is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric($amount)))) {
                    $monthlyCosts[$currency] = (float) $amount;
                }
            }

            return $monthlyCosts;
        }

        $legacyJpy = $snapshot->metrics_json['monthly_jpy'] ?? null;
        if (is_int($legacyJpy) || is_float($legacyJpy) || (is_string($legacyJpy) && is_numeric($legacyJpy))) {
            return ['JPY' => (float) $legacyJpy];
        }

        return [];
    }

    /** @return Collection<int, ImpactMetricSnapshot> */
    public function trend(Organization $organization): Collection
    {
        return ImpactMetricSnapshot::query()
            ->where('organization_id', $organization->id)
            ->whereIn('stage', ['daily', 'import_after', 'rollback_after'])
            ->latest('captured_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('opsdeck.pagination.per_page')))
            ->get()
            ->reverse()
            ->values();
    }
}
