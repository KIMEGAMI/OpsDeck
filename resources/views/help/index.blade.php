<x-layouts.app>
    <section class="card" aria-labelledby="help-title">
        <h1 id="help-title">使い方・よくある質問</h1>
        <p class="muted">管理表の取込や、社員・端末・クラウドサービスの管理方法を確認できます。</p>
        <p><a href="{{ route('support.create') }}">FAQで解決しない場合はお問い合わせ</a></p>
        <p>メールでのお問い合わせ：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
        <nav class="help-quick-nav" aria-label="ヘルプ内の目次">
            <ul>
                <li><a href="#help-start-title">初めて使う</a></li>
                <li><a href="#help-excel-csv-title">Excelを取り込む</a></li>
                <li><a href="#help-savings-title">費用削減を見る</a></li>
                <li><a href="#help-recovery-title">困ったとき</a></li>
                <li><a href="#help-plans-title">無料公開と登録上限</a></li>
            </ul>
        </nav>
        <section aria-labelledby="help-start-title">
            <h2 id="help-start-title">初めて使うとき</h2>
            <ol>
                <li><a href="{{ route('demo.show') }}">読み取り専用デモ</a>で、登録前に画面や取込例を確認できます。入力・保存・送信はありません。</li>
                <li><a href="{{ route('onboarding.index') }}">導入チェックリスト</a>から、組織設定と必要な初期登録を順番に進めます。</li>
                <li><a href="{{ route('imports.create') }}">データ取込</a>を開き、社員・端末などのサンプルプレビューで、必要な列を確認します。</li>
                <li>実際のデータを使うときは、元のExcelを残したままコピーを作ります。CSVは表の内容を保存するファイル形式です。コピーから必要なシートをCSV、またはXLSXとして別名保存します。</li>
                <li>取込前の確認画面で、列の対応と「新規・更新・確認待ち・エラー」を確認します。確認画面を見るだけでは業務データは登録・更新されません。CSV/TXTとXLSXを利用でき、社員・資産・SaaSの登録数は各上限までです。Google Sheets連携は現在提供していません。</li>
            </ol>
            <p class="muted">サンプルCSVは実データではありません。サンプル行を「データ取込」すると会社の情報として登録されるため、動作確認には「サンプルプレビュー」を使ってください。</p>
        </section>
        <section aria-labelledby="help-excel-csv-title">
            <h2 id="help-excel-csv-title">Excelの表をCSVにして取り込む</h2>
            <ol>
                <li>元のExcelファイルは残します。コピーを作り、そのコピーを開きます。</li>
                <li>コピーの中で、取り込みたいシート（例：社員名簿）を選びます。</li>
                <li>「ファイル」から「名前を付けて保存」または「コピーを保存」を選び、元とは別の名前と場所を指定します。</li>
                <li>保存形式に「CSV UTF-8（コンマ区切り）」があれば選びます。ない場合は「CSV（コンマ区切り）」を選びます。表示名はExcelの版によって異なります。</li>
                <li>「現在のシートだけが保存される」という確認が出たら、選んだシートが正しいことを確認して続けます。CSVには書式や数式ではなく、セルに表示されている値が保存されます。</li>
                <li>OpsDeckで保存したCSVを選び、「内容を確認する」を押します。列や新規・更新・エラーを確認し、問題がなければ取込を実行します。</li>
            </ol>
            <p>複数シートを取り込む場合は、CSVではシートごとに保存します。XLSXファイルでは、取り込むシートを画面で指定できます。</p>
            <p><a href="https://support.microsoft.com/en-us/excel/save-a-workbook-to-text-format-txt-or-csv">MicrosoftのCSV保存手順</a></p>
        </section>
        <section aria-labelledby="help-savings-title">
            <h2 id="help-savings-title">費用削減額の見方</h2>
            <p>OpsDeckは登録された契約・クラウドサービス・端末などの情報から、月の費用や見直し候補の概算を表示します。概算は実際の削減額ではなく、契約の解約やライセンス停止も自動では行いません。</p>
            <p>実際に削減できた金額は、担当者が根拠を確認して記録します。詳しくは<a href="{{ route('costs.dashboard') }}">コストダッシュボード</a>を確認してください。</p>
        </section>
        <section aria-labelledby="help-recovery-title">
            <h2 id="help-recovery-title">困ったとき</h2>
            <p>問題に近い項目を選ぶと、確認する順番が表示されます。</p>
            <ul>
                @foreach ($recoveryGuides as $type => $guide)
                    <li><a href="{{ route('help.recovery', $type) }}">{{ $guide['title'] }}</a></li>
                @endforeach
            </ul>
        </section>
        <section aria-labelledby="help-plans-title">
            <h2 id="help-plans-title">無料公開中の機能と登録上限</h2>
            <p>現在提供している機能はすべて無料で利用できます。プランによる機能差や追加料金はありません。</p>
            <div class="table-scroll help-plan-table-scroll">
                <table aria-label="無料公開中のOpsDeckの登録上限">
                    <thead>
                        <tr><th scope="col">利用料金</th><th scope="col">登録上限</th><th scope="col">主な機能</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">月額0円</th>
                            <td data-label="登録上限">社員{{ number_format((int) $plans['free']['limits']['employees']) }}名・端末{{ number_format((int) $plans['free']['limits']['assets']) }}台・クラウドサービス{{ number_format((int) $plans['free']['limits']['saas_services']) }}件・管理者{{ number_format((int) $plans['free']['limits']['administrators']) }}名</td>
                            <td data-label="主な機能">
                                <ul>
                                    @foreach ($plans['free']['features'] as $feature)
                                        <li>{{ $feature }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="muted">OpsDeckから新しい有料契約や追加容量を申し込むことはできません。過去に登録したStripe契約が残っている場合、契約状態や請求はStripe側で確認するか、<a href="mailto:{{ config('opsdeck.support_email') }}">サポートへお問い合わせください</a>。Google Sheets連携など、現在提供していない機能は利用対象外です。</p>
        </section>
        @foreach ($faqs as $faq)
            <details class="help-faq">
                <summary>{{ $faq['question'] }}</summary>
                <p>{{ $faq['answer'] }}</p>
            </details>
        @endforeach
    </section>
</x-layouts.app>
