<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_public_legal_pages_are_available_without_authentication(): void
    {
        $this->get(route('legal.terms'))->assertOk()->assertSee('利用規約')->assertSee('最終更新日：'.config('opsdeck.legal_last_updated'));
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('プライバシーポリシー')
            ->assertSee('最終更新日：'.config('opsdeck.legal_last_updated'))
            ->assertSee('support@kimegami.jp')
            ->assertSee('mailto:support@kimegami.jp');
        $this->get(route('legal.commercial-transactions'))
            ->assertOk()
            ->assertSee('特定商取引法に基づく表記')
            ->assertSee('最終更新日：2026年9月27日')
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
}
