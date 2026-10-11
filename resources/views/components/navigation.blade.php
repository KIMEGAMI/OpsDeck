@auth
    <nav class="app-navigation" aria-label="メインメニュー">
        <div class="nav-section">
            <span class="nav-section-label">基本</span>
            <a href="{{ route('dashboard') }}" @class(['is-active' => request()->routeIs('dashboard')])>ダッシュボード</a>
            <a href="{{ route('employees.index') }}" @class(['is-active' => request()->routeIs('employees.*')])>社員</a>
            <a href="{{ route('assets.index') }}" @class(['is-active' => request()->routeIs('assets.*')])>端末・資産</a>
            <a href="{{ route('saas-services.index') }}" @class(['is-active' => request()->routeIs('saas-services.*')])>SaaS</a>
            <a href="{{ route('contracts.index') }}" @class(['is-active' => request()->routeIs('contracts.*')])>契約</a>
        </div>
        <div class="nav-section">
            <span class="nav-section-label">運用</span>
            <a href="{{ route('costs.dashboard') }}" @class(['is-active' => request()->routeIs('costs.dashboard')])>コスト</a>
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'renewal_alerts'))
                <a href="{{ route('reminders.renewals') }}" @class(['is-active' => request()->routeIs('reminders.renewals')])>更新</a>
            @endif
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'security_controls'))
                <a href="{{ route('security-controls.index') }}" @class(['is-active' => request()->routeIs('security-controls.*')])>セキュリティ</a>
            @endif
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'evidence'))
                <a href="{{ route('evidence.index') }}" @class(['is-active' => request()->routeIs('evidence.*')])>証跡</a>
            @endif
            <a href="{{ route('imports.index') }}" @class(['is-active' => request()->routeIs('imports.*')])>Import</a>
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'questionnaires'))
                <a href="{{ route('questionnaires.index') }}" @class(['is-active' => request()->routeIs('questionnaires.*')])>質問票</a>
            @endif
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'impact_report'))
                <a href="{{ route('impact-report.show') }}" @class(['is-active' => request()->routeIs('impact-report.*')])>導入効果</a>
            @endif
        </div>
        @if (request()->attributes->get('currentOrganization'))
            <div class="nav-section">
                <span class="nav-section-label">組織</span>
                <a href="{{ route('settings.members') }}" @class(['is-active' => request()->routeIs('settings.members')])>メンバー</a>
                @if (request()->user()->can('update', request()->attributes->get('currentOrganization')))
                    <a href="{{ route('settings.organization') }}" @class(['is-active' => request()->routeIs('settings.organization')])>組織設定</a>
                @endif
                @if (request()->user()->can('manageBilling', request()->attributes->get('currentOrganization')))
                    <a href="{{ route('billing.index') }}" @class(['is-active' => request()->routeIs('billing.index')])>Billing</a>
                @endif
            </div>
        @endif
        @if (request()->attributes->get('currentOrganization'))
            <div class="nav-section">
                <span class="nav-section-label">その他</span>
                @if (request()->user()->can('export', request()->attributes->get('currentOrganization')) && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'exports'))
                    <a href="{{ route('exports.index') }}" @class(['is-active' => request()->routeIs('exports.*')])>エクスポート</a>
                @endif
                <a href="{{ route('help.index') }}">ヘルプ</a>
            </div>
        @endif
        @if (in_array(strtolower((string) request()->user()->email), array_map('strtolower', config('opsdeck.platform_admin_emails', [])), true))
            <div class="nav-section nav-section-admin">
                <span class="nav-section-label">管理者</span>
                <a href="{{ route('admin.dashboard') }}" @class(['is-active' => request()->routeIs('admin.dashboard')])>管理者画面</a>
                <a href="{{ route('admin.announcements.index') }}" @class(['is-active' => request()->routeIs('admin.announcements.*')])>お知らせ</a>
                <a href="{{ route('admin.health') }}" @class(['is-active' => request()->routeIs('admin.health')])>運用ヘルス</a>
            </div>
        @endif
    </nav>
@endauth
