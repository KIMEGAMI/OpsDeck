<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(): View
    {
        $recoveryGuides = array_filter(config('opsdeck.recovery_guides'), static function (array $guide): bool {
            $releaseFeature = $guide['release_feature'] ?? null;

            return ! is_string($releaseFeature) || config('opsdeck.release_features.'.$releaseFeature) === true;
        });

        return view('help.index', [
            'plans' => config('opsdeck.plans'),
            'recoveryGuides' => $recoveryGuides,
            'faqs' => [
                ['question' => 'OpsDeckは何を管理するサービスですか？', 'answer' => '社員、IT資産、利用中のクラウドサービス、契約更新、証跡、セキュリティの確認項目を検索でき、期限と対応状況を確認するための管理サービスです。MDMやEDR、端末の遠隔操作を提供するものではありません。'],
                ['question' => '確認画面を見るだけで会社のデータは変わりますか？', 'answer' => '変わりません。まず内容を確認し、取り込みを実行したときだけ会社のデータが登録・更新されます。元のExcelファイルと取込履歴は残ります。'],
                ['question' => '社内のExcel管理表はどう取り込めばよいですか？', 'answer' => '元のExcelは変更せず、取り込むシートをCSV、またはXLSXとして確認画面に進みます。CSVはExcelの表の内容を保存する形式で、UTF-8（BOM付き可）またはShift_JIS（Windows-31J）に対応しています。列名の行と、注記などがある場合はデータ開始行を指定し、画面に表示される列の対応と「新規・更新・確認待ち・エラー」を確認してください。確認画面を見るだけでは会社のデータは登録・更新されません。社員・端末・SaaSサービスには登録上限があります。上限を超えた行はエラーとして確認できます。Google Sheets連携は現在提供していません。'],
                ['question' => 'Excelファイルはどの形式で保存すればよいですか？', 'answer' => 'CSV、TXT、XLSXを取り込めます。旧形式のXLSファイルは対象外です。元ファイルを残したまま、対象シートをCSV（UTF-8またはShift_JIS）かXLSXで別保存してください。XLSXに複数シートがある場合は、データ取込時に対象シートを選択できます。保存後は確認画面で文字化け、日付・金額、列の対応を確認してください。現在公開している機能はすべて無料で利用できます。'],
                ['question' => 'AI補助を使うと、どのデータが外部へ送られますか？', 'answer' => '組織のAI補助を有効にし、サーバー側の利用条件を満たす場合、列マッピング候補の取得時に列名、対象データ種別、OpsDeck側の候補項目だけをOpenAIのGPT-6 Lunaへ送信します。取込データの行、ファイル全体、シート全体は送信しません。列名に含まれるメールアドレス・電話番号らしき値は伏せますが、氏名などは自動判定できないため、個人情報を含む列名は匿名化してから利用してください。AI候補は1組織あたり1日3回までです。AIを使わず手動でマッピングすることもできます。'],
                ['question' => 'AIの候補は自動承認されますか？', 'answer' => 'AI候補は理由と信頼度を表示しますが、自動承認されません。人間が確認してから反映します。'],
                ['question' => '管理者のお知らせメールはどこから届きますか？', 'answer' => '管理者のお知らせと週次サマリーは info@kimegami.jp から届きます。配信停止は週次サマリー画面で設定でき、運用上の問い合わせは '.config('opsdeck.support_email').' へご連絡ください。'],
                ['question' => '問い合わせ先はどこですか？', 'answer' => '運用上の問い合わせは '.config('opsdeck.support_email').' へご連絡ください。証跡ファイルの内容やパスワード、トークンはメールに記載しないでください。'],
            ],
        ]);
    }
}
