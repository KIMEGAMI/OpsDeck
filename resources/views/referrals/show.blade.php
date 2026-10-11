<x-layouts.app>
    <section class="card">
        <h1>紹介リンク</h1>
        <p class="muted">紹介元の個人情報は共有せず、クリック数だけを記録します。紹介による割引・報酬は自動適用されず、料金・契約規約に定める条件がある場合のみ適用されます。</p>
        <label for="referral_url">紹介リンク</label>
        <input id="referral_url" value="{{ route('referrals.redirect', $referral->code) }}" readonly>
        <p>クリック数：{{ $referral->click_count }}</p>
        <p>登録組織数：{{ $referral->conversions_count }}</p>
    </section>
</x-layouts.app>
