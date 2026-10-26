<?php

namespace Tests\Feature;

use Tests\TestCase;

class HelpControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['opsdeck.billing.enabled' => true]);
    }

    public function test_help_page_is_public_and_contains_scope_guidance(): void
    {
        config(['opsdeck.release_features.google_sheets' => false]);

        $response = $this->get(route('help.index'));

        $response->assertOk()
            ->assertSee('使い方・よくある質問')
            ->assertSee('aria-label="ヘルプ内の目次"', false)
            ->assertSee('href="#help-start-title"', false)
            ->assertSee('href="#help-excel-csv-title"', false)
            ->assertSee('href="#help-savings-title"', false)
            ->assertSee('href="#help-recovery-title"', false)
            ->assertSee('href="#help-plans-title"', false)
            ->assertSee('MDMやEDR')
            ->assertSee('初めて使うとき')
            ->assertSee('Excelの表をCSVにして取り込む')
            ->assertSee('コピーを作り、そのコピーを開きます')
            ->assertSee('現在のシートだけが保存される')
            ->assertSee('https://support.microsoft.com/en-us/excel/save-a-workbook-to-text-format-txt-or-csv', false)
            ->assertSee('データ取込')
            ->assertSee('CSVは表の内容を保存するファイル形式です')
            ->assertSee('サンプルプレビューで、必要な列を確認します')
            ->assertSee('登録前に画面や取込例を確認できます')
            ->assertSee('入力・保存・送信はありません')
            ->assertSee(route('demo.show'))
            ->assertSee('元のExcelを残したままコピーを作ります')
            ->assertSee('コピーから必要なシートをCSV')
            ->assertSee('取込前の確認画面で')
            ->assertDontSee('Migration Studio')
            ->assertSee('社内のExcel管理表はどう取り込めばよいですか？')
            ->assertSee('元のExcelは変更せず')
            ->assertSee('データ開始行を指定')
            ->assertSee('確認画面を見るだけでは会社のデータは登録・更新されません')
            ->assertSee('Excelファイルはどの形式で保存すればよいですか？')
            ->assertSee('旧形式のXLSファイルは対象外です')
            ->assertSee('データ取込時に対象シートを選択できます')
            ->assertSee('AI補助を使うと、どのデータが外部へ送られますか？')
            ->assertSee('取込データの行、ファイル全体、シート全体は送信しません')
            ->assertSee('氏名などは自動判定できない')
            ->assertSee('FreeプランではAI候補を利用できないため送信しません')
            ->assertSee(route('onboarding.index'))
            ->assertSee(route('imports.create'))
            ->assertSee('無料プラン（Free）でもCSV/TXTを取り込めます')
            ->assertSee('社員・端末・SaaSサービスの登録数はFreeの上限まで')
            ->assertSee('概算は実際の削減額ではなく')
            ->assertSee('セキュリティの確認項目を検索でき')
            ->assertSee(route('costs.dashboard'))
            ->assertSee('困ったとき')
            ->assertDontSee('Googleスプレッドシートに接続できないとき')
            ->assertSee('<details class="help-faq"', false)
            ->assertSee('<summary>OpsDeckは何を管理するサービスですか？</summary>', false)
            ->assertSee('support@kimegami.jp')
            ->assertSee('mailto:support@kimegami.jp')
            ->assertSee('info@kimegami.jp')
            ->assertSee('配信停止')
            ->assertSee('無料プラン（Free）と有料プラン（Premium）の違い')
            ->assertSee('Premiumの適用税額を含むお支払い総額')
            ->assertSee('help-plan-table-scroll', false)
            ->assertSee('data-label="月額"', false)
            ->assertSee('data-label="登録上限"', false)
            ->assertSee('data-label="主な機能"', false);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertNotFalse($styles);
        $this->assertStringContainsString('.help-faq > summary {', $styles);
        $this->assertStringContainsString('min-height: var(--interactive-target-min-height);', $styles);
        $this->assertStringContainsString('@media (max-width: 64rem)', $styles);
        $this->assertStringContainsString('.help-plan-table-scroll td::before {', $styles);

        foreach (config('opsdeck.recovery_guides') as $type => $guide) {
            $releaseFeature = $guide['release_feature'] ?? null;
            if (is_string($releaseFeature) && config('opsdeck.release_features.'.$releaseFeature) !== true) {
                continue;
            }

            $response->assertSee($guide['title'])
                ->assertSee(route('help.recovery', $type));
        }

        foreach (config('opsdeck.plans') as $plan) {
            $response->assertSee($plan['label']);
            foreach ($plan['features'] as $feature) {
                $response->assertSee($feature);
            }
        }

        $response->assertSee('月額'.number_format((int) config('opsdeck.plans.premium.monthly_price_jpy')).'円（税別）')
            ->assertSee('社員'.number_format((int) config('opsdeck.plans.free.limits.employees')).'名');
    }

    public function test_free_only_mode_hides_premium_pricing_from_the_help_page(): void
    {
        config(['opsdeck.billing.enabled' => false]);

        $this->get(route('help.index'))
            ->assertOk()
            ->assertSee('無料プラン（Free）の登録上限')
            ->assertSee('現在はFreeプランのみ提供しています。Premiumの新規申込みとStripe決済は停止中です。')
            ->assertDontSee('月額9,800円（税別）');
    }
}
