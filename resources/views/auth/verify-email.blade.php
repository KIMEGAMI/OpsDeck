<x-layouts.app>
    <section class="card" aria-labelledby="verify-email-title">
        <h1 id="verify-email-title">メールアドレスを確認してください</h1>
        <p>登録を完了するには、登録したメールアドレスに届いた確認リンクを開いてください。</p>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <form method="post" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit">確認メールを再送する</button>
        </form>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit">ログアウト</button>
        </form>
    </section>
</x-layouts.app>
