<x-layouts.app>
    <style>
        .landing-page { display: grid; gap: 1.25rem; }
        .landing-page h1, .landing-page h2, .landing-page h3 { color: #102a43; }
        .landing-hero { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(19rem, .85fr); gap: 2rem; align-items: center; padding: clamp(1.5rem, 5vw, 3.5rem); border-radius: .75rem; background: #102a43; color: #ffffff; }
        .landing-hero h1 { margin: .5rem 0 1rem; color: #ffffff; font-size: clamp(2rem, 4vw, 3.25rem); line-height: 1.2; }
        .landing-hero p { max-width: 46rem; color: #e6f1f7; line-height: 1.8; }
        .landing-eyebrow { color: #b8e1e8 !important; font-size: .85rem; font-weight: 800; letter-spacing: .08em; }
        .landing-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
        .landing-button { display: inline-block; padding: .8rem 1.15rem; border: 2px solid #ffffff; border-radius: .35rem; background: #ffffff; color: #102a43; font-weight: 800; text-decoration: none; }
        .landing-button-secondary { background: transparent; color: #ffffff; }
        .landing-button:hover, .landing-button:focus-visible { outline: 3px solid #8bd3dd; outline-offset: 2px; }
        .landing-summary { padding: 1rem; border: 1px solid #6b879d; border-radius: .6rem; background: #183b56; }
        .landing-summary h2 { margin-top: 0; color: #ffffff; font-size: 1.1rem; }
        .landing-summary figure { margin: 0; }
        .landing-summary img { display: block; width: 100%; height: auto; border-radius: .35rem; }
        .landing-summary figcaption { margin-top: .65rem; color: #e6f1f7; font-size: .9rem; line-height: 1.6; }
        .landing-results { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .landing-results-single { grid-template-columns: minmax(0, 1fr); }
        .landing-result { padding: clamp(1.1rem, 3vw, 1.75rem); border: 1px solid #9fb3c8; border-top: .35rem solid #005f73; border-radius: .6rem; background: #ffffff; }
        .landing-result h3 { margin-top: 0; color: #102a43; }
        .landing-result p { color: #334e68; line-height: 1.7; }
        .landing-feature-label { display: inline-block; padding: .15rem .55rem; border: 1px solid #005f73; border-radius: 999px; color: #005f73; font-size: .8rem; font-weight: 800; vertical-align: .2rem; }
        .landing-result-highlight { color: #102a43 !important; font-size: clamp(1.15rem, 2.6vw, 1.6rem); font-weight: 800; line-height: 1.45 !important; }
        .landing-time-example { padding: 1rem; border-radius: .45rem; background: #e6f6f8; color: #102a43; }
        .landing-time-example strong { display: block; margin-top: .4rem; font-size: clamp(1.3rem, 3vw, 1.9rem); }
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
            .landing-hero, .landing-grid, .landing-pricing, .landing-results { grid-template-columns: 1fr; }
            .landing-hero { gap: 1rem; }
            .landing-plan ul { min-height: auto; }
        }
    </style>

    <div class="landing-page">
        <section class="landing-hero" aria-labelledby="landing-title">
            <div>
                <p class="landing-eyebrow">IT運用の見える化から、費用の見直しへ</p>
                <h1 id="landing-title">見つける。見直す。<br>改善の手応えを、数字で残す。</h1>
                <p>社員・端末・クラウドサービス・契約をひとつに集約。更新前の契約や利用者のいないサービスなど、見逃しやすい確認候補を整理します。費用の見直し候補を試算し、確認できた削減実績を記録できます。</p>
                <div class="landing-actions">
                    <a class="landing-button" href="{{ route('register') }}">無料で台帳を整える</a>
                    <a class="landing-button landing-button-secondary" href="{{ route('login') }}">ログイン</a>
                    <a class="landing-button landing-button-secondary" href="{{ route('demo.show') }}">登録不要のデモを見る</a>
                </div>
            </div>
            <aside class="landing-summary">
                <h2>データを、見直しの判断材料に。</h2>
                <figure>
                    <img src="{{ asset('feature-guides/landing-value-flow.svg') }}" alt="社員や端末、契約などの情報を集め、費用見直し候補と確認済みの削減実績を整理する図">
                    <figcaption>費用の見直し候補は登録情報から試算し、確認済みの削減実績と分けて確認できます。</figcaption>
                </figure>
            </aside>
        </section>

        <section class="landing-results{{ config('opsdeck.release_features.work_time_tracking') ? '' : ' landing-results-single' }}" aria-label="{{ config('opsdeck.release_features.work_time_tracking') ? '費用と作業時間の効果' : '費用の見直し効果' }}">
            <article class="landing-result">
                <h2>「いくら見直せる？」を、自社データから。</h2>
                <p><span class="landing-feature-label">無料公開中</span></p>
                <p class="landing-result-highlight">年間の見直し候補額を、契約と利用状況から試算。</p>
                <p>更新が近い契約、利用者が登録されていないクラウドサービス、対象となる未割当端末を一覧で確認。担当者が詳細と契約条件を確かめ、次のアクションを判断できます。</p>
                <p class="landing-note">金額は入力情報と設定条件に基づく候補額です。削減実績や削減保証ではありません。</p>
                <p><a href="#landing-pricing-title">無料で使える機能を見る</a></p>
            </article>
            @if (config('opsdeck.release_features.work_time_tracking'))
                <article class="landing-result">
                    <h2>「作業時間がどれだけ変わった？」を、前後の記録で。</h2>
                    <p><span class="landing-feature-label">無料公開中</span></p>
                    <p>改善前・改善後の作業時間と、月あたりの回数を記録すると、月間の時間差を参考値として計算できます。</p>
                    <div class="landing-time-example" aria-label="作業時間の入力例">
                        入力例：1回30分から10分に短縮し、月4回行う場合
                        <strong>(30分 − 10分) × 4回 ＝ 月80分短縮</strong>
                    </div>
                    <p class="landing-note">これは入力例です。OpsDeckが作業時間を自動計測するものではなく、実際の効果は担当者が記録した値によって異なります。</p>
                </article>
            @endif
        </section>

        <section class="card landing-section" aria-labelledby="landing-workflow-title">
            <h2 id="landing-workflow-title">今の管理表から、次の改善アクションまで</h2>
            <div class="landing-grid">
                <article class="landing-tile">
                    <h3>1. 今の管理表を活用</h3>
                    <p>社員、端末、クラウドサービス、契約などの表から始められます。CSV/TXTとExcel（.xlsx）に対応しています。古いExcel形式（.xls）とGoogle Sheets連携は現在対象外です。</p>
                </article>
                <article class="landing-tile">
                    <h3>2. 確認してから登録</h3>
                    <p>取込前にプレビューし、列の対応と新規・更新・確認待ち・エラーを確認できます。AIの列候補も自動確定せず、担当者が確認して反映します。プレビューだけではデータは変わりません。</p>
                </article>
                <article class="landing-tile">
                    <h3>3. 次の対応と費用を把握</h3>
                    <p>契約更新、書類の期限、未割当の端末やサービスを確認。見直し候補額を確かめ、実際に削減できた金額は根拠とともに記録できます。</p>
                </article>
            </div>
        </section>

        <section class="card landing-section" aria-labelledby="landing-capabilities-title">
            <h2 id="landing-capabilities-title">IT運用の情報をまとめ、次の対応につなげる</h2>
            <div class="landing-grid">
                <article class="landing-tile">
                    <h3>会社の台帳を一元管理</h3>
                    <p>社員・部署、端末、クラウドサービス、契約と利用者の割当状況をまとめて確認できます。</p>
                </article>
                <article class="landing-tile">
                    <h3>期限と確認漏れを見つける</h3>
                    <p>契約の更新、書類の有効期限、セキュリティ確認、取引先の質問票など、対応が必要な項目を追えます。</p>
                </article>
                <article class="landing-tile">
                    <h3>対応の根拠と結果を残す</h3>
                    <p>取込履歴、確認済みの削減実績、週次サマリーで、状況を振り返れます。</p>
                </article>
            </div>
            <p class="landing-note">現在公開している機能はすべて無料で利用できます。Google Sheets連携など、まだ提供していない機能は対象外です。</p>
        </section>

        <section class="card landing-section" aria-labelledby="landing-savings-title">
            <h2 id="landing-savings-title">見直し候補を、3つの切り口で具体化</h2>
            <p class="landing-note">登録された契約・クラウドサービス・端末と、組織ごとの設定条件から年間候補額を試算します。何を確認すべきかも一覧でたどれます。</p>
            <div class="landing-grid">
                <article class="landing-tile">
                    <h3>更新が近い契約</h3>
                    <p>対象となる契約の年額と会社ごとの見直し条件から、更新前に検討する金額の目安を表示します。</p>
                </article>
                <article class="landing-tile">
                    <h3>利用者が割り当てられていないサービス</h3>
                    <p>利用者の登録状況と継続契約の費用から、利用実態や契約条件を確認したいサービスを見つけます。</p>
                </article>
                <article class="landing-tile">
                    <h3>使われていない可能性のある端末</h3>
                    <p>割当状況や端末の状態から確認候補を表示し、会社で設定した月額目安をもとに費用を試算します。</p>
                </article>
            </div>
            <p class="landing-note">表示額は見直し候補の試算で、削減実績や削減保証ではありません。実際に見直せるかは利用状況や契約条件を担当者が確認してください。契約交渉・解約・サービス停止は自動で行わず、確認済みの削減実績は別に記録します。</p>
        </section>

        <section class="card landing-section" aria-labelledby="landing-pricing-title">
            <h2 id="landing-pricing-title">OpsDeckの機能を、すべて無料で公開中</h2>
            <p class="landing-note">プランによる機能制限や追加料金はありません。現在提供中の機能を無料で利用できます。</p>
            <div class="landing-pricing">
                <article class="landing-plan">
                    <h3>OpsDeck</h3>
                    <p class="landing-price">月額0円</p>
                    <ul>
                        <li>社員{{ number_format((int) $plans['free']['limits']['employees']) }}名・端末{{ number_format((int) $plans['free']['limits']['assets']) }}台・SaaS{{ number_format((int) $plans['free']['limits']['saas_services']) }}件まで</li>
                        @foreach ($plans['free']['features'] as $feature)
                            <li>{{ $feature }}</li>
                        @endforeach
                        <li>管理者{{ number_format((int) $plans['free']['limits']['administrators']) }}名まで</li>
                        <li>月額料金・追加容量料金・自動課金なし</li>
                    </ul>
                    <a class="landing-button" href="{{ route('register') }}">無料で始める</a>
                </article>
            </div>
            <p class="landing-note">削減候補額は入力情報と設定条件に基づく試算です。実際の削減を保証するものではありません。</p>
        </section>

        <section class="card landing-section" aria-labelledby="landing-scope-title">
            <h2 id="landing-scope-title">OpsDeckがすること・しないこと</h2>
            <p class="landing-note">OpsDeckはIT資産や契約を整理し、期限と対応状況を追うための運用台帳です。MDM／EDR、端末の遠隔操作、社内システムそのものの置き換えは提供しません。既存のExcel形式が分からない場合は、サンプルとプレビューから確認できます。</p>
            <p><a href="{{ route('help.index') }}">サービスのFAQを見る</a></p>
            <p>お問い合わせ：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
        </section>
    </div>
</x-layouts.app>
