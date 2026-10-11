<x-layouts.app>
    <section class="card" aria-labelledby="help-title">
        <h1 id="help-title">Help / FAQ</h1>
        <p class="muted">OpsDeckの使い方と対応範囲を確認できます。</p>
        <p><a href="{{ route('support.create') }}">FAQで解決しない場合はお問い合わせ</a></p>
        <p>メールでのお問い合わせ：<a href="mailto:{{ config('opsdeck.support_email') }}">{{ config('opsdeck.support_email') }}</a></p>
        @foreach ($faqs as $faq)
            <article style="border-top:1px solid #bcccdc;padding:.75rem 0">
                <h2>{{ $faq['question'] }}</h2>
                <p>{{ $faq['answer'] }}</p>
            </article>
        @endforeach
    </section>
</x-layouts.app>
