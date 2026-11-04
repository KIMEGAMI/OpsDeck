<p>{{ $organization->name }}の直近7日間のまとめです。</p>
<p>集計期間：{{ $summary['period_start'] ?? now()->subDays((int) config('opsdeck.weekly_summary_period_days') - 1)->toDateString() }}〜{{ $summary['period_end'] ?? now()->toDateString() }}。対応状況は{{ $summary['as_of'] ?? now($organization->timezone)->format('Y-m-d H:i') }}時点です。</p>
<h2>直近7日間に新しく起きたこと</h2>
<ul>
    <li><a href="{{ route('imports.index', ['has_errors' => 1, 'created_after' => $summary['period_start'] ?? now()->subDays((int) config('opsdeck.weekly_summary_period_days') - 1)->toDateString(), 'created_before' => $summary['period_end'] ?? now()->toDateString()]) }}">取込でエラーがあった行</a>：{{ $summary['import_errors'] }}行</li>
    <li><a href="{{ route('imports.index', ['status' => 'failed', 'created_after' => $summary['period_start'] ?? now()->subDays((int) config('opsdeck.weekly_summary_period_days') - 1)->toDateString(), 'created_before' => $summary['period_end'] ?? now()->toDateString()]) }}">取込に失敗した回数</a>：{{ $summary['failed_import_batches'] ?? 0 }}回</li>
</ul>
<p><a href="{{ route('weekly-summary.show', ['organization_id' => $organization->id]) }}">直近7日間のまとめとメール設定を確認する</a></p>
<p>ログイン後、この組織のまとめメールの受信設定を変更できます。管理者のお知らせメールは別に設定できます。</p>
<h2>今も確認が必要な項目</h2>
@if (! ($summary['renewal_alerts_enabled'] ?? true) && ! ($summary['security_alerts_enabled'] ?? true))<p>現在利用できる範囲では、確認が必要な項目はありません。</p>@endif
<ul>
    @if ($summary['renewal_alerts_enabled'] ?? true)<li><a href="{{ route('reminders.renewals') }}">{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内に確認が必要な契約期限</a>：{{ $summary['renewals'] }}件</li>@endif
    @if ($summary['security_alerts_enabled'] ?? true)
        <li><a href="{{ route('security-controls.index', ['attention' => 1]) }}">確認が済んでいないセキュリティ項目</a>：{{ $summary['security_open'] }}件</li>
        <li><a href="{{ route('saas-services.index') }}">ログイン時の追加確認が済んでいないクラウドサービスのアカウント</a>：{{ $summary['mfa_unknown'] }}件</li>
    @endif
</ul>
