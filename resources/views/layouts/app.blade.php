<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; background: #f4f7fb; color: #172033; }
        body { margin: 0; }
        header { background: #102a43; color: #ffffff; padding: 1rem 2rem; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
        .card { background: #ffffff; border: 1px solid #bcccdc; border-radius: .5rem; padding: 1.5rem; box-shadow: 0 .1rem .3rem #102a4326; }
        label { display: block; font-weight: 700; margin-top: 1rem; }
        input, select { box-sizing: border-box; width: 100%; padding: .65rem; border: 1px solid #486581; border-radius: .25rem; color: #102a43; background: #ffffff; }
        button { cursor: pointer; margin-top: 1.25rem; padding: .65rem 1rem; border: 0; border-radius: .25rem; background: #005f73; color: #ffffff; font-weight: 700; }
        a { color: #005f73; font-weight: 700; }
        .errors { color: #9b1c1c; background: #fff5f5; border-left: .25rem solid #c53030; padding: .75rem 1rem; }
        .muted { color: #486581; }
        .nav-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        .nav-row button { margin-top: 0; background: #ffffff; color: #102a43; }
        .app-navigation { max-width: 72rem; margin: .75rem auto 0; display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; align-items: center; }
        .nav-section { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem .75rem; }
        .nav-section-label { color: #9fb3c8; font-size: .75rem; font-weight: 700; }
        .app-navigation a { color: #ffffff; font-size: .9rem; text-decoration: none; padding: .25rem .4rem; border-radius: .25rem; }
        .app-navigation a:hover, .app-navigation a:focus-visible, .app-navigation a.is-active { background: #486581; outline: none; }
        .nav-section-admin { margin-left: auto; }
        .dashboard-metrics { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .75rem; margin: 1.25rem 0; }
        .metric-card { display: block; background: #f7fafc; border: 1px solid #d9e2ec; border-radius: .45rem; padding: 1rem; text-decoration: none; color: #172033; }
        .metric-card:hover, .metric-card:focus-visible { border-color: #005f73; box-shadow: 0 0 0 2px #005f7333; outline: none; }
        .metric-label { display: block; color: #486581; font-size: .82rem; font-weight: 700; }
        .metric-value { display: block; color: #102a43; font-size: 1.65rem; line-height: 1.2; margin-top: .35rem; }
        .dashboard-columns { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(16rem, .65fr); gap: 1rem; margin-top: 1rem; }
        .dashboard-panel { background: #ffffff; border: 1px solid #bcccdc; border-radius: .5rem; padding: 1.25rem; box-shadow: 0 .1rem .3rem #102a4326; }
        .dashboard-panel h2 { margin-top: 0; }
        .dashboard-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem 1rem; }
        .dashboard-links p { margin: 0; }
        .dashboard-announcements { margin-top: 1rem; }
        .dashboard-announcements .card { max-height: 22rem; overflow-y: auto; }
        .dashboard-announcements article + article { border-top: 1px solid #d9e2ec; margin-top: 1rem; padding-top: 1rem; }
        @media (max-width: 64rem) { .dashboard-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 48rem) { header { padding: .85rem 1rem; } .nav-section-admin { margin-left: 0; } .app-navigation { gap: .6rem; } .dashboard-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .metric-card { padding: .75rem; } .metric-value { font-size: clamp(1rem, 4vw, 1.4rem); overflow-wrap: anywhere; } .dashboard-columns, .dashboard-links { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header>
        <div class="nav-row">
            <strong>OpsDeck</strong>
            @auth
                @include('components.navigation')
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">ログアウト</button>
                </form>
            @endauth
        </div>
    </header>
    <main>
        @if ($errors->any())
            <div class="errors" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        {{ $slot }}
    </main>
    <footer style="max-width:56rem;margin:2rem auto;padding:0 1rem 2rem">
        <a href="{{ route('help.index') }}">Help / FAQ</a> |
        <a href="{{ route('legal.terms') }}">利用規約</a> |
        <a href="{{ route('legal.privacy') }}">プライバシーポリシー</a> |
        <a href="{{ route('legal.commercial-transactions') }}">特商法表記</a>
    </footer>
</body>
</html>
