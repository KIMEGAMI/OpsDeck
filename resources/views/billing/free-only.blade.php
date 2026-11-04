<x-layouts.app>
    <section class="card" aria-labelledby="billing-free-only-title">
        <h1 id="billing-free-only-title">無料公開について</h1>
        <p>OpsDeckは現在、月額0円で公開しています。現在利用できる機能に追加料金はありません。</p>
        <p>OpsDeckから新しい有料契約や追加容量を申し込むことはできません。過去に登録したStripe契約の状態や請求は、この画面では確認・変更できません。契約が残っている場合はStripe側で状態を確認するか、<a href="mailto:{{ config('opsdeck.support_email') }}">サポートへお問い合わせください</a>。</p>
    </section>
</x-layouts.app>
