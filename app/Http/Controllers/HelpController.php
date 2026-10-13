<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(): View
    {
        return view('help.index', ['faqs' => [
            ['question' => 'OpsDeckは何を管理するサービスですか？', 'answer' => '社員、IT資産、SaaS、契約更新、証跡、Security Controlを検索でき、期限と対応状況を確認するための管理サービスです。MDMやEDR、端末の遠隔操作を提供するものではありません。'],
            ['question' => 'Importで元データが壊れることはありますか？', 'answer' => 'ImportはPreviewで内容を確認してから実行します。元ファイルとImport履歴を保持し、失敗時も元データを変更しません。'],
            ['question' => '社内のExcel管理表はどう取り込めばよいですか？', 'answer' => '元のExcelは変更せず、取り込むシートのコピーをCSV、またはPremiumプランで利用できるXLSXとしてPreviewします。CSVはUTF-8（BOM付き可）またはShift_JIS（Windows-31J）に対応しています。見出しの行範囲と、注記などがある場合のデータ開始行を指定し、Previewで列マッピングと新規・更新・確認待ち・エラーを確認してください。Previewだけでは業務データは登録・更新されません。FreeプランはCSVプレビューまで、Import実行はPremiumプランです。'],
            ['question' => 'AIの候補は自動承認されますか？', 'answer' => 'AI候補は理由と信頼度を表示しますが、自動承認されません。人間が確認してから反映します。'],
            ['question' => '管理者のお知らせメールはどこから届きますか？', 'answer' => '管理者のお知らせと週次サマリーは info@kimegami.jp から届きます。配信停止は週次サマリー画面で設定でき、運用上の問い合わせは '.config('opsdeck.support_email').' へご連絡ください。'],
            ['question' => '問い合わせ先はどこですか？', 'answer' => '運用上の問い合わせは '.config('opsdeck.support_email').' へご連絡ください。Evidence本文やパスワード、トークンはメールに記載しないでください。'],
        ]]);
    }
}
