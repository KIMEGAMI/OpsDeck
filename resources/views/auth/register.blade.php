<x-layouts.app>
    <section class="card" aria-labelledby="register-title">
        <h1 id="register-title">OpsDeckを始める</h1>
        <p class="muted">登録後、社員・端末・SaaS・契約の移行を順に進められます。</p>
        <form method="post" action="{{ route('register.store') }}">
            @csrf
            <label for="organization_name">会社名</label>
            <input id="organization_name" name="organization_name" value="{{ old('organization_name') }}" autocomplete="organization" required>
            <label for="name">氏名</label>
            <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required>
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
            <label for="password">パスワード</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            <label for="password_confirmation">パスワード（確認）</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            <button type="submit">組織を作成して開始</button>
        </form>
        <p><a href="{{ route('login') }}">すでにアカウントをお持ちの場合</a></p>
    </section>
</x-layouts.app>
