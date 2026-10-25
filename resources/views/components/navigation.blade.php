@auth
    <nav class="app-navigation" aria-label="メインメニュー">
        <div class="nav-section">
            <span class="nav-section-label">基本</span>
            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">ダッシュボード</x-nav-link>
            <x-nav-link :href="route('employees.index')" :active="request()->routeIs('employees.*')">社員</x-nav-link>
            <x-nav-link :href="route('assets.index')" :active="request()->routeIs('assets.*')">端末・資産</x-nav-link>
            <x-nav-link :href="route('saas-services.index')" :active="request()->routeIs('saas-services.*')">クラウドサービス</x-nav-link>
            <x-nav-link :href="route('contracts.index')" :active="request()->routeIs('contracts.*')">契約</x-nav-link>
        </div>
        <div class="nav-section">
            <span class="nav-section-label">運用</span>
            <x-nav-link :href="route('costs.dashboard')" :active="request()->routeIs('costs.dashboard')">費用の見直し</x-nav-link>
            <x-nav-link :href="route('saved-views.index')" :active="request()->routeIs('saved-views.*')">保存した一覧</x-nav-link>
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'renewal_alerts'))
                <x-nav-link :href="route('reminders.renewals')" :active="request()->routeIs('reminders.renewals')">更新期限</x-nav-link>
            @endif
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'security_controls'))
                <x-nav-link :href="route('security-controls.index')" :active="request()->routeIs('security-controls.*')">セキュリティ確認</x-nav-link>
            @endif
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'evidence'))
                <x-nav-link :href="route('evidence.index')" :active="request()->routeIs('evidence.*')">書類・確認資料</x-nav-link>
            @endif
            <x-nav-link :href="route('imports.index')" :active="request()->routeIs('imports.*')">Excelなどの取込</x-nav-link>
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'questionnaires'))
                <x-nav-link :href="route('questionnaires.index')" :active="request()->routeIs('questionnaires.*')">質問票</x-nav-link>
            @endif
            @if (request()->attributes->get('currentOrganization') && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'impact_report'))
                <x-nav-link :href="route('impact-report.show')" :active="request()->routeIs('impact-report.*')">導入効果</x-nav-link>
            @endif
        </div>
        @if (request()->attributes->get('currentOrganization'))
            <div class="nav-section">
                <span class="nav-section-label">組織</span>
                <x-nav-link :href="route('settings.members')" :active="request()->routeIs('settings.members')">メンバー</x-nav-link>
                @if (request()->user()->can('update', request()->attributes->get('currentOrganization')))
                    <x-nav-link :href="route('settings.organization')" :active="request()->routeIs('settings.organization')">組織設定</x-nav-link>
                @endif
                @if (request()->user()->can('manageBilling', request()->attributes->get('currentOrganization')))
                    <x-nav-link :href="route('billing.index')" :active="request()->routeIs('billing.index')">プラン・お支払い</x-nav-link>
                @endif
            </div>
        @endif
        @if (request()->attributes->get('currentOrganization'))
            <div class="nav-section">
                <span class="nav-section-label">その他</span>
                @if (request()->user()->can('export', request()->attributes->get('currentOrganization')) && app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'exports'))
                    <x-nav-link :href="route('exports.index')" :active="request()->routeIs('exports.*')">データの書き出し</x-nav-link>
                @endif
                <x-nav-link :href="route('feature-guide.index')" :active="request()->routeIs('feature-guide.*')">機能ガイド</x-nav-link>
                <x-nav-link :href="route('help.index')" :active="request()->routeIs('help.*')">使い方・よくある質問</x-nav-link>
            </div>
        @endif
        @if (in_array(strtolower((string) request()->user()->email), array_map('strtolower', config('opsdeck.platform_admin_emails', [])), true))
            <div class="nav-section nav-section-admin">
                <span class="nav-section-label">管理者</span>
                <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">管理者画面</x-nav-link>
                <x-nav-link :href="route('admin.announcements.index')" :active="request()->routeIs('admin.announcements.*')">お知らせ</x-nav-link>
                <x-nav-link :href="route('admin.health')" :active="request()->routeIs('admin.health')">システムの稼働状況</x-nav-link>
            </div>
        @endif
    </nav>
@endauth
