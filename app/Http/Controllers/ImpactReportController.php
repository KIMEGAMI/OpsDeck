<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\CsvFormulaSanitizer;
use App\Services\ImpactReportMetricsService;
use App\Services\RealizedSavingsSummary;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImpactReportController extends Controller
{
    public function show(Request $request, ImpactReportMetricsService $metricsService, RealizedSavingsSummary $realizedSavingsSummary): View
    {
        $organization = $this->organization($request);
        $metrics = $metricsService->current($organization);
        $comparison = $metricsService->importComparison($organization);
        $trend = $metricsService->trend($organization);
        $realizedSavingsTotals = $realizedSavingsSummary->totalsByCurrencyAndPeriod($organization);

        return view('impact-reports.show', compact('organization', 'metrics', 'comparison', 'trend', 'realizedSavingsTotals'));
    }

    public function download(Request $request, ImpactReportMetricsService $metricsService, RealizedSavingsSummary $realizedSavingsSummary): StreamedResponse
    {
        $organization = $this->organization($request);
        $this->authorize('export', $organization);
        $metrics = $metricsService->current($organization);
        $comparison = $metricsService->importComparison($organization);
        $trend = $metricsService->trend($organization);
        $realizedSavingsTotals = $realizedSavingsSummary->totalsByCurrencyAndPeriod($organization);
        $csvFormulaSanitizer = app(CsvFormulaSanitizer::class);

        return response()->streamDownload(static function () use ($metrics, $comparison, $trend, $realizedSavingsTotals, $csvFormulaSanitizer): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['metric', 'value', 'source'], ',', '"', '');
            foreach ($metrics as $metric => $value) {
                if ($metric === 'monthly_costs_by_currency' && is_array($value)) {
                    foreach ($value as $currency => $monthlyCost) {
                        if (! is_string($currency) || (! is_int($monthlyCost) && ! is_float($monthlyCost))) {
                            continue;
                        }

                        fputcsv($handle, [
                            $csvFormulaSanitizer->sanitize('monthly_cost_'.$currency),
                            number_format((float) $monthlyCost, 2, '.', ''),
                            'OpsDeck current contract data; grouped by currency, not converted',
                        ], ',', '"', '');
                    }

                    continue;
                }

                fputcsv($handle, [$metric, $value, 'OpsDeck current data'], ',', '"', '');
            }
            if ($comparison !== null) {
                foreach (['employees', 'assets', 'saas_services', 'unresolved_items'] as $metric) {
                    $before = $comparison['before']->metrics_json[$metric] ?? 0;
                    $after = $comparison['after']->metrics_json[$metric] ?? 0;
                    fputcsv($handle, ['latest_import_before_'.$metric, $before, 'Import batch '.$comparison['after']->import_batch_id], ',', '"', '');
                    fputcsv($handle, ['latest_import_after_'.$metric, $after, 'Import batch '.$comparison['after']->import_batch_id], ',', '"', '');
                    fputcsv($handle, ['latest_import_delta_'.$metric, $after - $before, 'Import batch '.$comparison['after']->import_batch_id], ',', '"', '');
                }

                foreach ($comparison['monthly_costs_by_currency'] as $currency => $costs) {
                    $metric = $csvFormulaSanitizer->sanitize('latest_import_monthly_cost_'.$currency);
                    $batchSource = 'Import batch '.$comparison['after']->import_batch_id;
                    fputcsv($handle, [$metric.'_before', number_format($costs['before'], 2, '.', ''), $batchSource], ',', '"', '');
                    fputcsv($handle, [$metric.'_after', number_format($costs['after'], 2, '.', ''), $batchSource], ',', '"', '');
                    fputcsv($handle, [$metric.'_delta', number_format($costs['delta'], 2, '.', ''), $batchSource], ',', '"', '');
                }
            }
            foreach ($trend as $snapshot) {
                fputcsv($handle, ['trend_'.$snapshot->stage.'_unresolved_items', $snapshot->metrics_json['unresolved_items'] ?? 0, $snapshot->captured_at->toIso8601String()], ',', '"', '');
            }
            foreach ($realizedSavingsTotals as $savingTotal) {
                fputcsv($handle, [
                    'realized_savings_'.$savingTotal->currency.'_'.$savingTotal->period,
                    number_format((float) $savingTotal->amount_total, 2, '.', ''),
                    'Recorded by organization staff; not independently verified',
                ], ',', '"', '');
            }
            fclose($handle);
        }, 'opsdeck-impact-report-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('currentOrganization');
    }
}
