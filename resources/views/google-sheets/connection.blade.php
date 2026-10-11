<x-layouts.app>
    <section class="card">
        <h1>Google Sheets連携</h1>
        <p class="muted">Google SheetsからOpsDeckへ一方向に取り込みます。書き戻しや自動削除は行いません。</p>
        @unless ($featureAvailable)
            <p class="muted">Google Sheets連携はPremiumプランで利用できます。<a href="{{ route('billing.index') }}">プランを確認する</a></p>
        @endunless
        @if ($connection?->revoked_at === null && $connection?->refresh_token)
            <p>接続済み{{ $connection->google_account_email ? '（'.$connection->google_account_email.'）' : '' }}</p>
            <p>許可スコープ: {{ implode(', ', $connection->scopes_json ?? []) }}</p>
            <form method="post" action="{{ route('google-sheets.disconnect') }}">
                @csrf
                <button type="submit">連携を解除</button>
            </form>
        @else
            <p>Google Sheetsは未接続です。</p>
            @if ($featureAvailable)
                <a href="{{ route('google-sheets.connect') }}">Google OAuthで接続</a>
            @endif
        @endif
        <p class="muted"><a href="{{ route('help.recovery', 'google_oauth') }}">Google Sheets連携の復旧ガイド</a></p>
    </section>
</x-layouts.app>
