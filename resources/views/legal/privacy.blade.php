<x-layouts.app>
    <article class="card" aria-labelledby="privacy-title">
        <h1 id="privacy-title">プライバシーポリシー</h1>
        <p class="muted">最終更新日：{{ config('opsdeck.privacy_policy_last_updated') }}</p>
        <h2>1. 取得する情報</h2>
        <p>アカウント情報、組織データ、操作履歴、サービス改善に必要な技術情報を取得します。</p>
        <h2>2. 利用目的</h2>
        <p>サービスの提供、本人確認、セキュリティ対策、問い合わせ対応、利用状況の分析に利用します。</p>
        <h2>3. 利用状況イベント</h2>
        <p>登録、Import、オンボーディング、Billingなどの利用状況を、組織単位の改善指標として記録します。イベントにはメールアドレス、パスワード、トークン、Evidence本文などを保存しません。</p>
        <h2>4. 委託・外部サービス</h2>
        <p>機能の利用時に、次の外部サービスと必要な情報をやり取りします。外部サービス側での保存期間や取扱いは、各事業者の規約・プライバシー情報をご確認ください。</p>
        <ul>
            @if (config('opsdeck.billing.enabled'))
                <li>Stripe：Premiumの申込・請求・契約状態の確認に利用します。決済画面はStripeが提供します。</li>
            @else
                <li>Stripe：現在は課金機能を停止しており、決済情報をStripeへ送信しません。</li>
            @endif
            <li>Resend：メール配信先に設定している場合、お知らせ・招待・週次サマリーを送るため、宛先メールアドレスとメール内容を送信します。</li>
            <li>Google Sheets：利用者が連携した場合、読み取り専用の権限でファイル情報と指定したシートの内容を取得します。</li>
            <li>OpenAI：Premiumで組織のAI補助が有効な場合、列マッピング候補の作成に必要な列名・対象データ種別・候補項目を送信します。取込データの行、ファイル全体、シート全体は送信しません。列名の個人情報は一部を自動で伏せますが、氏名などは判定できないため、必要に応じて匿名化してください。</li>
        </ul>
        <p>Import元ファイルとEvidenceは、運営者が設定した非公開ストレージに保存します。秘密情報は環境変数で管理します。</p>
        <h2>5. 保管と削除</h2>
        <p>Import元ファイル、取込履歴、行ごとの確認結果は、再確認・エラー修正・取込取消のため、期限を設けず保存します。日次の導入効果履歴は{{ number_format(config('opsdeck.impact_snapshots.daily_retention_days')) }}日間保存し、保存期間を過ぎた分は定期処理で削除します。その他の情報は、サービス提供や問い合わせ対応などの利用目的に必要な期間保管し、法令または契約上必要な期間を除き、不要となった情報を適切に削除します。</p>
        <h2>6. 問い合わせ</h2>
        <p>個人情報に関するお問い合わせは、<a href="{{ route('support.create', ['category' => 'privacy']) }}">お問い合わせフォーム（個人情報・データの取扱い）</a>またはメール（<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a>）からご連絡ください。</p>
    </article>
</x-layouts.app>
