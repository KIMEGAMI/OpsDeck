<x-layouts.app>
    <section class="card" aria-labelledby="dashboard-title">
        <h1 id="dashboard-title">{{ $organization->name }}</h1>
        <p class="muted">社員・パソコン・契約など、会社の情報をまとめて確認する画面です。</p>
        <div class="dashboard-metrics" aria-label="主要指標">
            <a class="metric-card" href="{{ route('employees.index') }}"><span class="metric-label">社員数</span><strong class="metric-value">{{ $metrics['employees'] }}</strong></a>
            <a class="metric-card" href="{{ route('assets.index') }}"><span class="metric-label">管理端末数</span><strong class="metric-value">{{ $metrics['assets'] }}</strong></a>
            <a class="metric-card" href="{{ route('saas-services.index') }}"><span class="metric-label">クラウドサービス数</span><strong class="metric-value">{{ $metrics['saasServices'] }}</strong></a>
            <a class="metric-card" href="{{ route('contracts.index') }}"><span class="metric-label">有効契約数</span><strong class="metric-value">{{ $metrics['contracts'] }}</strong></a>
            <a class="metric-card" href="{{ route('costs.dashboard') }}"><span class="metric-label">月額費用合計（円）</span><strong class="metric-value">{{ number_format($metrics['monthlyJpy'], $monthlyJpyDisplayDecimals) }}</strong></a>
        </div>
        <div class="dashboard-columns">
            <section class="dashboard-panel" aria-labelledby="today-tasks-title">
                <h2 id="today-tasks-title">今日やること</h2>
                <ol>
            @if ($planFeatures['evidence'] && $metrics['evidenceExpired'] > 0)
                <li><a href="{{ route('evidence.index', ['expired' => 1]) }}">期限切れ証跡を確認する</a>（{{ $metrics['evidenceExpired'] }}件）</li>
            @endif
            @if ($planFeatures['renewal_alerts'] && $metrics['renewalAlerts'] > 0)
                <li><a href="{{ route('reminders.renewals') }}">期限切れ・{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の契約期限を確認する</a>（{{ $metrics['renewalAlerts'] }}件）</li>
            @endif
            @if ($metrics['importErrors'] > 0)
                <li><a href="{{ route('imports.index', ['has_errors' => 1, 'unreviewed' => 1]) }}">取込で確認が必要な行を確認する</a>（{{ $metrics['importErrors'] }}行）</li>
            @endif
            @if ($metrics['importFailedBatches'] > 0)
                <li><a href="{{ route('imports.index', ['status' => 'failed', 'unreviewed' => 1]) }}">取り込みに失敗した履歴を確認する</a>（{{ $metrics['importFailedBatches'] }}件）</li>
            @endif
            @if ($planFeatures['questionnaires'] && $metrics['questionnairePending'] > 0)
                <li><a href="{{ route('questionnaires.index') }}">未承認の質問票回答を確認する</a>（{{ $metrics['questionnairePending'] }}件）</li>
            @endif
            @if ($planFeatures['security_controls'] && $metrics['securityOpen'] > 0)
                <li><a href="{{ route('security-controls.index', ['attention' => 1]) }}">未対応のセキュリティ項目を確認する</a>（{{ $metrics['securityOpen'] }}件）</li>
            @endif
            @if ($metrics['assetAttention'] > 0)
                <li><a href="{{ route('assets.index', ['needs_attention' => 1]) }}">未割当または退職者に紐づく資産を確認する</a>（{{ $metrics['assetAttention'] }}件）</li>
            @endif
            @if ($metrics['saasAttention'] > 0)
                <li><a href="{{ route('saas-services.index', ['needs_attention' => 1]) }}">利用者未登録・退職済み社員のサービスを確認する</a>（{{ $metrics['saasAttention'] }}件）</li>
            @endif
            @if ($metrics['evidenceExpired'] === 0 && $metrics['renewalAlerts'] === 0 && $metrics['importErrors'] === 0 && $metrics['importFailedBatches'] === 0 && $metrics['securityOpen'] === 0 && $metrics['questionnairePending'] === 0 && $metrics['assetAttention'] === 0 && $metrics['saasAttention'] === 0)
                <li>このプランで確認できる範囲では、優先対応が必要な項目はありません。</li>
            @endif
                </ol>
                @if ($hasUnavailablePriorityFeatures)
                    <p class="muted">一部の機能は現在提供していません。利用できる機能は無料でご利用いただけます。</p>
                @endif
            </section>
            <details class="dashboard-panel">
                <summary>すべての注意件数を見る</summary>
                <ul aria-label="注意が必要な件数">
                    @if ($planFeatures['renewal_alerts'])
                        <li>期限切れ・{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の更新アラート：{{ $metrics['renewalAlerts'] }}件</li>
                    @endif
                    @if ($planFeatures['security_controls'])
                        <li>要対応セキュリティ項目：{{ $metrics['securityOpen'] }}件</li>
                    @endif
                    @if ($planFeatures['evidence'])
                        <li>期限切れ証跡：{{ $metrics['evidenceExpired'] }}件</li>
                    @endif
                    <li>取込で確認が必要な行：{{ $metrics['importErrors'] }}行</li>
                    <li>取り込みに失敗した履歴：{{ $metrics['importFailedBatches'] }}件</li>
                </ul>
            </details>
        </div>
        <details class="dashboard-panel">
            <summary>よく使う機能を開く</summary>
            <nav class="dashboard-links" aria-label="よく使う機能">
                <p><a href="{{ route('costs.dashboard') }}">費用の内訳を見る</a></p>
                @if ($planFeatures['renewal_alerts'])
                    <p><a href="{{ route('reminders.renewals') }}">更新リマインダー</a></p>
                @endif
                @if ($planFeatures['security_controls'])
                    <p><a href="{{ route('security-controls.index', ['attention' => 1]) }}">要対応セキュリティ項目を確認</a></p>
                @endif
                @if ($planFeatures['evidence'])
                    <p><a href="{{ route('evidence.index', ['expired' => 1]) }}">期限切れ証跡を確認</a></p>
                @endif
                <p><a href="{{ route('imports.index', ['has_errors' => 1]) }}">取込で確認が必要な行を探す</a></p>
                <p><a href="{{ route('saved-views.index') }}">前に保存した一覧を見る</a></p>
                <p><a href="{{ route('weekly-summary.show') }}">直近7日間のまとめを見る</a></p>
                <p><a href="{{ route('help.recovery', 'import') }}">取込に困ったとき</a></p>
                <p><a href="{{ route('help.recovery', 'email') }}">メールが届かないとき</a></p>
                @if ($planFeatures['questionnaires'])
                    <p><a href="{{ route('questionnaires.index') }}">取引先質問票に回答</a></p>
                @endif
                @if ($planFeatures['impact_report'])
                    <p><a href="{{ route('impact-report.show') }}">導入効果レポートを確認</a></p>
                @endif
                @if (in_array(request()->attributes->get('currentMembership')?->role?->value, ['owner', 'admin'], true))
                    @if ($planFeatures['referrals'])
                        <p><a href="{{ route('referrals.show') }}">紹介リンクを取得</a></p>
                    @endif
                    <p><a href="{{ route('onboarding.index') }}">導入チェックリストを確認</a></p>
                @endif
            </nav>
        </details>
        @if (in_array(request()->attributes->get('currentMembership')?->role?->value, ['owner', 'admin'], true) && count($metrics['limitWarnings']) > 0)
            <section aria-labelledby="limit-warning-title" role="status">
            <h2 id="limit-warning-title">プラン上限の注意</h2>
            <ul>
                @foreach ($metrics['limitWarnings'] as $warning)
                    <li>{{ $warning['label'] }}：{{ $warning['current'] }}/{{ $warning['limit'] }}（{{ $warning['percentage'] }}%、{{ $warning['status'] }}）</li>
                @endforeach
            </ul>
            @if (request()->attributes->get('currentMembership')?->role?->value === 'owner')
                <p><a href="{{ route('settings.organization') }}">組織設定で利用状況を確認</a></p>
            @else
                <p class="muted">不要な登録データの整理について、組織Ownerにご依頼ください。</p>
            @endif
            <p><a href="mailto:{{ config('opsdeck.support_email') }}">利用上限について問い合わせる</a></p>
            </section>
        @endif
        @if ($showOnboardingPanel && ! $hasCompletedOnboarding)
            <section class="dashboard-panel" aria-labelledby="first-step-title">
                <h2 id="first-step-title">初めて使う方へ</h2>
                <p>社員名簿など、今使っている管理表のコピーを選んでください。元のファイルは変更せず、登録前に内容を確認できます。</p>
                <p><a href="{{ route('imports.create') }}">管理表のコピーを選んで確認する</a></p>
                <p class="muted">確認しただけでは会社のデータは変わりません。内容を確かめてから、取込を実行できます。</p>
                <p><a href="{{ route('onboarding.index') }}">順番に進める導入チェックリストを見る</a></p>
            </section>
        @endif
    </section>
    <div class="dashboard-announcements">
        @include('dashboard-announcements', ['announcements' => $announcements])
    </div>
    <footer class="dashboard-legal-footer">
        <nav aria-label="規約とプライバシー">
            <a href="{{ route('legal.privacy') }}">プライバシーポリシー</a>
            <a href="{{ route('legal.terms') }}">利用規約</a>
        </nav>
    </footer>
</x-layouts.app>
