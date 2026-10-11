<x-layouts.app>
    <style>
        .landing-page { display: grid; gap: 1.25rem; }
        .landing-page h1, .landing-page h2, .landing-page h3 { color: #102a43; }
        .landing-hero { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(16rem, .7fr); gap: 2rem; align-items: center; padding: clamp(1.5rem, 5vw, 3.5rem); border-radius: .75rem; background: #102a43; color: #ffffff; }
        .landing-hero h1 { margin: .5rem 0 1rem; color: #ffffff; font-size: clamp(2rem, 4vw, 3.25rem); line-height: 1.2; }
        .landing-hero p { max-width: 46rem; color: #e6f1f7; line-height: 1.8; }
        .landing-eyebrow { color: #b8e1e8 !important; font-size: .85rem; font-weight: 800; letter-spacing: .08em; }
        .landing-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
        .landing-button { display: inline-block; padding: .8rem 1.15rem; border: 2px solid #ffffff; border-radius: .35rem; background: #ffffff; color: #102a43; font-weight: 800; text-decoration: none; }
        .landing-button-secondary { background: transparent; color: #ffffff; }
        .landing-button:hover, .landing-button:focus-visible { outline: 3px solid #8bd3dd; outline-offset: 2px; }
        .landing-summary { padding: 1.25rem; border: 1px solid #6b879d; border-radius: .6rem; background: #183b56; }
        .landing-summary h2 { margin-top: 0; color: #ffffff; font-size: 1.1rem; }
        .landing-summary ul { margin-bottom: 0; padding-left: 1.25rem; color: #e6f1f7; line-height: 1.9; }
        .landing-section { padding: clamp(1.25rem, 3vw, 2rem); }
        .landing-section > h2 { margin-top: 0; }
        .landing-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
        .landing-tile { padding: 1.1rem; border: 1px solid #bcccdc; border-radius: .5rem; background: #ffffff; }
        .landing-tile h3 { margin-top: 0; }
        .landing-tile p, .landing-note { color: #334e68; line-height: 1.7; }
        .landing-pricing { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .landing-plan { padding: 1.4rem; border: 1px solid #9fb3c8; border-radius: .6rem; background: #ffffff; }
        .landing-plan-premium { border: 2px solid #005f73; }
        .landing-plan h3 { margin-top: 0; }
        .landing-price { margin: .5rem 0 1rem; color: #102a43; font-size: 1.7rem; font-weight: 800; }
        .landing-plan ul { min-height: 8rem; padding-left: 1.25rem; color: #334e68; line-height: 1.8; }
        .landing-plan .landing-button { border-color: #005f73; background: #005f73; color: #ffffff; }
        .landing-plan-premium .landing-button { border-color: #102a43; background: #102a43; }
        @media (max-width: 48rem) {
            .landing-hero, .landing-grid, .landing-pricing { grid-template-columns: 1fr; }
            .landing-hero { gap: 1rem; }
            .landing-plan ul { min-height: auto; }
        }
    </style>

    <div class="landing-page">
        <section class="landing-hero" aria-labelledby="landing-title">
            <div>
                <p class="landing-eyebrow">会社のIT運用を、見える台帳から整える</p>
                <h1 id="landing-title">社員・端末・SaaS・契約を、ひとつの場所で管理。</h1>
                <p>ExcelやCSVに散らばった情報をまとめ、誰が何を使っているか、契約更新はいつか、何を確認すべきかを見えるようにします。まずは無料プランで台帳とコストを確認できます。</p>
                <div class="landing-actions">
                    <a class="landing-button" href="{{ route('register') }}">無料で始める</a>
                    <a class="landing-button landing-button-secondary" href="{{ route('login') }}">ログイン</a>
                    <a class="landing-button landing-button-secondary" href="{{ route('demo.show') }}">デモ画面を見る（登録不要）</a>
                </div>
            </div>
            <aside class="landing-summary" aria-label="OpsDeckでできること">
                <h2>OpsDeckでできること</h2>
                <ul>
                    <li>社員・IT資産・SaaS・契約の台帳化</li>
                    <li>更新期限、未割当、要対応項目の確認</li>
                    <li>Import結果と導入効果の記録</li>
                </ul>
            </aside>
        </section>

        <section class="card landing-section" aria-labelledby="landing-workflow-title">
            <h2 id="landing-workflow-title">導入後の流れ</h2>
            <div class="landing-grid">
                <article class="landing-tile">
                    <h3>1. 既存の表を準備</h3>
                    <p>社員、端末、SaaS、契約など、今使っている管理表を活用します。CSVのサンプルと取込前プレビューで列を確認できます。</p>
                </article>
                <article class="landing-tile">
                    <h3>2. 内容を確認して取り込む</h3>
                    <p>新規・更新・確認待ち・エラーを見てから実行します。AIの列候補も自動確定せず、人が確認して反映します。</p>
                </article>
                <article class="landing-tile">
                    <h3>3. 期限と対応状況を確認</h3>
                    <p>契約更新、証跡期限、未割当の資産やSaaSなどを一覧で確認し、対応履歴や導入効果を記録できます。</p>
                </article>
            </div>
        </section>

        <section class="card landing-section" aria-labelledby="landing-pricing-title">
            <h2 id="landing-pricing-title">プラン</h2>
            <div class="landing-pricing">
                <article class="landing-plan">
                    <h3>Free</h3>
                    <p class="landing-price">{{ number_format((int) $plans['free']['monthly_price_jpy']) }}円</p>
                    <ul>
                        <li>社員{{ number_format((int) $plans['free']['limits']['employees']) }}名・端末{{ number_format((int) $plans['free']['limits']['assets']) }}台・SaaS{{ number_format((int) $plans['free']['limits']['saas_services']) }}件まで</li>
                        @foreach ($plans['free']['features'] as $feature)
                            <li>{{ $feature }}</li>
                        @endforeach
                        <li>管理者{{ number_format((int) $plans['free']['limits']['administrators']) }}名</li>
                    </ul>
                    <a class="landing-button" href="{{ route('register') }}">Freeで始める</a>
                </article>
                <article class="landing-plan landing-plan-premium">
                    <h3>Premium</h3>
                    <p class="landing-price">月額{{ number_format((int) $plans['premium']['monthly_price_jpy']) }}円</p>
                    <ul>
                        <li>社員{{ number_format((int) $plans['premium']['limits']['employees']) }}名・端末{{ number_format((int) $plans['premium']['limits']['assets']) }}台・SaaS{{ number_format((int) $plans['premium']['limits']['saas_services']) }}件まで</li>
                        @foreach ($plans['premium']['features'] as $feature)
                            <li>{{ $feature }}</li>
                        @endforeach
                        <li>管理者{{ number_format((int) $plans['premium']['limits']['administrators']) }}名まで</li>
                    </ul>
                    <a class="landing-button" href="{{ route('register') }}">登録後にPremiumを申し込む</a>
                </article>
            </div>
            <p class="landing-note">料金の税区分や適用条件は申込画面をご確認ください。導入効果レポートの削減額は入力情報に基づく試算であり、削減を保証するものではありません。</p>
        </section>

        <section class="card landing-section" aria-labelledby="landing-scope-title">
            <h2 id="landing-scope-title">OpsDeckがすること・しないこと</h2>
            <p class="landing-note">OpsDeckはIT資産や契約を整理し、期限と対応状況を追うための運用台帳です。MDM／EDR、端末の遠隔操作、社内システムそのものの置き換えは提供しません。既存のExcel形式が分からない場合は、サンプルとプレビューから確認できます。</p>
            <p><a href="{{ route('help.index') }}">サービスのFAQを見る</a>　<a href="{{ route('legal.commercial-transactions') }}">特定商取引法に基づく表記</a></p>
        </section>
    </div>
</x-layouts.app>
