<x-layouts.app>
    <article class="card" aria-labelledby="commercial-title">
        <h1 id="commercial-title">特定商取引法に基づく表記</h1>
        <p class="muted">最終更新日：{{ config('opsdeck.legal_last_updated') }}</p>
        <dl>
            <dt>販売事業者名</dt><dd>{{ $seller['seller_name'] ?: '未設定（本番公開前に設定が必要です）' }}</dd>
            <dt>所在地</dt><dd>{{ $seller['seller_address'] ?: '未設定（本番公開前に設定が必要です）' }}</dd>
            <dt>電話番号</dt><dd>{{ $seller['seller_phone'] ?: '未設定（本番公開前に設定が必要です）' }}</dd>
            <dt>販売責任者</dt><dd>{{ $seller['seller_representative'] ?: '未設定（本番公開前に設定が必要です）' }}</dd>
            <dt>サービス名</dt><dd>OpsDeck</dd>
            <dt>販売価格</dt><dd>申込画面に表示する月額料金（Stripe設定値）</dd>
            <dt>支払方法</dt><dd>Stripeによるクレジットカード決済</dd>
            <dt>提供時期</dt><dd>決済完了後、通常直ちに利用可能</dd>
            <dt>解約</dt><dd>Customer Portalから手続きできます。解約後の扱いは契約条件に従います。</dd>
            <dt>問い合わせ先</dt><dd>@if ($contactEmail)<a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>@else契約時に提示する連絡先@endif</dd>
        </dl>
    </article>
</x-layouts.app>
