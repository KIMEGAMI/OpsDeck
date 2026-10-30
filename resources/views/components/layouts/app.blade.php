<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <meta name="theme-color" content="#102a43">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --interactive-target-min-height: 2.75rem; color-scheme: light; font-family: system-ui, sans-serif; background: #f4f7fb; color: #172033; }
        :focus-visible { outline: 3px solid #005f73; outline-offset: 2px; }
        body { margin: 0; }
        header { background: #102a43; color: #ffffff; padding: 1rem 2rem; }
        header :focus-visible { outline-color: #f6c945; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
        h1, h2, h3 { color: #102a43; line-height: 1.3; }
        h1 { font-size: clamp(1.5rem, 2vw, 1.875rem); }
        h2 { font-size: 1.25rem; }
        h3 { font-size: 1.125rem; }
        .card { background: #ffffff; border: 1px solid #bcccdc; border-radius: .5rem; padding: 1.5rem; box-shadow: 0 .1rem .3rem #102a4326; }
        .auth-card { max-width: 34rem; margin-inline: auto; }
        label { display: block; font-weight: 700; margin-top: 1rem; }
        input:not([type="checkbox"]):not([type="radio"]), select, textarea { box-sizing: border-box; width: 100%; min-height: var(--interactive-target-min-height); padding: .65rem; border: 1px solid #486581; border-radius: .25rem; color: #102a43; background: #ffffff; }
        input[type="checkbox"], input[type="radio"] { width: 1.25rem; height: 1.25rem; margin: 0 .5rem 0 0; vertical-align: middle; accent-color: #005f73; }
        textarea { min-height: 6rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .table-scroll { max-width: 100%; overflow-x: auto; overscroll-behavior-x: contain; }
        .table-scroll table { width: max-content; min-width: 100%; }
        th, td { border-bottom: 1px solid #bcccdc; padding: .75rem; text-align: left; }
        button { cursor: pointer; min-height: var(--interactive-target-min-height); margin-top: 1.25rem; padding: .65rem 1rem; border: 0; border-radius: .25rem; background: #005f73; color: #ffffff; font-weight: 700; }
        .auth-provider-button { display: inline-flex; min-height: var(--interactive-target-min-height); align-items: center; padding: .65rem 1rem; border: 1px solid #486581; border-radius: .25rem; background: #ffffff; color: #102a43; font-weight: 700; text-decoration: none; }
        a { color: #005f73; font-weight: 700; }
        .errors { color: #9b1c1c; background: #fff5f5; border-left: .25rem solid #c53030; padding: .75rem 1rem; }
        .muted { color: #486581; }
        .nav-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        .nav-row button { margin-top: 0; background: #ffffff; color: #102a43; }
        .desktop-navigation { flex: 1; min-width: 0; }
        .mobile-navigation { display: none; }
        .public-site-navigation { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .25rem .5rem; }
        .public-site-navigation a { display: inline-flex; min-height: var(--interactive-target-min-height); align-items: center; padding: .45rem .65rem; border-radius: .25rem; color: #ffffff; text-decoration: none; }
        .public-site-navigation a:hover, .public-site-navigation a:focus-visible { background: #486581; }
        .public-site-navigation a:focus-visible { outline-color: #f6c945; }
        .mobile-public-site-navigation { display: none; }
        .app-navigation { max-width: 72rem; margin: .75rem auto 0; display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; align-items: center; }
        .nav-section { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem .75rem; }
        .nav-section-label { color: #9fb3c8; font-size: .75rem; font-weight: 700; }
        .app-navigation a { display: inline-flex; min-height: var(--interactive-target-min-height); align-items: center; color: #ffffff; font-size: 1rem; text-decoration: none; padding: .5rem .65rem; border-radius: .25rem; }
        .app-navigation a:hover, .app-navigation a:focus-visible, .app-navigation a.is-active { background: #486581; }
        .app-navigation a:focus-visible { outline-color: #f6c945; }
        .nav-section-admin { margin-left: auto; }
        .dashboard-metrics { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .75rem; margin: 1.25rem 0; }
        .metric-card { display: block; background: #f7fafc; border: 1px solid #d9e2ec; border-radius: .45rem; padding: 1rem; text-decoration: none; color: #172033; }
        .metric-card:hover, .metric-card:focus-visible { border-color: #005f73; box-shadow: 0 0 0 2px #005f7333; }
        .metric-label { display: block; color: #486581; font-size: .82rem; font-weight: 700; }
        .metric-value { display: block; color: #102a43; font-size: 1.65rem; line-height: 1.2; margin-top: .35rem; }
        .dashboard-columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-top: 1rem; }
        .dashboard-panel { background: #ffffff; border: 1px solid #bcccdc; border-radius: .5rem; padding: 1.25rem; box-shadow: 0 .1rem .3rem #102a4326; }
        .dashboard-panel h2 { margin-top: 0; }
        .dashboard-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem 1rem; }
        .dashboard-links p { margin: 0; }
        .dashboard-announcements { margin-top: 1rem; }
        .dashboard-announcements .card { max-height: 22rem; overflow-y: auto; }
        .dashboard-announcements article + article { border-top: 1px solid #d9e2ec; margin-top: 1rem; padding-top: 1rem; }
        .dashboard-legal-footer { margin: 1.5rem 0 0; padding: 1rem 0; border-top: 2px solid #bcccdc; color: #334e68; }
        .dashboard-legal-footer nav { display: flex; flex-wrap: wrap; gap: .75rem 1.25rem; }
        .dashboard-legal-footer a { min-height: var(--interactive-target-min-height); display: inline-flex; align-items: center; }
        @media (max-width: 64rem) { .dashboard-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 48rem) { header { padding: .85rem 1rem; } .nav-row { flex-wrap: wrap; } .desktop-navigation { display: none; } .mobile-navigation { display: block; flex: 1; min-width: 0; } .mobile-navigation > summary { display: flex; width: fit-content; min-height: var(--interactive-target-min-height); align-items: center; cursor: pointer; padding: .45rem .7rem; border: 1px solid #9fb3c8; border-radius: .25rem; color: #ffffff; font-weight: 700; } .mobile-navigation[open] > .app-navigation { display: flex; flex-direction: column; align-items: flex-start; gap: .75rem; } .mobile-navigation[open] .nav-section { flex-direction: column; align-items: flex-start; } .nav-section-admin { margin-left: 0; } .app-navigation { gap: .6rem; } .dashboard-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .metric-card { padding: .75rem; } .metric-value { font-size: clamp(1rem, 4vw, 1.4rem); overflow-wrap: anywhere; } .dashboard-columns, .dashboard-links { grid-template-columns: 1fr; } }
        @media (max-width: 48rem) {
            .desktop-public-site-navigation { display: none; }
            .mobile-public-site-navigation { display: block; position: relative; flex: 1; min-width: 0; }
            .mobile-public-site-navigation > summary { display: flex; width: fit-content; min-height: 3rem; align-items: center; margin-left: auto; cursor: pointer; padding: .5rem .75rem; border: 1px solid #9fb3c8; border-radius: .25rem; color: #ffffff; font-weight: 700; }
            .mobile-public-site-navigation > summary:focus-visible { outline-color: #f6c945; }
            .mobile-navigation > summary .menu-summary-label--open, .mobile-public-site-navigation > summary .menu-summary-label--open { display: none; }
            .mobile-navigation[open] > summary .menu-summary-label--closed, .mobile-public-site-navigation[open] > summary .menu-summary-label--closed { display: none; }
            .mobile-navigation[open] > summary .menu-summary-label--open, .mobile-public-site-navigation[open] > summary .menu-summary-label--open { display: inline; }
            .mobile-public-site-navigation[open] .public-site-navigation { position: absolute; top: calc(100% + .5rem); right: 0; z-index: 20; display: grid; grid-template-columns: 1fr; width: min(20rem, calc(100vw - 2rem)); box-sizing: border-box; gap: .35rem; padding: .5rem; border: 1px solid #9fb3c8; border-radius: .5rem; background: #102a43; box-shadow: 0 .2rem .5rem #102a4355; }
            .mobile-public-site-navigation[open] .public-site-navigation a { display: flex; min-height: 3rem; align-items: center; padding: .65rem .75rem; border: 1px solid #9fb3c8; background: #183b56; }
        }
    </style>
</head>
<body>
    <a class="skip-link" href="#main-content">本文へスキップ</a>
    <header>
        <div class="nav-row">
            <strong>OpsDeck</strong>
            @guest
                @if (request()->routeIs('home'))
                    <nav class="public-site-navigation desktop-public-site-navigation" aria-label="トップページメニュー">
                        <a href="#landing-workflow-title">使い方</a>
                        <a href="#landing-savings-title">見直し額の目安</a>
                        <a href="#landing-pricing-title">料金</a>
                        <a href="{{ route('help.index') }}">よくある質問</a>
                        <a href="{{ route('login') }}">ログイン</a>
                    </nav>
                    <details class="mobile-public-site-navigation">
                        <summary><span class="menu-summary-label--closed">ページメニューを開く</span><span class="menu-summary-label--open">ページメニューを閉じる</span></summary>
                        <nav class="public-site-navigation" aria-label="ページメニュー">
                            <a href="#landing-workflow-title">使い方</a>
                            <a href="#landing-savings-title">見直し額の目安</a>
                            <a href="#landing-pricing-title">料金</a>
                            <a href="{{ route('help.index') }}">よくある質問</a>
                            <a href="{{ route('login') }}">ログイン</a>
                        </nav>
                    </details>
                @endif
            @endguest
            @auth
                @unless (request()->routeIs('verification.notice'))
                    <div class="desktop-navigation">
                        @include('components.navigation')
                    </div>
                    <details class="mobile-navigation">
                        <summary><span class="menu-summary-label--closed">メニューを開く</span><span class="menu-summary-label--open">メニューを閉じる</span></summary>
                        @include('components.navigation')
                    </details>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">ログアウト</button>
                    </form>
                @endunless
            @endauth
        </div>
    </header>
    <main id="main-content" tabindex="-1">
        @if ($errors->any())
            <div class="errors" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                @if ($errors->has('plan_limit'))
                    @php($currentOrganization = request()->attributes->get('currentOrganization'))
                    @if ($currentOrganization instanceof \App\Models\Organization && auth()->user()?->can('manageBilling', $currentOrganization))
                        <p><a href="{{ route('billing.index') }}">料金プランと上限を確認する</a></p>
                    @else
                        <p class="muted">プラン変更は組織Ownerのみ行えます。Ownerに相談してください。</p>
                    @endif
                @endif
            </div>
        @endif
        {{ $slot }}
    </main>
</body>
</html>
