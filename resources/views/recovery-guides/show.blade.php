<x-layouts.app>
    <section class="card">
        <h1>{{ $guide['title'] }}</h1>
        <p class="muted">困ったときに、安全に確認できる手順です。詳しいエラー内容やパスワードなどの秘密情報は表示しません。</p>
        <ol>
            @foreach ($guide['steps'] as $step)<li>{{ $step }}</li>@endforeach
        </ol>
        @if ($type === 'import')
            <p><a href="{{ route('imports.index') }}">取込履歴で内容を確認する</a></p>
        @endif
        @if ($type === 'google_oauth')
            <p><a href="{{ route('google-sheets.connection') }}">Googleスプレッドシートの接続状態を見る</a></p>
        @endif
        @if ($type === 'email')
            <p><a href="{{ route('weekly-summary.show') }}">直近7日間のまとめ・メールの受信設定を見る</a></p>
        @endif
        @if ($type === 'ai')
            @if ($canManageOrganizationSettings)
                <p><a href="{{ route('settings.organization') }}">組織設定でAI補助の状態を見る</a></p>
            @endif
            @if ($canManageBilling)
                <p><a href="{{ route('billing.index') }}">プラン・お支払い画面で契約状態を見る</a></p>
            @else
                <p>AIの利用設定や契約状態は、会社の設定を管理する担当者（Owner）に確認してください。</p>
            @endif
        @endif
        @if ($type === 'stripe')
            @if ($canManageBilling)
                <p><a href="{{ route('billing.index') }}">プラン・お支払い画面で契約状態を見る</a></p>
            @else
                <p>契約状態は、組織の契約担当者に確認してください。</p>
            @endif
        @endif
        <p>解決しない場合は、<a href="{{ route('support.create') }}">お問い合わせフォーム</a>からご連絡ください。送信後に受付番号が発行され、運営側でも同じ番号で確認できます。</p>
        <p>メールでのお問い合わせ：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
    </section>
</x-layouts.app>
