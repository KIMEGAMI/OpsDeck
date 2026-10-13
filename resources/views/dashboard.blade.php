<x-layouts.app>
    <section class="card" aria-labelledby="dashboard-title">
        <h1 id="dashboard-title">{{ $organization->name }}</h1>
        <p class="muted">現在の組織データと、優先して確認すべき項目を表示しています。</p>
        <div class="dashboard-metrics" aria-label="主要指標">
            <a class="metric-card" href="{{ route('employees.index') }}"><span class="metric-label">社員数</span><strong class="metric-value">{{ $metrics['employees'] }}</strong></a>
            <a class="metric-card" href="{{ route('assets.index') }}"><span class="metric-label">管理端末数</span><strong class="metric-value">{{ $metrics['assets'] }}</strong></a>
            <a class="metric-card" href="{{ route('saas-services.index') }}"><span class="metric-label">利用SaaS数</span><strong class="metric-value">{{ $metrics['saasServices'] }}</strong></a>
            <a class="metric-card" href="{{ route('contracts.index') }}"><span class="metric-label">有効契約数</span><strong class="metric-value">{{ $metrics['contracts'] }}</strong></a>
            <a class="metric-card" href="{{ route('costs.dashboard') }}"><span class="metric-label">月次コスト（JPY）</span><strong class="metric-value">{{ number_format($metrics['monthlyJpy'], 2) }}</strong></a>
        </div>
        <div class="dashboard-columns">
            <section class="dashboard-panel" aria-labelledby="today-tasks-title">
                <h2 id="today-tasks-title">今日やること</h2>
                <ol>
            @if ($planFeatures['evidence'] && $metrics['evidenceExpired'] > 0)
                <li><a href="{{ route('evidence.index', ['expired' => 1]) }}">期限切れ証跡を確認する</a>（{{ $metrics['evidenceExpired'] }}件）</li>
            @endif
            @if ($planFeatures['renewal_alerts'] && $metrics['renewalAlerts'] > 0)
                <li><a href="{{ route('reminders.renewals') }}">期限切れ・{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の契約更新を確認する</a>（{{ $metrics['renewalAlerts'] }}件）</li>
            @endif
            @if ($metrics['importErrors'] > 0)
                <li><a href="{{ route('imports.index', ['has_errors' => 1, 'unreviewed' => 1]) }}">未確認Importエラーを確認する</a>（{{ $metrics['importErrors'] }}行）</li>
            @endif
            @if ($metrics['importFailedBatches'] > 0)
                <li><a href="{{ route('imports.index', ['status' => 'failed', 'unreviewed' => 1]) }}">未確認のImport実行失敗を確認する</a>（{{ $metrics['importFailedBatches'] }}バッチ）</li>
            @endif
            @if ($planFeatures['questionnaires'] && $metrics['questionnairePending'] > 0)
                <li><a href="{{ route('questionnaires.index') }}">未承認の質問票回答を確認する</a>（{{ $metrics['questionnairePending'] }}件）</li>
            @endif
            @if ($planFeatures['security_controls'] && $metrics['securityOpen'] > 0)
                <li><a href="{{ route('security-controls.index', ['attention' => 1]) }}">未対応のSecurity Controlを確認する</a>（{{ $metrics['securityOpen'] }}件）</li>
            @endif
            @if ($metrics['assetAttention'] > 0)
                <li><a href="{{ route('assets.index', ['needs_attention' => 1]) }}">未割当または退職者に紐づく資産を確認する</a>（{{ $metrics['assetAttention'] }}件）</li>
            @endif
            @if ($metrics['saasAttention'] > 0)
                <li><a href="{{ route('saas-services.index', ['needs_attention' => 1]) }}">未割当または退職者に紐づくSaaSを確認する</a>（{{ $metrics['saasAttention'] }}件）</li>
            @endif
            @if ($metrics['evidenceExpired'] === 0 && $metrics['renewalAlerts'] === 0 && $metrics['importErrors'] === 0 && $metrics['importFailedBatches'] === 0 && $metrics['securityOpen'] === 0 && $metrics['questionnairePending'] === 0 && $metrics['assetAttention'] === 0 && $metrics['saasAttention'] === 0)
                <li>このプランで確認できる範囲では、優先対応が必要な項目はありません。</li>
            @endif
                </ol>
                @if ($hasUnavailablePriorityFeatures)
                    <p class="muted">更新リマインダー、証跡、セキュリティ管理、質問票機能はPremiumプランで利用できます。
                        @if ($canManageBilling)
                            <a href="{{ route('billing.index') }}">プランと料金を確認</a>
                        @else
                            プランの確認は組織Ownerにご相談ください。
                        @endif
                    </p>
                @endif
            </section>
            <section class="dashboard-panel" aria-labelledby="attention-title">
                <h2 id="attention-title">要確認</h2>
                <ul>
                    @if ($planFeatures['renewal_alerts'])
                        <li>期限切れ・{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の更新アラート：{{ $metrics['renewalAlerts'] }}件</li>
                    @endif
                    @if ($planFeatures['security_controls'])
                        <li>要対応セキュリティ項目：{{ $metrics['securityOpen'] }}件</li>
                    @endif
                    @if ($planFeatures['evidence'])
                        <li>期限切れ証跡：{{ $metrics['evidenceExpired'] }}件</li>
                    @endif
                    <li>未確認Importエラー行数：{{ $metrics['importErrors'] }}行</li>
                    <li>未確認Import実行失敗：{{ $metrics['importFailedBatches'] }}バッチ</li>
                </ul>
            </section>
        </div>
        <section class="dashboard-panel" aria-labelledby="quick-links-title">
            <h2 id="quick-links-title">よく使う機能</h2>
            <div class="dashboard-links">
                <p><a href="{{ route('costs.dashboard') }}">コスト詳細</a></p>
                @if ($planFeatures['renewal_alerts'])
                    <p><a href="{{ route('reminders.renewals') }}">更新リマインダー</a></p>
                @endif
                @if ($planFeatures['security_controls'])
                    <p><a href="{{ route('security-controls.index', ['attention' => 1]) }}">要対応セキュリティ項目を確認</a></p>
                @endif
                @if ($planFeatures['evidence'])
                    <p><a href="{{ route('evidence.index', ['expired' => 1]) }}">期限切れ証跡を確認</a></p>
                @endif
                <p><a href="{{ route('imports.index', ['has_errors' => 1]) }}">Importエラー履歴を確認</a></p>
                <p><a href="{{ route('saved-views.index') }}">定型ビューを確認</a></p>
                <p><a href="{{ route('weekly-summary.show') }}">週次サマリーを確認</a></p>
                <p><a href="{{ route('help.recovery', 'import') }}">Import復旧ガイド</a></p>
                <p><a href="{{ route('help.recovery', 'email') }}">メール復旧ガイド</a></p>
                @if ($planFeatures['questionnaires'])
                    <p><a href="{{ route('questionnaires.index') }}">取引先質問票に回答</a></p>
                @endif
                @if ($planFeatures['impact_report'])
                    <p><a href="{{ route('impact-report.show') }}">導入効果レポートを確認</a></p>
                @endif
                @if (in_array(request()->attributes->get('currentMembership')?->role?->value, ['owner', 'admin'], true))
                    <p><a href="{{ route('referrals.show') }}">紹介リンクを取得</a></p>
                    <p><a href="{{ route('onboarding.index') }}">導入チェックリストを確認</a></p>
                @endif
            </div>
        </section>
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
                <p class="muted">上限の変更や契約プランの見直しは、組織Ownerにご依頼ください。</p>
            @endif
            <p><a href="mailto:{{ config('opsdeck.support_email') }}">利用上限について問い合わせる</a></p>
            </section>
        @endif
        <h2>次に行うこと</h2>
        <ol>
            <li>社員・端末・SaaS・契約の既存管理表を確認する</li>
            <li>Migration Studioでプレビューを確認してから取り込む</li>
            <li>取り込み後に契約期限とセキュリティ状態を確認する</li>
        </ol>
        <p>Migration StudioではCSVのプレビュー、列マッピング、Import実行、結果確認、安全な取消に対応しています。重要な変更は必ず人間の確認後に実行されます。</p>
    </section>
    <div class="dashboard-announcements">
        @include('dashboard-announcements', ['announcements' => $announcements])
    </div>
</x-layouts.app>
