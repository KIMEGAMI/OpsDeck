<?php

namespace App\Services\Import;

use Illuminate\Validation\ValidationException;
use Throwable;

class ImportFailureMessage
{
    private const array SAFE_MESSAGE_PREFIXES = [
        'CSVはUTF-8またはShift_JIS' => 'CSVの文字コードをUTF-8またはShift_JIS（Windows-31J）にして、再度プレビューしてください。',
        '指定した見出し範囲' => '選択した見出し範囲がファイル内にあるか確認し、再度プレビューしてください。',
        '指定した行に' => '選択した行に正しい見出しがあるか確認し、再度プレビューしてください。',
        '指定した行が' => '選択した行に正しい見出しがあるか確認し、再度プレビューしてください。',
        '見出しに同じ名前' => '列名の重複や空欄を修正し、一意な見出しにして再度プレビューしてください。',
        '見出し範囲は設定された上限' => '見出し行を設定上限の範囲内で選び直してください。',
        'データ開始行は見出し範囲より後' => 'データ開始行を見出し範囲より後に指定してください。',
        '見出し行番号が許可範囲外' => '見出し行を設定上限の範囲内で選び直してください。',
        'データ開始行番号は見出し範囲' => 'データ開始行を見出し範囲より後に指定してください。',
        '見出し行または取込データに' => 'XLSXの数式・結合セル・非表示行または列を確認し、値に修正して再度プレビューしてください。',
        '見出し範囲をまたぐ結合セル' => 'XLSXの見出し範囲をまたぐ結合セルを解除して、再度プレビューしてください。',
        'XLSXの結合セル範囲が重複' => 'XLSXの結合セル範囲を見直して、再度プレビューしてください。',
        'XLSXのXML展開サイズまたは行・列数' => 'XLSXを分割して、行・列・ファイルサイズを設定上限内にして再度プレビューしてください。',
        'XLSXに対応できない日付値' => 'XLSXの日付セルを確認し、対応する日付形式に修正して再度プレビューしてください。',
        'Previewを作成する元データが見つかりません' => '取込元ファイルが見つかりません。ファイルを再アップロードして再度プレビューしてください。',
        '取込元ファイルを検証できません' => '取込元ファイルを検証できません。ファイルを再アップロードして再度お試しください。',
        '取込元ファイルがアップロード後に変更されています' => '取込元ファイルが変更されているため処理を停止しました。ファイルを再アップロードして再度お試しください。',
    ];

    public static function safeMessage(?Throwable $exception): ?string
    {
        if (! $exception instanceof ValidationException) {
            return null;
        }

        foreach ($exception->errors()['file'] ?? [] as $message) {
            if (! is_string($message)) {
                continue;
            }

            if (preg_match('/^(?:CSV|XLSX|Google Sheets)の取込上限（[\d,]+行）を超えています。(?:ファイル|シート)を分割して再度お試しください。$/u', $message) === 1) {
                return $message;
            }

            foreach (self::SAFE_MESSAGE_PREFIXES as $safePrefix => $safeMessage) {
                if (str_starts_with($message, $safePrefix)) {
                    return $safeMessage;
                }
            }
        }

        return null;
    }
}
