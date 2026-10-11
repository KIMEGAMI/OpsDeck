<x-layouts.app>
    <article class="card" aria-labelledby="privacy-title">
        <h1 id="privacy-title">プライバシーポリシー</h1>
        <p class="muted">最終更新日：{{ config('opsdeck.legal_last_updated') }}</p>
        <h2>1. 取得する情報</h2>
        <p>アカウント情報、組織データ、操作履歴、サービス改善に必要な技術情報を取得します。</p>
        <h2>2. 利用目的</h2>
        <p>サービスの提供、本人確認、セキュリティ対策、問い合わせ対応、利用状況の分析に利用します。</p>
        <h2>3. 利用状況イベント</h2>
        <p>登録、Import、オンボーディング、Billingなどの利用状況を、組織単位の改善指標として記録します。イベントにはメールアドレス、パスワード、トークン、Evidence本文などを保存しません。</p>
        <h2>4. 委託・外部サービス</h2>
        <p>決済、メール、ストレージ、AI処理など、サービス提供に必要な範囲で外部サービスを利用する場合があります。秘密情報は環境変数で管理します。</p>
        <h2>5. 保管と削除</h2>
        <p>利用目的に必要な期間保管し、法令または契約上必要な期間を除き、不要となった情報を適切に削除します。</p>
        <h2>6. 問い合わせ</h2>
        <p>個人情報に関する問い合わせ窓口：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
    </article>
</x-layouts.app>
