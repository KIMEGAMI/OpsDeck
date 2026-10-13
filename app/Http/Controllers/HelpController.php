<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(): View
    {
        return view('help.index', [
            'plans' => config('opsdeck.plans'),
            'faqs' => [
                ['question' => 'OpsDeckは何を管理するサービスですか？', 'answer' => '社員、IT資産、SaaS、契約更新、証跡、Security Controlを検索でき、期限と対応状況を確認するための管理サービスです。MDMやEDR、端末の遠隔操作を提供するものではありません。'],
                ['question' => 'Importで元データが壊れることはありますか？', 'answer' => 'ImportはPreviewで内容を確認してから実行します。元ファイルとImport履歴を保持し、失敗時も元データを変更しません。'],
                ['question' => '社内のExcel管理表はどう取り込めばよいですか？', 'answer' => '元のExcelは変更せず、取り込むシートのコピーをCSV、またはPremiumプランで利用できるXLSXとしてPreviewします。CSVはUTF-8（BOM付き可）またはShift_JIS（Windows-31J）に対応しています。見出しの行範囲と、注記などがある場合のデータ開始行を指定し、Previewで列マッピングと新規・更新・確認待ち・エラーを確認してください。Previewだけでは業務データは登録・更新されません。FreeプランはCSVプレビューまで、Import実行はPremiumプランです。'],
                ['question' => 'Excelファイルはどの形式で保存すればよいですか？', 'answer' => 'CSVまたはTXTを取り込めます。PremiumプランではXLSXもプレビューできます。旧形式のXLSファイルは対象外です。元ファイルを残したまま、対象シートをCSV（UTF-8またはShift_JIS）かXLSXで別保存してください。XLSXに複数シートがある場合は、Import時に対象シートを選択できます。保存後はPreviewで文字化け、日付・金額、列の対応を確認してください。'],
                ['question' => 'AI補助を使うと、どのデータが外部へ送られますか？', 'answer' => 'Premiumプランで組織のAI補助を有効にし、サーバー側の利用条件を満たす場合、列マッピング候補の取得時に列名、対象データ種別、OpsDeck側の候補項目を設定済みAIサービスへ送信します。取込データの行、ファイル全体、シート全体は送信しません。列名に含まれるメールアドレス・電話番号らしき値は伏せますが、氏名などは自動判定できないため、個人情報を含む列名は匿名化してから利用してください。FreeプランではAI候補を利用できないため送信しません。AIを使わず手動でマッピングすることもできます。'],
                ['question' => 'AIの候補は自動承認されますか？', 'answer' => 'AI候補は理由と信頼度を表示しますが、自動承認されません。人間が確認してから反映します。'],
                ['question' => '管理者のお知らせメールはどこから届きますか？', 'answer' => '管理者のお知らせと週次サマリーは info@kimegami.jp から届きます。配信停止は週次サマリー画面で設定でき、運用上の問い合わせは '.config('opsdeck.support_email').' へご連絡ください。'],
                ['question' => '問い合わせ先はどこですか？', 'answer' => '運用上の問い合わせは '.config('opsdeck.support_email').' へご連絡ください。Evidence本文やパスワード、トークンはメールに記載しないでください。'],
            ],
        ]);
    }
}
