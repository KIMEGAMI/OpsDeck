<div>
    <!-- Order your soul. Reduce your wants. - Augustine -->
</div>
<x-layouts.app>
    <section class="card" aria-labelledby="reset-password-title">
        <h1 id="reset-password-title">新しいパスワードを設定</h1>
        <p class="muted">新しいパスワードを入力してください。リンクは発行から{{ config('auth.passwords.users.expire') }}分間有効です。</p>
        <form method="post" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
            @error('email')<p class="errors" role="alert">{{ $message }}</p>@enderror
            <label for="password">新しいパスワード</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            @error('password')<p class="errors" role="alert">{{ $message }}</p>@enderror
            <label for="password_confirmation">新しいパスワード（確認）</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            <button type="submit">パスワードを再設定</button>
        </form>
    </section>
</x-layouts.app>
