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
        $generatedAt = now($organization->timezone);
        $metrics = $metricsService->current($organization);
        $comparison = $metricsService->importComparison($organization);
        $trend = $metricsService->trend($organization);
        $realizedSavingsTotals = $realizedSavingsSummary->totalsByCurrencyAndPeriod($organization);

        return view('impact-reports.show', compact('organization', 'generatedAt', 'metrics', 'comparison', 'trend', 'realizedSavingsTotals'));
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
        $organizationName = $organization->name;
        $organizationTimezone = $organization->timezone;
        $generatedAt = now($organization->timezone);

        return response()->streamDownload(function () use ($metrics, $comparison, $trend, $realizedSavingsTotals, $csvFormulaSanitizer, $organizationName, $organizationTimezone, $generatedAt): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['項目', '項目ID', '値', '情報源・補足'], ',', '"', '');
            $this->writeCsvMetricRow($handle, 'organization_name', $organizationName, 'このレポートの対象組織', $csvFormulaSanitizer);
            $this->writeCsvMetricRow(
                $handle,
                'report_generated_at',
                $generatedAt->format('Y-m-d H:i'),
                '組織のタイムゾーン: '.$organizationTimezone,
                $csvFormulaSanitizer,
            );
            foreach ($metrics as $metric => $value) {
                if ($metric === 'monthly_jpy') {
                    continue;
                }

                if ($metric === 'monthly_costs_by_currency' && is_array($value)) {
                    foreach ($value as $currency => $monthlyCost) {
                        if (! is_string($currency) || (! is_int($monthlyCost) && ! is_float($monthlyCost))) {
                            continue;
                        }

                        $this->writeCsvMetricRow(
                            $handle,
                            'monthly_cost_'.$currency,
                            number_format((float) $monthlyCost, 2, '.', ''),
                            '登録済み契約データ。通貨別に集計し、為替換算していません。',
                            $csvFormulaSanitizer,
                        );
                    }

                    continue;
                }

                if ($metric === 'work_time_labor_equivalent_by_currency' && is_array($value)) {
                    foreach ($value as $currency => $monthlyEquivalent) {
                        if (! is_string($currency) || (! is_int($monthlyEquivalent) && ! is_float($monthlyEquivalent))) {
                            continue;
                        }

                        $this->writeCsvMetricRow(
                            $handle,
                            'work_time_labor_equivalent_'.$currency,
                            number_format((float) $monthlyEquivalent, 2, '.', ''),
                            '担当者入力の作業時間と任意の時間単価から算出した参考値。実際の経費削減ではありません。',
                            $csvFormulaSanitizer,
                        );
                    }

                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $this->writeCsvMetricRow($handle, $metric, $value, '現在のOpsDeck登録データ', $csvFormulaSanitizer);
                }
            }
            if ($comparison !== null) {
                foreach (['employees', 'assets', 'saas_services', 'unresolved_items'] as $metric) {
                    $before = $comparison['before']->metrics_json[$metric] ?? 0;
                    $after = $comparison['after']->metrics_json[$metric] ?? 0;
                    $batchSource = '取込履歴ID '.$comparison['after']->import_batch_id;
                    $this->writeCsvMetricRow($handle, 'latest_import_before_'.$metric, $before, $batchSource, $csvFormulaSanitizer);
                    $this->writeCsvMetricRow($handle, 'latest_import_after_'.$metric, $after, $batchSource, $csvFormulaSanitizer);
                    $this->writeCsvMetricRow($handle, 'latest_import_delta_'.$metric, $after - $before, $batchSource, $csvFormulaSanitizer);
                }

                foreach ($comparison['monthly_costs_by_currency'] as $currency => $costs) {
                    $metric = 'latest_import_monthly_cost_'.$currency;
                    $batchSource = '取込履歴ID '.$comparison['after']->import_batch_id;
                    $this->writeCsvMetricRow($handle, $metric.'_before', number_format($costs['before'], 2, '.', ''), $batchSource, $csvFormulaSanitizer);
                    $this->writeCsvMetricRow($handle, $metric.'_after', number_format($costs['after'], 2, '.', ''), $batchSource, $csvFormulaSanitizer);
                    $this->writeCsvMetricRow($handle, $metric.'_delta', number_format($costs['delta'], 2, '.', ''), $batchSource, $csvFormulaSanitizer);
                }
            }
            foreach ($trend as $snapshot) {
                $this->writeCsvMetricRow(
                    $handle,
                    'trend_'.$snapshot->stage.'_unresolved_items',
                    $snapshot->metrics_json['unresolved_items'] ?? 0,
                    '記録日時 '.$snapshot->captured_at->toIso8601String(),
                    $csvFormulaSanitizer,
                );
            }
            foreach ($realizedSavingsTotals as $savingTotal) {
                $this->writeCsvMetricRow(
                    $handle,
                    'realized_savings_'.$savingTotal->currency.'_'.$savingTotal->period,
                    number_format((float) $savingTotal->amount_total, 2, '.', ''),
                    '組織の担当者が記録。第三者による検証はありません。',
                    $csvFormulaSanitizer,
                );
            }
            fclose($handle);
        }, 'opsdeck-impact-report-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param resource $handle */
    private function writeCsvMetricRow($handle, string $metric, int|float|string $value, string $source, CsvFormulaSanitizer $sanitizer): void
    {
        fputcsv($handle, [
            $sanitizer->sanitize($this->metricLabel($metric)),
            $sanitizer->sanitize($metric),
            is_string($value) ? $sanitizer->sanitize($value) : $value,
            $sanitizer->sanitize($source),
        ], ',', '"', '');
    }

    private function metricLabel(string $metric): string
    {
        $currencyCodePattern = '[A-Z]{'.(int) config('opsdeck.work_time.currency_code_length').'}';
        $labels = [
            'organization_name' => '対象組織',
            'report_generated_at' => 'レポート作成日時',
            'employees' => '社員数',
            'assets' => '資産数',
            'saas_services' => 'クラウドサービス数',
            'evidence_expired' => '期限切れ証跡数',
            'security_open' => '未対応セキュリティ項目数',
            'renewals_30_days' => '30日以内の更新期限件数',
            'work_time_monthly_minutes' => '月あたり作業時間短縮（分）',
            'work_time_monthly_hours' => '月あたり作業時間短縮（時間）',
            'import_errors' => '未確認Importエラー行数',
            'import_failed_batches' => '未確認Import実行失敗数',
            'questionnaire_pending' => '未承認質問票回答数',
            'asset_attention' => '未割当・退職者紐づき資産数',
            'saas_attention' => '未割当・退職者紐づきSaaS数',
            'unresolved_items' => '確認が必要な項目数（カテゴリ件数の単純合算）',
        ];

        if (isset($labels[$metric])) {
            return $labels[$metric];
        }

        if (preg_match('/\Amonthly_cost_('.$currencyCodePattern.')\z/', $metric, $matches) === 1) {
            return '月額契約費用（'.$matches[1].'）';
        }

        if (preg_match('/\Awork_time_labor_equivalent_('.$currencyCodePattern.')\z/', $metric, $matches) === 1) {
            return '作業時間の金額換算参考値（月あたり・'.$matches[1].'）';
        }

        if (preg_match('/\Alatest_import_(before|after|delta)_(employees|assets|saas_services|unresolved_items)\z/', $metric, $matches) === 1) {
            $stage = ['before' => 'Import前', 'after' => 'Import後', 'delta' => '増減'][$matches[1]];
            $item = ['employees' => '社員数', 'assets' => '資産数', 'saas_services' => 'クラウドサービス数', 'unresolved_items' => '確認項目数'][$matches[2]];

            return '最新Importの'.$stage.'（'.$item.'）';
        }

        if (preg_match('/\Alatest_import_monthly_cost_('.$currencyCodePattern.')_(before|after|delta)\z/', $metric, $matches) === 1) {
            $stage = ['before' => '取込前', 'after' => '取込後', 'delta' => '増減'][$matches[2]];

            return '月額契約費用（'.$matches[1].'）の'.$stage;
        }

        if (preg_match('/\Atrend_(import_after|rollback_after|daily)_unresolved_items\z/', $metric, $matches) === 1) {
            $stage = ['import_after' => '取込後', 'rollback_after' => '取込取消後', 'daily' => '日次'][$matches[1]];

            return '確認項目数の推移（'.$stage.'）';
        }

        if (preg_match('/\Arealized_savings_('.$currencyCodePattern.')_([a-z_]+)\z/', $metric, $matches) === 1) {
            $period = (string) config('opsdeck.cost_savings.realized_periods.'.$matches[2], $matches[2]);

            return '記録された削減実績（'.$matches[1].'・'.$period.'）';
        }

        return 'その他の指標';
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('currentOrganization');
    }
}
