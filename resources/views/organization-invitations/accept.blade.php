<x-layouts.app>
    <section class="card">
        <h1>{{ $invitation->organization->name }}への招待</h1>
        <p>招待先: {{ $invitation->email }} / ロール: {{ $invitation->role->value }}</p>
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
                    <label for="password">パスワード（12文字以上）</label>
                    <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
                    <label for="password_confirmation">パスワード（確認）</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
                @endguest
                <button type="submit">招待を受け入れる</button>
            </form>
        @endif
    </section>
</x-layouts.app>
