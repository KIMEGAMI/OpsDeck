<x-layouts.app>
    <section class="card auth-card" aria-labelledby="reset-password-title">
        <h1 id="reset-password-title">新しいパスワードを設定</h1>
        <p class="muted">新しいパスワードを入力してください。リンクは発行から{{ config('auth.passwords.users.expire') }}分間有効です。</p>
        <form method="post" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
            @error('email')<p class="errors" role="alert">{{ $message }}</p>@enderror
            <x-password-input id="password" name="password" label="新しいパスワード" toggle-label="新しいパスワード" />
            @error('password')<p class="errors" role="alert">{{ $message }}</p>@enderror
            <x-password-input id="password_confirmation" name="password_confirmation" label="新しいパスワード（確認）" toggle-label="新しい確認用パスワード" />
            <button type="submit">パスワードを再設定</button>
        </form>
    </section>
</x-layouts.app>
