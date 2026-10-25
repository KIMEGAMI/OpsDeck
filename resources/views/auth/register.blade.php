<x-layouts.app>
    <section class="card auth-card" aria-labelledby="register-title">
        <h1 id="register-title">OpsDeckを始める</h1>
        <p class="muted">登録後、社員・端末・SaaS・契約の移行を順に進められます。</p>
        <p class="muted">登録する前に、<a href="{{ route('legal.terms') }}">利用規約</a>と<a href="{{ route('legal.privacy') }}">プライバシーポリシー</a>をご確認ください。</p>
        <form method="post" action="{{ route('register.store') }}">
            @csrf
            <label for="organization_name">会社名</label>
            <input id="organization_name" name="organization_name" value="{{ old('organization_name') }}" autocomplete="organization" required>
            <label for="name">氏名</label>
            <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required>
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
            <x-password-input id="password" name="password" label="パスワード" />
            <x-password-input id="password_confirmation" name="password_confirmation" label="パスワード（確認）" toggle-label="確認用パスワード" />
            <button type="submit">組織を作成して開始</button>
        </form>
        <p><a href="{{ route('login') }}">すでにアカウントをお持ちの場合</a></p>
    </section>
</x-layouts.app>
