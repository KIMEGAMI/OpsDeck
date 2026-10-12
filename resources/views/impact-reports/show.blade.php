<x-layouts.app>
    <style media="print">
        @page { size: A4; margin: 14mm; }
        html, body { background: #ffffff !important; color: #172033 !important; }
        body > header, .impact-report-actions { display: none !important; }
        body > main { max-width: none !important; margin: 0 !important; padding: 0 !important; }
        .card { border: 0 !important; border-radius: 0 !important; box-shadow: none !important; padding: 0 !important; }
        .card h1, .card h2 { break-after: avoid-page; }
        .card thead { display: table-header-group; }
        .card tr { break-inside: avoid; break-after: auto; }
        .card a { color: #172033 !important; text-decoration: none !important; }
        .card .muted { color: #334e68 !important; }
    </style>
    <section class="card">
        <h1>導入効果レポート</h1>
        <p class="muted">現在のOpsDeckデータから集計した効果指標です。導入前スナップショットがない項目は比較できません。</p>
        <dl>
            <dt>社員</dt><dd>{{ $metrics['employees'] }}件</dd>
            <dt>資産</dt><dd>{{ $metrics['assets'] }}件</dd>
            <dt>SaaS</dt><dd>{{ $metrics['saas_services'] }}件</dd>
            <dt>期限切れ証跡</dt><dd>{{ $metrics['evidence_expired'] }}件</dd>
            <dt>未対応Security Control</dt><dd>{{ $metrics['security_open'] }}件</dd>
            <dt>{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の更新期限</dt><dd>{{ $metrics['renewals_30_days'] }}件</dd>
            <dt>未確認Importエラー</dt><dd>{{ $metrics['import_errors'] }}行</dd>
            <dt>未確認Import実行失敗</dt><dd><a href="{{ route('imports.index', ['status' => 'failed', 'unreviewed' => 1]) }}">{{ $metrics['import_failed_batches'] }}バッチ</a></dd>
            <dt>未承認の質問票回答</dt><dd>{{ $metrics['questionnaire_pending'] }}件</dd>
            <dt>未割当・退職者紐づき資産</dt><dd>{{ $metrics['asset_attention'] }}件</dd>
            <dt>未割当・退職者紐づきSaaS</dt><dd>{{ $metrics['saas_attention'] }}件</dd>
            <dt>確認が必要な項目の合計</dt><dd>{{ $metrics['unresolved_items'] }}件</dd>
            <dt>登録済み契約の月額換算コスト</dt>
            <dd>
                @forelse ($metrics['monthly_costs_by_currency'] as $currency => $monthlyCost)
                    <div>{{ $currency }}: {{ number_format($monthlyCost, 2) }}</div>
                @empty
                    <span class="muted">対象となる月次・年次契約はありません。</span>
                @endforelse
            </dd>
        </dl>
        <p class="muted">登録済みの有効・解約予定契約のうち、月次・年次の契約を通貨別に月額換算しています（年次契約は12分割）。通貨間の換算はせず、一時契約・その他の請求周期は含みません。これは契約台帳に基づく概算で、実際の請求額や実現した経費削減額ではありません。<a href="{{ route('costs.dashboard') }}">削減可能額の概算</a>はこちらで確認できます。</p>
        <section aria-labelledby="impact-realized-savings-title">
            <h2 id="impact-realized-savings-title">記録された削減実績</h2>
            <p class="muted">組織の担当者が実現済みとして記録した金額です。根拠資料をOpsDeckが自動検証したものではありません。取消済みは除外し、通貨と期間を分けて集計します。<a href="{{ route('costs.dashboard') }}">削減実績の記録・履歴</a></p>
            @if ($realizedSavingsTotals->isNotEmpty())
                <ul>
                    @foreach ($realizedSavingsTotals as $savingTotal)
                        <li>{{ $savingTotal->currency }}（{{ config('opsdeck.cost_savings.realized_periods.'.$savingTotal->period, $savingTotal->period) }}）：{{ number_format((float) $savingTotal->amount_total, 2) }}</li>
                    @endforeach
                </ul>
            @else
                <p>記録された削減実績はありません。</p>
            @endif
        </section>
        <p class="muted">「確認が必要な項目の合計」は上記の異なる管理カテゴリの件数を単純合算した値で、経費削減額や作業時間の実測値ではありません。</p>
        <section aria-labelledby="impact-import-comparison-title">
            <h2 id="impact-import-comparison-title">最新Importの前後比較</h2>
            @if ($comparison !== null)
                <table>
                    <thead><tr><th scope="col">指標</th><th scope="col">Import前</th><th scope="col">Import後</th><th scope="col">差分</th></tr></thead>
                    <tbody>
                        @foreach ([['社員数', 'employees'], ['資産数', 'assets'], ['SaaS数', 'saas_services'], ['確認が必要な項目', 'unresolved_items']] as [$label, $metric])
                            @php
                                $before = $comparison['before']->metrics_json[$metric] ?? 0;
                                $after = $comparison['after']->metrics_json[$metric] ?? 0;
                            @endphp
                            <tr><th scope="row">{{ $label }}</th><td>{{ $before }}</td><td>{{ $after }}</td><td>{{ $after - $before }}</td></tr>
                        @endforeach
                        @foreach ($comparison['monthly_costs_by_currency'] as $currency => $costs)
                            <tr><th scope="row">月額コスト（{{ $currency }}）</th><td>{{ number_format($costs['before'], 2) }}</td><td>{{ number_format($costs['after'], 2) }}</td><td>{{ number_format($costs['delta'], 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">比較可能なImport履歴はまだありません。次回のImport完了後から前後比較を表示します。</p>
            @endif
        </section>
        <section aria-labelledby="impact-trend-title">
            <h2 id="impact-trend-title">Import後の推移</h2>
            @if ($trend->isNotEmpty())
                <table>
                    <thead><tr><th scope="col">記録日時</th><th scope="col">状態</th><th scope="col">社員</th><th scope="col">資産</th><th scope="col">SaaS</th><th scope="col">確認項目</th></tr></thead>
                    <tbody>
                        @foreach ($trend as $snapshot)
                            <tr>
                                <td>{{ $snapshot->captured_at->timezone($organization->timezone)->format('Y-m-d H:i') }}</td>
                                <td>{{ match ($snapshot->stage) { 'rollback_after' => '取消後', 'daily' => '日次', default => 'Import後' } }}</td>
                                <td>{{ $snapshot->metrics_json['employees'] ?? 0 }}</td>
                                <td>{{ $snapshot->metrics_json['assets'] ?? 0 }}</td>
                                <td>{{ $snapshot->metrics_json['saas_services'] ?? 0 }}</td>
                                <td>{{ $snapshot->metrics_json['unresolved_items'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">推移はImport完了後から記録されます。過去の状態を遡って推測することはありません。</p>
            @endif
        </section>
        @can('export', $organization)
            <p class="impact-report-actions"><a href="{{ route('impact-report.download') }}">CSVをダウンロード</a> <button type="button" aria-label="導入効果レポートを印刷またはPDF保存" onclick="window.print()">印刷・PDF保存</button></p>
        @endcan
    </section>
</x-layouts.app>
