<x-layouts.app>
    <section class="card">
        <h1>{{ $guide['title'] }}</h1>
        <p class="muted">安全に復旧するための確認手順です。内部エラー内容や秘密情報は表示しません。</p>
        <ol>
            @foreach ($guide['steps'] as $step)<li>{{ $step }}</li>@endforeach
        </ol>
        @if ($type === 'email')
            <p><a href="{{ route('weekly-summary.show') }}">週次サマリー・お知らせメールの受信設定を確認</a></p>
        @endif
        <p>解決しない場合は、<a href="{{ route('support.create') }}">お問い合わせフォーム</a>からご連絡ください。送信後に受付番号が発行され、運営側でも同じ番号で確認できます。</p>
        <p>メールでのお問い合わせ：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
    </section>
</x-layouts.app>
