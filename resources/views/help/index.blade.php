<x-layouts.app>
    <section class="card" aria-labelledby="help-title">
        <h1 id="help-title">Help / FAQ</h1>
        <p class="muted">OpsDeckの使い方と対応範囲を確認できます。</p>
        <p><a href="{{ route('support.create') }}">FAQで解決しない場合はお問い合わせ</a></p>
        <p>メールでのお問い合わせ：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
        <section aria-labelledby="help-start-title">
            <h2 id="help-start-title">初めて使うとき</h2>
            <ol>
                <li><a href="{{ route('onboarding.index') }}">導入チェックリスト</a>から、組織設定と必要な初期登録を順番に進めます。</li>
                <li><a href="{{ route('imports.create') }}">Migration Studio</a>で社員・資産などのサンプルCSVを確認し、元のExcelを変更せずコピーしたCSVまたは対応プランのXLSXをプレビューします。</li>
                <li>Previewで列対応・新規・更新・確認待ち・エラーを確認します。Previewだけでは業務データは登録・更新されません。FreeプランはCSVプレビューまで、業務データへのImport実行はPremiumプランです。</li>
            </ol>
            <p class="muted">サンプルCSVは実データではありません。サンプル行をImportすると業務データとして登録されるため、動作確認にはサンプルプレビューを使ってください。</p>
        </section>
        <section aria-labelledby="help-savings-title">
            <h2 id="help-savings-title">費用削減額の見方</h2>
            <p>OpsDeckは登録された契約・SaaS・資産情報から月次コストや見直し候補の概算を表示します。概算は実際の削減額ではなく、契約の解約やライセンス停止も自動では行いません。</p>
            <p>実際に削減できた金額は、担当者が根拠を確認して記録します。詳しくは<a href="{{ route('costs.dashboard') }}">コストダッシュボード</a>を確認してください。</p>
        </section>
        @foreach ($faqs as $faq)
            <article style="border-top:1px solid #bcccdc;padding:.75rem 0">
                <h2>{{ $faq['question'] }}</h2>
                <p>{{ $faq['answer'] }}</p>
            </article>
        @endforeach
    </section>
</x-layouts.app>
