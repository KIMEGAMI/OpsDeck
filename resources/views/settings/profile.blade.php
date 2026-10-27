<x-layouts.app>
    <section class="card" aria-labelledby="account-settings-title">
        <h1 id="account-settings-title">個人設定</h1>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <p class="muted">ログイン中のアカウント情報とパスワードを管理します。組織全体の設定は「組織設定」から行えます。</p>
    </section>

    <div class="dashboard-columns">
        <section class="dashboard-panel" aria-labelledby="profile-title">
            <h2 id="profile-title">プロフィール</h2>
            <p><strong>ログイン中のユーザー:</strong> {{ $user->name }}</p>
            <p><strong>ログイン用メールアドレス:</strong> {{ $user->email }}</p>
            <p><strong>メール認証:</strong> {{ $user->hasVerifiedEmail() ? '認証済み' : '未認証' }}</p>
            @unless ($user->hasVerifiedEmail())
                <p><a href="{{ route('verification.notice') }}">確認メールを確認・再送する</a></p>
            @endunless

            <form method="post" action="{{ route('settings.profile.update') }}">
                @csrf
                @method('put')
                <label for="name">表示名</label>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" autocomplete="name" required maxlength="{{ config('opsdeck.validation.name_max_length') }}">
                <button type="submit">表示名を保存</button>
            </form>

            <p class="muted">ログイン用メールアドレスの変更はこの画面では行えません。変更が必要な場合は <a href="mailto:{{ config('opsdeck.support_email') }}">サポートへお問い合わせください</a>。</p>
        </section>

        <section class="dashboard-panel" aria-labelledby="password-title">
            <h2 id="password-title">パスワード変更</h2>
            <p class="muted">本人確認のため現在のパスワードを入力してください。新しいパスワードは確認欄にも入力します。</p>
            <form method="post" action="{{ route('settings.password.update') }}">
                @csrf
                @method('put')
                <x-password-input id="current_password" name="current_password" label="現在のパスワード" autocomplete="current-password" toggleLabel="現在のパスワード" />
                <x-password-input id="password" name="password" label="新しいパスワード" autocomplete="new-password" toggleLabel="新しいパスワード" />
                <x-password-input id="password_confirmation" name="password_confirmation" label="新しいパスワード（確認）" autocomplete="new-password" toggleLabel="新しいパスワードの確認" />
                <button type="submit">パスワードを変更</button>
            </form>
        </section>

        <section class="dashboard-panel" aria-labelledby="google-login-title">
            <h2 id="google-login-title">Googleログイン</h2>
            @if ($user->google_id !== null)
                <p>Googleアカウントは連携済みです。このアカウントでGoogleログインできます。</p>
                <form method="post" action="{{ route('settings.google-login.disconnect') }}">
                    @csrf
                    <label for="google_disconnect_current_password">本人確認のため、現在のパスワードを入力してください。</label>
                    <x-password-input id="google_disconnect_current_password" name="current_password" label="現在のパスワード" autocomplete="current-password" toggleLabel="現在のパスワード" />
                    <button type="submit">Google連携を解除</button>
                </form>
            @else
                <p class="muted">最初に既存アカウントへログインしてGoogleを連携すると、次回からGoogleログインを使えます。</p>
                @if (! $user->hasVerifiedEmail())
                    <p class="muted">Googleを連携するには、先にメール認証を完了してください。</p>
                @elseif (config('services.google.login_enabled'))
                    <form method="post" action="{{ route('settings.google-login.connect') }}">
                        @csrf
                        <label for="google_connect_current_password">本人確認のため、現在のパスワードを入力してください。</label>
                        <x-password-input id="google_connect_current_password" name="current_password" label="現在のパスワード" autocomplete="current-password" toggleLabel="現在のパスワード" />
                        <button type="submit">Googleアカウントを連携</button>
                    </form>
                @else
                    <p class="muted">Google OAuthの環境設定が完了すると連携できます。</p>
                @endif
            @endif
        </section>
    </div>

    @if ($memberships->isNotEmpty())
        <section class="card dashboard-announcements" aria-labelledby="organization-memberships-title">
            <h2 id="organization-memberships-title">所属組織と通知設定</h2>
            @foreach ($memberships as $membership)
                <article>
                    <h3>{{ $membership->organization->name }}</h3>
                    <p><strong>組織での権限:</strong> {{ $membership->role->label() }}</p>
                    @if ($user->hasVerifiedEmail())
                        <p><a href="{{ route('weekly-summary.show', ['organization_id' => $membership->organization_id]) }}">この組織の通知設定を見る</a></p>
                    @else
                        <p class="muted">通知設定を開くには、先にメール認証を完了してください。</p>
                    @endif
                </article>
            @endforeach
        </section>
    @endif
</x-layouts.app>
