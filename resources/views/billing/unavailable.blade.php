<x-layouts.app>
    <section class="card" role="alert">
        <h1>Billing手続きを開始できませんでした</h1>
        <p>Stripeとの通信または料金設定を確認できなかったため、手続きを中断しました。秘密情報やStripeの内部エラー詳細は表示していません。</p>
        <ol>
            <li>時間をおいてからBilling画面で再度お試しください。</li>
            <li><a href="{{ route('help.recovery', 'stripe') }}">Stripe連携の復旧ガイド</a>を確認してください。</li>
        </ol>
        <p>サポートへ問い合わせる場合は、照会番号 <strong>{{ $errorReference }}</strong> をお伝えください。</p>
        <p><a href="{{ route('billing.index') }}">Billing画面へ戻る</a></p>
        <p>解決しない場合は <a href="mailto:{{ $supportEmail }}">サポートへ問い合わせる</a>ことができます。</p>
    </section>
</x-layouts.app>
