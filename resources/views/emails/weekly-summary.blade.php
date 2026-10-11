<p>{{ $organization->name }}の週次サマリーです。</p>
<p>発生状況の集計期間：{{ $summary['period_start'] ?? now()->subDays((int) config('opsdeck.weekly_summary_period_days') - 1)->toDateString() }}〜{{ $summary['period_end'] ?? now()->toDateString() }}。現在の対応項目は{{ $summary['as_of'] ?? now($organization->timezone)->format('Y-m-d H:i') }}時点です。</p>
<h2>期間中に発生したこと</h2>
<ul>
    <li><a href="{{ route('imports.index', ['has_errors' => 1, 'created_after' => $summary['period_start'] ?? now()->subDays((int) config('opsdeck.weekly_summary_period_days') - 1)->toDateString(), 'created_before' => $summary['period_end'] ?? now()->toDateString()]) }}">Importエラー行数</a>：{{ $summary['import_errors'] }}</li>
    <li><a href="{{ route('imports.index', ['status' => 'failed', 'created_after' => $summary['period_start'] ?? now()->subDays((int) config('opsdeck.weekly_summary_period_days') - 1)->toDateString(), 'created_before' => $summary['period_end'] ?? now()->toDateString()]) }}">Import実行失敗</a>：{{ $summary['failed_import_batches'] ?? 0 }}バッチ</li>
</ul>
<h2>集計時点で対応が必要な項目</h2>
@if (! ($summary['renewal_alerts_enabled'] ?? true) && ! ($summary['security_alerts_enabled'] ?? true))<p>現在のプランでは契約更新・セキュリティ項目を集計対象外としています。</p>@endif
<ul>
    @if ($summary['renewal_alerts_enabled'] ?? true)<li><a href="{{ route('reminders.renewals') }}">{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の更新期限</a>：{{ $summary['renewals'] }}</li>@endif
    @if ($summary['security_alerts_enabled'] ?? true)
        <li><a href="{{ route('security-controls.index', ['attention' => 1]) }}">未対応Security Control</a>：{{ $summary['security_open'] }}</li>
        <li><a href="{{ route('saas-services.index') }}">MFA未確認SaaSアカウント</a>：{{ $summary['mfa_unknown'] }}</li>
    @endif
</ul>
