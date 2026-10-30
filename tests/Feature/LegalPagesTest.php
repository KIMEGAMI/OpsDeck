<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'opsdeck.billing.enabled' => true,
            'opsdeck.release_features.storage_addon' => true,
        ]);
    }

    public function test_public_legal_pages_are_available_without_authentication(): void
    {
        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertSee('利用規約')
            ->assertSee('最終更新日：'.config('opsdeck.legal_last_updated'))
            ->assertSee('組織のOwner・管理者')
            ->assertSee('Premiumは会社単位で月額9,800円（税別）です。')
            ->assertSee('解約は次回更新日前にBilling画面から案内されるCustomer Portalで手続きしてください。')
            ->assertSee('解約手続後も次回更新日までは利用でき、契約は次回更新日に終了します。')
            ->assertSee('決済済み期間の利用料は日割りで返金しません。')
            ->assertSee('Customer Portal')
            ->assertSee(route('legal.privacy'));
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('プライバシーポリシー')
            ->assertSee('最終更新日：'.config('opsdeck.privacy_policy_last_updated'))
            ->assertSee('Stripe：Premiumの申込、請求、契約状態の確認に利用します。')
            ->assertSee('Resend：メール配信に利用する設定の場合')
            ->assertSee('Google Sheets：連携機能を利用した場合')
            ->assertSee('OpenAI：組織でAI列マッピングを有効にして利用した場合')
            ->assertSee('Cloudflare R2：OpsDeckのファイル保存先として設定されている場合')
            ->assertSee('Import元ファイル、取込履歴、行ごとの確認結果は、再確認・エラー修正・取込取消に使用するため、現在は期限を設けず保存します。')
            ->assertSee(number_format(config('opsdeck.impact_snapshots.daily_retention_days')).'日間保存し、保存期間を過ぎた分は定期処理で削除します。')
            ->assertSee(route('support.create', ['category' => 'privacy']))
            ->assertSee('お問い合わせフォーム（個人情報・データの取扱い）')
            ->assertSee('support@kimegami.jp')
            ->assertSee('mailto:support@kimegami.jp');
        $this->get(route('legal.commercial-transactions'))
            ->assertOk()
            ->assertSee('特定商取引法に基づく表記')
            ->assertSee('最終更新日：'.config('opsdeck.legal_last_updated'))
            ->assertSee('月額9,800円（税別）')
            ->assertSee('1GiBあたり月額100円（税別）')
            ->assertSee('次回更新日前に手続きしてください。契約は次回更新日に終了し、終了日までは利用できます。')
            ->assertSee('決済済み期間の利用料は日割りで返金しません。')
            ->assertSee('決済前にStripe Checkoutで表示します')
            ->assertSee('support@kimegami.jp')
            ->assertSee('mailto:support@kimegami.jp');
    }

    public function test_commercial_information_uses_configured_seller_details_and_escapes_html(): void
    {
        config([
            'opsdeck.commercial_information.seller_name' => '<script>alert(1)</script> 事業者',
            'opsdeck.commercial_information.seller_address' => '東京都千代田区',
            'opsdeck.commercial_information.seller_phone' => '03-1234-5678',
            'opsdeck.commercial_information.seller_representative' => '山田 太郎',
        ]);

        $this->get(route('legal.commercial-transactions'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; 事業者', false)
            ->assertSee('東京都千代田区')
            ->assertSee('03-1234-5678')
            ->assertSee('山田 太郎')
            ->assertDontSee('<script>alert(1)</script> 事業者', false);
    }

    public function test_commercial_information_shows_missing_setup_notice_when_seller_details_are_not_configured(): void
    {
        config([
            'opsdeck.commercial_information.seller_name' => null,
            'opsdeck.commercial_information.seller_address' => null,
            'opsdeck.commercial_information.seller_phone' => null,
            'opsdeck.commercial_information.seller_representative' => null,
        ]);

        $this->get(route('legal.commercial-transactions'))
            ->assertOk()
            ->assertSee('未設定（本番公開前に設定が必要です）');
    }

    public function test_free_only_mode_discloses_that_stripe_billing_is_disabled(): void
    {
        config(['opsdeck.billing.enabled' => false]);

        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('Stripe：現在は課金機能を停止しており、決済情報をStripeへ送信しません。')
            ->assertDontSee('Stripe：Premiumの申込・請求・契約状態の確認に利用します。');

        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertSee('現在は月額0円のFreeプランのみ提供しています。Premiumの新規申込みとStripe決済は停止中です。')
            ->assertDontSee('Premiumは会社単位で月額9,800円（税別）です。');

        $this->get(route('legal.commercial-transactions'))
            ->assertOk()
            ->assertSee('現在は月額0円のFreeプランのみ提供しています。Premiumの新規申込みとStripe決済は停止中です。')
            ->assertDontSee('月額9,800円（税別）')
            ->assertDontSee('Stripe Checkoutで合計を表示します');
    }
}
