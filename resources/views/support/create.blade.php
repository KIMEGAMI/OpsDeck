<x-layouts.app>
    <section class="card">
        <h1>お問い合わせ</h1>
        @if (session('support_reference_code'))
            <p role="status">{{ session('status') }} 受付番号：{{ session('support_reference_code') }}</p>
        @elseif (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <p id="support-safety-note" class="muted">パスワード、トークン、Evidence本文などの秘密情報は入力しないでください。</p>
        <form method="post" action="{{ route('support.store') }}" onsubmit="const button = this.querySelector('button[type=submit]'); button.disabled = true; button.setAttribute('aria-disabled', 'true'); return true;">
            @csrf
            <label for="category">カテゴリ</label>
            <select id="category" name="category" required><option value="usage">使い方</option><option value="import">Import</option><option value="billing">Billing</option><option value="security">Security</option><option value="other">その他</option></select>
            <label for="error_reference">エラー照会ID（任意）</label>
            <input id="error_reference" name="error_reference" value="{{ old('error_reference', $errorReference) }}" maxlength="36" autocomplete="off" aria-describedby="error-reference-help">
            <p id="error-reference-help" class="muted">エラー画面に表示された番号がある場合は、そのまま貼り付けてください。</p>
            <label for="message">お問い合わせ内容</label>
            <textarea id="message" name="message" maxlength="5000" aria-describedby="support-safety-note" required></textarea>
            <button type="submit" aria-disabled="false">送信</button>
        </form>
        <p>メールでのお問い合わせ：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
    </section>
</x-layouts.app>
