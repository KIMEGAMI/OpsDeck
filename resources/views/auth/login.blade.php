<x-layouts.app>
    <section class="card auth-card" aria-labelledby="login-title">
        <h1 id="login-title">OpsDeckへログイン</h1>
        <p class="muted">社員・パソコン・使っているサービス・契約をまとめて管理できます。</p>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        @if (config('services.google.login_enabled'))
            <p><a class="auth-provider-button" href="{{ route('login.google.redirect') }}">Googleでログイン</a></p>
        @endif
        <form method="post" action="{{ route('login.store') }}">
            @csrf
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
            <x-password-input id="password" name="password" label="パスワード" autocomplete="current-password" />
            <p><a href="{{ route('password.request') }}">パスワードをお忘れですか？</a></p>
            <label><input name="remember" type="checkbox" value="1" style="width:auto" aria-describedby="remember-help"> ログイン状態を保持する</label>
            <p id="remember-help" class="muted">共有のパソコンでは、チェックを外してください。</p>
            <button type="submit">ログイン</button>
        </form>
        <p><a href="{{ route('demo.show') }}">登録前に読み取り専用デモを見る</a></p>
        <p><a href="{{ route('register') }}">組織を新規登録する</a></p>
        <p><a href="{{ route('help.index') }}">使い方に困ったらヘルプを見る</a></p>
    </section>
</x-layouts.app>
