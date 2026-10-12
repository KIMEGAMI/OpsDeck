# OpsDeck

OpsDeckは、会社のIT資産・SaaS・契約・更新期限・セキュリティ確認をまとめ、ITコストの見直しと実施内容の記録を支援する業務サービスです。Excel台帳の取込みから削減候補の確認、対応状況・証跡の管理、導入効果の振り返りまでを一つの流れで扱います。

OpsDeckは削減額を保証するサービスではありません。金額は登録データと設定値に基づく概算で、実際の削減実績は利用者が確認して記録します。

## 主な機能

- 社員、IT資産、SaaSアカウント、契約と更新予定の管理
- CSV / XLSX / Google Sheetsからのデータ取込み、プレビュー、マッピング、実行、履歴確認
- 未使用資産や契約更新をもとにしたコスト削減候補と、確認済み削減実績の記録
- セキュリティ管理項目、証跡、取引先向け質問票・回答承認の管理
- ダッシュボード、お知らせ、週次サマリー、導入効果レポート
- 組織メンバー・権限管理、監査ログ、Free / Premiumの利用制御とStripe Billing

データ取込み後も、列の対応付けや実行内容を確認してから処理します。AI機能は任意で、AIが質問票回答を自動承認することはありません。

## Free / Premium

現在のプラン設定は [`config/opsdeck.php`](config/opsdeck.php) が正です。

| プラン | 主な内容 |
| --- | --- |
| Free | CSVの取込みプレビュー、基本ダッシュボード、コスト集計。登録上限は社員20人、資産50件、SaaS 10件、管理者1人。 |
| Premium | 会社単位で月額9,800円（税別）。適用税額を加算し、決済前にStripe Checkoutで合計を表示します。CSV / XLSX / Google Sheets取込み、取込み実行、更新・セキュリティ機能、削減候補・実績、証跡、質問票、CSV出力、導入効果レポート。登録上限は社員200人、資産500件、SaaS 100件、管理者5人。 |

プラン機能や上限を変更する場合は、画面表示だけでなくサーバー側の利用制御・テスト・Stripe価格設定との整合を確認してください。

## ローカル開発

### 必要なもの

- PHP 8.4、Composer 2
- Node.js 22、npm
- 開発用DB（`.env.example` の初期値はSQLite）

PowerShellでリポジトリのルートから実行します。

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
if (-not (Test-Path database/database.sqlite)) { New-Item -ItemType File database/database.sqlite | Out-Null }
php artisan migrate
npm install --ignore-scripts
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

画面を開発しながら確認する場合は、`npm run build` の代わりに別のターミナルで `npm run dev` を実行できます。`.env` は各自のローカル用に作成し、Gitへ追加しないでください。本番シークレットをローカルへコピーしないでください。

### 外部サービス

Google Sheets、メール送信、Stripe、AIを使う場合は、各サービスの接続情報を環境変数または本番のSecret Managerに設定します。キーやパスワードをソースコード、README、画面ログへ記載しないでください。外部連携を設定しなくても、未設定の機能を除くローカル開発・テストができます。

## テストとCI

```powershell
php artisan test --compact
npm run build
```

GitHub ActionsはPHP 8.4で依存関係監査・フォーマット・PHPテストを実行し、MySQL 8.4とMariaDB 11.4でもテストします。別ジョブでフロントエンドをビルドします。CIの成否はGitHub Actionsの実行結果で確認してください。

## 本番導入

本番はSQLiteではなくMySQLまたはMariaDBを使用し、HTTPS、`APP_DEBUG=false`、安全なセッションCookie、耐久性のあるQueue、共有Cache、監視可能なログ、メール送信経路、定期バックアップを設定してください。バックアップは取得だけでなく、アプリDBと分離した専用DBへの復元検証も必要です。

有料契約を開始する前にStripeの本番キー・有効なJPY月額Premium価格・Webhookを設定してください。販売者情報と特定商取引法・プライバシー・利用規約の内容も、実際の事業者情報に合わせて確認してください。

設定確認コマンド:

```powershell
php artisan opsdeck:deployment-check
```

このコマンドは秘密値を表示せず、設定・DB接続とマイグレーション・バックアップ／復元ツール・Queue／Cache・メール・Stripe・販売者情報などを確認します。Stripeの本番設定がある場合、Premium価格の状態をStripe APIへ照会します。出力が`not_ready`なら本番公開前に不足項目を解消してください。

本番の設定項目・運用確認は[製品成長・導入仕様書](OPSDECK_PRODUCT_GROWTH_SPEC.md)と、アプリの`opsdeck:deployment-check`の実装・テストを参照してください。設定値の完全な一覧は[`.env.example`](.env.example)にありますが、そこには実シークレットを記載しないでください。

## 主な運用コマンド

利用可能なコマンドの一覧は次で確認できます。

```powershell
php artisan list
```

ヘルスチェック、バックアップ、復元検証、導入効果スナップショットなどのコマンドは、実行環境と対象DBを確認してから運用手順に沿って実行してください。特に復元テスト先は本番アプリDBと分離された専用DBでなければなりません。
