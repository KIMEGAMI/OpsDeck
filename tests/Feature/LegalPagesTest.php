<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['opsdeck.release_features.storage_addon' => false]);
    }

    public function test_public_legal_pages_are_available_without_authentication(): void
    {
        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertSee('利用規約')
            ->assertSee('最終更新日：'.config('opsdeck.legal_last_updated'))
            ->assertSee('組織のOwner・管理者')
            ->assertSee('月額利用料・プラン料金・追加容量料金を請求せず、無料で提供しています。')
            ->assertDontSee('Premium')
            ->assertDontSee('Stripe Checkout')
            ->assertSee(route('legal.privacy'));
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('プライバシーポリシー')
            ->assertSee('最終更新日：'.config('opsdeck.privacy_policy_last_updated'))
            ->assertSee('Stripe：現在は課金機能を停止しており、決済情報をStripeへ送信しません。')
            ->assertDontSee('Stripe：Premiumの申込')
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
    }

    public function test_environment_template_uses_current_legal_page_update_dates(): void
    {
        $environmentTemplate = file_get_contents(base_path('.env.example'));

        $this->assertIsString($environmentTemplate);
        $this->assertStringContainsString('OPSDECK_LEGAL_LAST_UPDATED="2026年10月29日"', $environmentTemplate);
        $this->assertStringContainsString('OPSDECK_PRIVACY_POLICY_LAST_UPDATED="2026年10月28日"', $environmentTemplate);
    }

    public function test_commercial_transactions_page_is_no_longer_public(): void
    {
        $this->get('/commercial-transactions')->assertNotFound();
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
            ->assertSee('月額利用料・プラン料金・追加容量料金を請求せず、無料で提供しています。')
            ->assertDontSee('Premium');
    }
}
