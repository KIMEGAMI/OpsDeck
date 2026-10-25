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
            <select id="category" name="category" required @error('category') aria-invalid="true" aria-describedby="category-error" @enderror>
                @foreach (config('opsdeck.support.categories') as $value => $label)
                    <option value="{{ $value }}" @selected(old('category', $category) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-field-error field="category" />

            <label for="error_reference">エラー照会ID（任意）</label>
            <input id="error_reference" name="error_reference" value="{{ old('error_reference', $errorReference) }}" maxlength="80" autocomplete="off" @error('error_reference') aria-invalid="true" aria-describedby="error-reference-help error_reference-error" @else aria-describedby="error-reference-help" @enderror>
            <p id="error-reference-help" class="muted">エラー画面に表示された番号がある場合は、そのまま貼り付けてください。</p>
            <x-field-error field="error_reference" />

            <label for="message">お問い合わせ内容</label>
            <textarea id="message" name="message" maxlength="5000" required @error('message') aria-invalid="true" aria-describedby="support-safety-note message-error" @else aria-describedby="support-safety-note" @enderror></textarea>
            <x-field-error field="message" />
            <button type="submit" aria-disabled="false">送信</button>
        </form>
        <p>メールでのお問い合わせ：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
    </section>
</x-layouts.app>
