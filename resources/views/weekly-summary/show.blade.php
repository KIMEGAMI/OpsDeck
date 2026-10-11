<x-layouts.app>
    <section class="card">
        <h1>週次サマリー</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p class="muted">発生状況の集計期間：{{ $summary['period_start'] }}〜{{ $summary['period_end'] }}。現在の対応項目は{{ $summary['as_of'] }}時点です。集計値には元画面へのリンクを付けています。</p>
        <h2>期間中に発生したこと</h2>
        <dl>
            <dt>Importエラー行数</dt><dd><a href="{{ route('imports.index', ['has_errors' => 1, 'created_after' => $summary['period_start'], 'created_before' => $summary['period_end']]) }}">{{ $summary['import_errors'] }}</a></dd>
            <dt>Import実行失敗</dt><dd><a href="{{ route('imports.index', ['status' => 'failed', 'created_after' => $summary['period_start'], 'created_before' => $summary['period_end']]) }}">{{ $summary['failed_import_batches'] }}</a>バッチ</dd>
        </dl>
        <h2>集計時点で対応が必要な項目</h2>
        @if (! $summary['renewal_alerts_enabled'] && ! $summary['security_alerts_enabled'])
            <p class="muted">現在のプランでは契約更新・セキュリティ項目を集計対象外としています。</p>
        @endif
        <dl>
            @if ($summary['renewal_alerts_enabled'])
                <dt>{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の契約更新</dt><dd><a href="{{ route('reminders.renewals') }}">{{ $summary['renewals'] }}</a></dd>
            @endif
            @if ($summary['security_alerts_enabled'])
                <dt>未対応Security Control</dt><dd><a href="{{ route('security-controls.index', ['attention' => 1]) }}">{{ $summary['security_open'] }}</a></dd>
                <dt>MFA未確認SaaSアカウント</dt><dd><a href="{{ route('saas-services.index') }}">{{ $summary['mfa_unknown'] }}</a></dd>
            @endif
        </dl>
        <h2>配信設定</h2>
        <form method="post" action="{{ route('weekly-summary.preference') }}">
            @csrf @method('put')
            <label><input type="hidden" name="weekly_summary_enabled" value="0"><input type="checkbox" name="weekly_summary_enabled" value="1" @checked($preference->weekly_summary_enabled)> 週次サマリーを受け取る</label>
            <label><input type="hidden" name="announcement_enabled" value="0"><input type="checkbox" name="announcement_enabled" value="1" @checked($preference->announcement_enabled)> 管理者のお知らせメールを受け取る</label>
            <button type="submit">設定を保存</button>
        </form>
    </section>
</x-layouts.app>
