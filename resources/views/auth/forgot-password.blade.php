<div>
    <!-- Do what you can, with what you have, where you are. - Theodore Roosevelt -->
</div>
<x-layouts.app>
    <section class="card" aria-labelledby="forgot-password-title">
        <h1 id="forgot-password-title">パスワードをお忘れの場合</h1>
        <p class="muted">登録したメールアドレスを入力してください。該当する場合は、再設定用リンクをお送りします。</p>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <form method="post" action="{{ route('password.email') }}">
            @csrf
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
            @error('email')<p class="errors" role="alert">{{ $message }}</p>@enderror
            <button type="submit">再設定メールを送信</button>
        </form>
        <p><a href="{{ route('login') }}">ログインへ戻る</a></p>
    </section>
</x-layouts.app>
