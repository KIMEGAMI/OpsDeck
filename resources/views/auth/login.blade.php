<x-layouts.app>
    <section class="card" aria-labelledby="login-title">
        <h1 id="login-title">OpsDeckへログイン</h1>
        <p class="muted">組織のIT運用情報を安全に管理します。</p>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <form method="post" action="{{ route('login.store') }}">
            @csrf
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
            <label for="password">パスワード</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <p><a href="{{ route('password.request') }}">パスワードをお忘れですか？</a></p>
            <label><input name="remember" type="checkbox" value="1" style="width:auto"> ログイン状態を保持する</label>
            <button type="submit">ログイン</button>
        </form>
        <p><a href="{{ route('register') }}">組織を新規登録する</a></p>
    </section>
</x-layouts.app>
