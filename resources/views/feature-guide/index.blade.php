<x-layouts.app>
    <style>
        .feature-guide-intro { border-left: .35rem solid #005f73; }
        .feature-guide-intro p { max-width: 62rem; }
        .feature-guide-index { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: .75rem; padding: 0; list-style: none; }
        .feature-guide-index a { display: block; height: 100%; padding: 1rem; border: 1px solid #9fb3c8; border-radius: .4rem; background: #f7fafc; text-decoration: none; }
        .feature-guide-index a:hover, .feature-guide-index a:focus-visible { border-color: #005f73; background: #e6f6f8; }
        .feature-guide-section { margin-top: 1.25rem; }
        .feature-guide-content { display: grid; grid-template-columns: minmax(0, 1fr) minmax(18rem, .9fr); gap: 1.25rem; align-items: start; }
        .feature-guide-content figure { margin: 0; padding: .75rem; border: 1px solid #bcccdc; border-radius: .4rem; background: #f4f7fb; }
        .feature-guide-content img { display: block; width: 100%; height: auto; }
        .feature-guide-content figcaption, .feature-guide-note { color: #334e68; font-size: .95rem; }
        .feature-guide-steps { padding-left: 1.5rem; }
        .feature-guide-steps li + li { margin-top: .65rem; }
        .feature-guide-plan { display: inline-block; margin-left: .25rem; padding: .1rem .45rem; border: 1px solid #486581; border-radius: 999px; color: #102a43; background: #e6f6f8; font-size: .85rem; font-weight: 700; }
        .feature-guide-subsection { padding-top: .5rem; border-top: 1px solid #d9e2ec; }
        .feature-guide-admin { border-color: #9fb3c8; background: #f7fafc; }
        @media (max-width: 48rem) { .feature-guide-content { grid-template-columns: 1fr; } }
    </style>

    <section class="card feature-guide-intro" aria-labelledby="feature-guide-title">
        <h1 id="feature-guide-title">機能ガイド</h1>
        <p>「何をしたいか」から、開くメニューと操作の順番を確認できます。画面の画像は、会社の実データを使わない操作イメージです。</p>
        <p>よくある質問や不具合時の確認手順は、別メニューの<a href="{{ route('help.index') }}">使い方・よくある質問</a>をご覧ください。</p>
        <p>操作で困ったとき：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
    </section>

    <section class="card feature-guide-section" aria-labelledby="feature-guide-index-title">
        <h2 id="feature-guide-index-title">目的を選ぶ</h2>
        <ul class="feature-guide-index">
            <li><a href="#dashboard-guide">今日やることを確認する</a></li>
            <li><a href="#import-guide">Excel・CSVを登録する</a></li>
            <li><a href="#records-guide">社員・端末・サービス・契約を管理する</a></li>
            <li><a href="#cost-guide">費用を見直す・削減実績を記録する</a></li>
            <li><a href="#security-guide">セキュリティ・証跡・質問票を管理する</a></li>
            <li><a href="#report-guide">状況をまとめて共有する</a></li>
            <li><a href="#organization-guide">メンバーや通知を設定する</a></li>
        </ul>
    </section>

    <section id="dashboard-guide" class="card feature-guide-section" aria-labelledby="dashboard-guide-title">
        <h2 id="dashboard-guide-title">1. 今日やることを確認する</h2>
        <div class="feature-guide-content">
            <div>
                <ol class="feature-guide-steps">
                    <li>メニューの「基本」から「ダッシュボード」を開きます。</li>
                    <li>社員・端末・クラウドサービス・契約のカードを選ぶと、該当する一覧へ移動できます。</li>
                    <li>画面の「確認が必要な項目」から、更新期限、取込エラー、未割当などを順に確認します。</li>
                    <li>まだ情報を登録していない場合は、「Excelなどの取込」から管理表を登録します。</li>
                </ol>
                <p><a href="{{ route('dashboard') }}">ダッシュボードを開く</a></p>
            </div>
            <figure>
                <img src="{{ asset('feature-guides/dashboard-overview.svg') }}" alt="ダッシュボードから管理一覧と確認が必要な項目へ進む操作イメージ">
                <figcaption>概要カードで全体を見て、確認が必要な項目へ進みます。</figcaption>
            </figure>
        </div>
    </section>

    <section id="import-guide" class="card feature-guide-section" aria-labelledby="import-guide-title">
        <h2 id="import-guide-title">2. Excel・CSVの管理表を登録する</h2>
        <div class="feature-guide-content">
            <div>
                <ol class="feature-guide-steps">
                    <li>メニューの「運用」から「Excelなどの取込」を開き、「管理表を取り込む前に確認する」を選びます。</li>
                    <li>取込名を付け、社員・端末・契約などの種類を選びます。元の表は残して、取込用のコピーを使います。</li>
                    <li>FreeではCSV/TXT、PremiumではXLSXを選べます。Google Sheets連携と古いExcel形式（.xls）は1.0では対象外です。</li>
                    <li>列名がある行とデータの開始行を確認し、「内容を確認する」を押します。この時点では会社のデータは登録・変更されません。</li>
                    <li>プレビューで列の対応、新規・更新・確認待ち・エラーを見ます。Premiumでは列名の対応候補をAIに提案させることもできます。候補は自動確定されないため、確認・修正して保存します。</li>
                    <li>内容に問題がなければ取込を実行し、「データの取込履歴」から結果を確認します。</li>
                </ol>
                <p><a href="{{ route('imports.create') }}">管理表の取込画面を開く</a> ／ <a href="{{ route('imports.index') }}">取込履歴を見る</a></p>
                <p class="feature-guide-note">CSVはExcelの表を保存できるファイル形式です。FreeでExcelを使う場合は、コピーした表をCSV UTF-8で保存してください。まず試すときはサンプルの「取込なしでプレビュー」を使い、見本ファイルを実データとして登録しないでください。</p>
            </div>
            <figure>
                <img src="{{ asset('feature-guides/import-flow.svg') }}" alt="管理表の選択、内容のプレビュー、確認後の取込という流れのイメージ">
                <figcaption>ファイルを選んだ後、内容を確認してから登録します。</figcaption>
            </figure>
        </div>
    </section>

    <section id="records-guide" class="card feature-guide-section" aria-labelledby="records-guide-title">
        <h2 id="records-guide-title">3. 社員・端末・クラウドサービス・契約を管理する</h2>
        <div class="feature-guide-content">
            <div>
                <div class="feature-guide-subsection">
                    <h3>社員と部署</h3>
                    <ol class="feature-guide-steps">
                        <li>「基本」→「社員」を開き、「社員を登録する」から追加します。部署や在籍状況を登録すると、割当確認に使えます。</li>
                        <li>登録済みの社員を一覧から開き、詳細を確認・更新します。人数が多い場合は、Excelなどの取込を利用できます。</li>
                    </ol>
                </div>
                <div class="feature-guide-subsection">
                    <h3>端末・クラウドサービスと利用者</h3>
                    <ol class="feature-guide-steps">
                        <li>「基本」→「端末・資産」または「クラウドサービス」を開き、登録画面から管理情報を追加します。</li>
                        <li>端末やサービスの詳細を開き、利用者・割当状況を確認します。担当変更や返却があれば、詳細画面から情報を更新します。</li>
                        <li>一覧で「要確認」などに絞り込み、未割当や退職した社員にひも付くデータを確認します。</li>
                    </ol>
                </div>
                <div class="feature-guide-subsection">
                    <h3>契約</h3>
                    <ol class="feature-guide-steps">
                        <li>「基本」→「契約」から契約名、取引先、費用、契約期間、更新条件を登録します。</li>
                        <li>契約一覧や「費用の見直し」で終了日・更新時期を確認し、更新前に担当者が契約内容を見直します。</li>
                    </ol>
                </div>
                <p><a href="{{ route('employees.index') }}">社員</a> ／ <a href="{{ route('assets.index') }}">端末・資産</a> ／ <a href="{{ route('saas-services.index') }}">クラウドサービス</a> ／ <a href="{{ route('contracts.index') }}">契約</a></p>
            </div>
            <figure>
                <img src="{{ asset('feature-guides/records-flow.svg') }}" alt="社員、端末、クラウドサービス、契約を台帳として管理するイメージ">
                <figcaption>社員・資産・サービス・契約を登録し、利用者や期限を確認します。</figcaption>
            </figure>
        </div>
    </section>

    <section id="cost-guide" class="card feature-guide-section" aria-labelledby="cost-guide-title">
        <h2 id="cost-guide-title">4. 費用を見直し、削減実績を記録する</h2>
        <p class="feature-guide-note">削減候補の概算と削減実績の記録はPremiumです。Freeでは登録した契約の月額費用を確認できます。</p>
        <div class="feature-guide-content">
            <div>
                <ol class="feature-guide-steps">
                    <li>メニューの「運用」から「費用の見直し」を開きます。</li>
                    <li>更新が近い継続契約、利用者が未登録のサービス、未割当などの端末を見て、候補の詳細を開きます。</li>
                    <li>利用状況や契約条件を担当者が確認します。画面の金額は登録情報と設定条件に基づく見直し候補の概算です。</li>
                    <li>実際に削減できたことを確認したら、「削減実績を記録」から内容・金額・期間・確認日を入力します。可能な場合は根拠資料も関連付けます。</li>
                </ol>
                <p><a href="{{ route('costs.dashboard') }}">費用の見直しを開く</a></p>
                <p class="feature-guide-note">候補額は削減実績や保証額ではありません。契約交渉、解約、ライセンス停止、端末の処分は自動で行われません。担当者が内容を判断してから対応してください。</p>
            </div>
            <figure>
                <img src="{{ asset('feature-guides/savings-review.svg') }}" alt="削減候補の確認と、確認済みの削減実績記録を分けて表示するイメージ">
                <figcaption>見直し候補と、確認して記録した実績を分けて扱います。</figcaption>
            </figure>
        </div>
    </section>

    <section id="security-guide" class="card feature-guide-section" aria-labelledby="security-guide-title">
        <h2 id="security-guide-title">5. セキュリティ・証跡・質問票を管理する</h2>
        <div class="feature-guide-content">
            <div>
                <ol class="feature-guide-steps">
                    <li>Premiumの「セキュリティ確認」で管理項目を登録し、対応状況と次回確認日を更新します。</li>
                    <li>「書類・確認資料」にポリシーや証明資料を登録し、種別と有効期限を入力します。必要な資料は管理項目とあわせて確認します。</li>
                    <li>「質問票」で取引先から受け取った質問票を登録します。登録情報・証跡を確認しながら回答案を入力し、実際の運用状況を人が確認してから承認します。</li>
                    <li>期限や未承認の回答は、ダッシュボードの確認項目から見直します。</li>
                </ol>
                <p class="feature-guide-note">セキュリティ管理、証跡、質問票の機能はPremiumです。メニューに表示されない場合は、プランと組織の権限を確認してください。作成した回答案は、根拠と内容を人が確認してから承認してください。</p>
                @php($currentOrganization = request()->attributes->get('currentOrganization'))
                @if ($currentOrganization && app(\App\Services\PlanFeatureService::class)->isAvailable($currentOrganization, 'security_controls'))
                    <p><a href="{{ route('security-controls.index') }}">セキュリティ確認を開く</a>
                        @if (app(\App\Services\PlanFeatureService::class)->isAvailable($currentOrganization, 'evidence'))
                            ／ <a href="{{ route('evidence.index') }}">書類・確認資料を開く</a>
                        @endif
                        @if (app(\App\Services\PlanFeatureService::class)->isAvailable($currentOrganization, 'questionnaires'))
                            ／ <a href="{{ route('questionnaires.index') }}">質問票を開く</a>
                        @endif
                    </p>
                @endif
            </div>
            <figure>
                <img src="{{ asset('feature-guides/security-flow.svg') }}" alt="セキュリティ項目、証跡資料、取引先質問票を確認するイメージ">
                <figcaption>対応状況と資料の期限、質問票の承認状態を確認します。</figcaption>
            </figure>
        </div>
    </section>

    <section id="report-guide" class="card feature-guide-section" aria-labelledby="report-guide-title">
        <h2 id="report-guide-title">6. 状況をまとめて共有する</h2>
        <div class="feature-guide-content">
            <div>
                <ol class="feature-guide-steps">
                    <li>同じ条件で何度も確認する一覧は、「保存した一覧」で保存します。</li>
                    <li>直近の取込や更新期限などは、「週次まとめ」で期間ごとに確認します。</li>
                    <li>保存した一覧と週次まとめで、確認内容を共有します。導入効果レポートは1.0の公開対象外です。</li>
                    <li>必要に応じて、権限のある管理者がCSVを書き出します。</li>
                </ol>
                <p><a href="{{ route('saved-views.index') }}">保存した一覧</a> ／ <a href="{{ route('weekly-summary.show') }}">週次まとめ</a>
                    @php($currentOrganization = request()->attributes->get('currentOrganization'))
                    @if ($currentOrganization && app(\App\Services\PlanFeatureService::class)->isAvailable($currentOrganization, 'impact_report'))
                        ／ <a href="{{ route('impact-report.show') }}">導入効果</a>
                    @endif
                    @if ($currentOrganization && request()->user()->can('export', $currentOrganization) && app(\App\Services\PlanFeatureService::class)->isAvailable($currentOrganization, 'exports'))
                        ／ <a href="{{ route('exports.index') }}">データの書き出し</a>
                    @endif
                </p>
                <p class="feature-guide-note">CSV書き出しはPremiumです。高度な導入効果レポートは1.0の公開対象外です。</p>
            </div>
            <figure>
                <img src="{{ asset('feature-guides/report-flow.svg') }}" alt="保存した一覧と週次まとめを確認し、権限のある管理者がCSVを書き出す流れ">
                <figcaption>日々の確認内容は保存した一覧や週次まとめで整理し、権限のある管理者がCSVで共有できます。</figcaption>
            </figure>
        </div>
    </section>

    <section id="organization-guide" class="card feature-guide-section" aria-labelledby="organization-guide-title">
        <h2 id="organization-guide-title">7. メンバーや通知を設定する</h2>
        <div class="feature-guide-content">
            <div>
                <ol class="feature-guide-steps">
                    <li>「組織」→「メンバー」を開き、招待する人のメールアドレスと権限を設定して招待します。</li>
                    <li>組織情報や費用試算の条件を変える場合は「組織設定」を開きます。組織の設定権限が必要です。</li>
                    <li>プランの確認・変更は「プラン・お支払い」から行います。契約担当者のメニューに表示されます。</li>
                    <li>週次まとめやお知らせメールの受信設定は、各画面の設定を確認します。</li>
                </ol>
                <p><a href="{{ route('settings.members') }}">メンバーを確認する</a>
                    @php($currentOrganization = request()->attributes->get('currentOrganization'))
                    @if ($currentOrganization && request()->user()->can('update', $currentOrganization))
                        ／ <a href="{{ route('settings.organization') }}">組織設定</a>
                    @endif
                    @if ($currentOrganization && request()->user()->can('manageBilling', $currentOrganization))
                        ／ <a href="{{ route('billing.index') }}">プラン・お支払い</a>
                    @endif
                </p>
            </div>
            <figure>
                <img src="{{ asset('feature-guides/organization-flow.svg') }}" alt="組織のメンバー招待、役割設定、プラン確認のイメージ">
                <figcaption>招待とプラン変更は、組織の担当者が権限を確認して行います。</figcaption>
            </figure>
        </div>
        <p class="feature-guide-note">OpsDeck運営用の「管理者」メニューは、運営管理者だけに表示されます。会社の組織管理メニューとは別の機能です。</p>
    </section>

    <section class="card feature-guide-section feature-guide-admin" aria-labelledby="feature-guide-next-title">
        <h2 id="feature-guide-next-title">次に進む</h2>
        <p>最初に登録する場合は、管理表をプレビューして、列や表示内容に間違いがないか確認してください。</p>
        <p><a href="{{ route('imports.create') }}">Excel・CSVの取込を始める</a> ／ <a href="{{ route('help.index') }}">使い方・よくある質問を見る</a></p>
    </section>
</x-layouts.app>
