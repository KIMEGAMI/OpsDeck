<?php

namespace Tests\Feature;

use Tests\TestCase;

class HelpControllerTest extends TestCase
{
    public function test_help_page_is_public_and_contains_scope_guidance(): void
    {
        $response = $this->get(route('help.index'));

        $response->assertOk()
            ->assertSee('Help / FAQ')
            ->assertSee('MDMやEDR')
            ->assertSee('初めて使うとき')
            ->assertSee('社内のExcel管理表はどう取り込めばよいですか？')
            ->assertSee('元のExcelは変更せず')
            ->assertSee('データ開始行を指定')
            ->assertSee('Previewだけでは業務データは登録・更新されません')
            ->assertSee('Excelファイルはどの形式で保存すればよいですか？')
            ->assertSee('旧形式のXLSファイルは対象外です')
            ->assertSee('Import時に対象シートを選択できます')
            ->assertSee('AI補助を使うと、どのデータが外部へ送られますか？')
            ->assertSee('取込データの行、ファイル全体、シート全体は送信しません')
            ->assertSee('氏名などは自動判定できない')
            ->assertSee('FreeプランではAI候補を利用できないため送信しません')
            ->assertSee(route('onboarding.index'))
            ->assertSee(route('imports.create'))
            ->assertSee('業務データへのImport実行はPremiumプラン')
            ->assertSee('概算は実際の削減額ではなく')
            ->assertSee(route('costs.dashboard'))
            ->assertSee('support@kimegami.jp')
            ->assertSee('mailto:support@kimegami.jp')
            ->assertSee('info@kimegami.jp')
            ->assertSee('配信停止');
    }
}
