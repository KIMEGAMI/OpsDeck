<x-layouts.app>
    <section class="card">
        <h1>直近7日間のまとめとメール設定</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p class="muted">集計期間：{{ $summary['period_start'] }}〜{{ $summary['period_end'] }}。対応状況は{{ $summary['as_of'] }}時点です。数字を押すと、詳しい一覧を確認できます。</p>
        <h2>直近7日間に新しく起きたこと</h2>
        <dl>
            <dt>取込でエラーがあった行</dt><dd><a href="{{ route('imports.index', ['has_errors' => 1, 'created_after' => $summary['period_start'], 'created_before' => $summary['period_end']]) }}">{{ $summary['import_errors'] }}</a>行</dd>
            <dt>取込に失敗した回数</dt><dd><a href="{{ route('imports.index', ['status' => 'failed', 'created_after' => $summary['period_start'], 'created_before' => $summary['period_end']]) }}">{{ $summary['failed_import_batches'] }}</a>回</dd>
        </dl>
        <h2>今も確認が必要な項目</h2>
        @if (! $summary['renewal_alerts_enabled'] && ! $summary['security_alerts_enabled'])
            <p class="muted">無料プランでは、契約の更新期限やセキュリティ項目は表示していません。</p>
        @endif
        <dl>
            @if ($summary['renewal_alerts_enabled'])
                <dt>{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内に確認が必要な契約期限</dt><dd><a href="{{ route('reminders.renewals') }}">{{ $summary['renewals'] }}</a>件</dd>
            @endif
            @if ($summary['security_alerts_enabled'])
                <dt>確認が済んでいないセキュリティ項目</dt><dd><a href="{{ route('security-controls.index', ['attention' => 1]) }}">{{ $summary['security_open'] }}</a>件</dd>
                <dt>ログイン時の追加確認が済んでいないクラウドサービスのアカウント</dt><dd><a href="{{ route('saas-services.index') }}">{{ $summary['mfa_unknown'] }}</a>件</dd>
            @endif
        </dl>
        <h2>メールの受信設定</h2>
        <form method="post" action="{{ route('weekly-summary.preference') }}">
            @csrf @method('put')
            <label><input type="hidden" name="weekly_summary_enabled" value="0"><input type="checkbox" name="weekly_summary_enabled" value="1" @checked($preference->weekly_summary_enabled)> 週1回のまとめメールを受け取る</label>
            <label><input type="hidden" name="announcement_enabled" value="0"><input type="checkbox" name="announcement_enabled" value="1" @checked($preference->announcement_enabled)> 管理者のお知らせメールを受け取る</label>
            <button type="submit">メール設定を保存する</button>
        </form>
        <p class="muted"><a href="{{ route('help.recovery', 'email') }}">メールが届かないときの確認方法</a></p>
    </section>
</x-layouts.app>
