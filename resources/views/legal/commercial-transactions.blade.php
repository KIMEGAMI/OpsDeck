<x-layouts.app>
    <article class="card" aria-labelledby="commercial-title">
        <h1 id="commercial-title">特定商取引法に基づく表記</h1>
        <p class="muted">最終更新日：{{ config('opsdeck.legal_last_updated') }}</p>
        @if (config('opsdeck.billing.enabled'))
            @if (! $seller['seller_name'] || ! $seller['seller_address'] || ! $seller['seller_phone'] || ! $seller['seller_representative'])
                <p class="errors" role="alert">販売者情報が未設定です。Premiumの販売を開始する前に、事業者名・所在地・電話番号・販売責任者を設定してください。</p>
            @endif
            <dl>
                <dt>販売事業者名</dt><dd>{{ $seller['seller_name'] ?: '未設定（本番公開前に設定が必要です）' }}</dd>
                <dt>所在地</dt><dd>{{ $seller['seller_address'] ?: '未設定（本番公開前に設定が必要です）' }}</dd>
                <dt>電話番号</dt><dd>{{ $seller['seller_phone'] ?: '未設定（本番公開前に設定が必要です）' }}</dd>
                <dt>販売責任者</dt><dd>{{ $seller['seller_representative'] ?: '未設定（本番公開前に設定が必要です）' }}</dd>
                <dt>サービス名</dt><dd>OpsDeck</dd>
                <dt>販売価格</dt><dd>Free：月額0円。Premium：会社単位 月額{{ number_format((int) config('opsdeck.plans.premium.monthly_price_jpy')) }}円（税別）。適用税額を加えた合計は決済前にStripe Checkoutで表示します。</dd>
                @if (config('opsdeck.release_features.storage_addon'))
                    <dt>追加容量</dt><dd>申込画面で選択できる場合、1GiBあたり月額{{ number_format((int) config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy')) }}円（税別）。選択した容量はPremiumの初回請求から加算され、以後も月額で請求します。</dd>
                @endif
                <dt>商品代金以外の必要料金</dt><dd>インターネット接続料金等は利用者の負担です。税額は申込時に決済画面で表示します。</dd>
                <dt>支払方法</dt><dd>Stripe Checkoutを通じたカード決済</dd>
                <dt>支払時期</dt><dd>申込時および各月の更新時に、Stripeの請求・決済条件に従って決済します。</dd>
                <dt>サービス提供時期</dt><dd>申込みと決済が確認され次第、通常は直ちに利用できます。</dd>
                <dt>契約期間・自動更新</dt><dd>1か月単位。解約手続がない場合は、次の請求期間へ更新されます。</dd>
                <dt>解約方法・時期</dt><dd>Billing画面から案内されるStripe Customer Portalで次回更新日前に手続きしてください。契約は次回更新日に終了し、終了日までは利用できます。</dd>
                <dt>返品・返金</dt><dd>決済済み期間の利用料は日割りで返金しません。ただし、法令上返金が必要となる場合を除きます。</dd>
            </dl>
        @else
            <p>現在は月額0円のFreeプランのみ提供しています。Premiumの新規申込みとStripe決済は停止中です。</p>
        @endif
        <p>サービスに関するお問い合わせ：@if ($contactEmail)<a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>@elseお問い合わせ先は現在準備中です@endif</p>
    </article>
</x-layouts.app>
