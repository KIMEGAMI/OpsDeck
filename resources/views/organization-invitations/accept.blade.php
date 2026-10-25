<x-layouts.app>
    <section class="card auth-card">
        <h1>{{ $invitation->organization->name }}への招待</h1>
        <p>招待先: {{ $invitation->email }} / 権限: {{ $invitation->role->label() }}（{{ $invitation->role->description() }}）</p>
        <p class="muted">有効期限: {{ $invitation->expires_at->format('Y-m-d H:i') }}</p>
        @if (auth()->guest() && $existingUser)
            <p role="status">このメールアドレスには既存アカウントがあります。先にログインしてから招待を受け入れてください。</p>
            <a href="{{ route('login') }}">ログインして招待を受け入れる</a>
        @else
            <form method="post" action="{{ route('invitations.accept', $token) }}">
                @csrf
                <label for="name">表示名</label>
                <input id="name" name="name" required maxlength="255" value="{{ old('name', auth()->user()?->name) }}">
                @guest
                    <x-password-input id="password" name="password" label="パスワード（12文字以上）" minlength="12" />
                    <x-password-input id="password_confirmation" name="password_confirmation" label="パスワード（確認）" minlength="12" toggle-label="確認用パスワード" />
                @endguest
                <button type="submit">招待を受け入れる</button>
            </form>
        @endif
    </section>
</x-layouts.app>
