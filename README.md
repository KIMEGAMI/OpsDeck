# OpsDeck

OpsDeckは、会社のIT資産・SaaS・契約・更新期限・セキュリティ確認をまとめ、ITコストの見直しと実施内容の記録を支援する業務サービスです。Excel台帳の取込みから削減候補の確認、対応状況・証跡の管理までを一つの流れで扱います。

OpsDeckは削減額を保証するサービスではありません。金額は登録データと設定値に基づく概算で、実際の削減実績は利用者が確認して記録します。

## 主な機能

- 社員、IT資産、SaaSアカウント、契約と更新予定の管理
- CSV / XLSXからのデータ取込み、プレビュー、手動またはAI提案による列マッピング、実行、履歴確認
- 未使用資産や契約更新をもとにしたコスト削減候補と、確認済み削減実績の記録
- セキュリティ管理項目、証跡、取引先向け質問票・回答承認の管理
- ダッシュボード、お知らせ、週次サマリー
- 組織メンバー・権限管理、監査ログ、Free / Premiumの利用制御とStripe Billing

データ取込み後も、列の対応付けや実行内容を確認してから処理します。AI機能は任意で、AIが質問票回答を自動承認することはありません。

## Free / Premium

現在のプラン設定は [`config/opsdeck.php`](config/opsdeck.php) が正です。

| プラン | 主な内容 |
| --- | --- |
| Free | CSV/TXTから実データを登録（社員20人、資産50件、SaaS 10件まで）、基本ダッシュボード、コスト集計。管理者は1人まで。 |
| Premium | 会社単位で月額9,800円（税別）。適用税額を加算し、決済前にStripe Checkoutで合計を表示します。CSV / XLSX取込み、AI列マッピングの提案（取込前に人が確認）、更新・セキュリティ機能、削減候補・実績、証跡、質問票、CSV出力。登録上限は社員200人、資産500件、SaaS 100件、管理者5人。 |

追加容量課金は実装を保持していますが、OpsDeck 1.0の公開対象外です。1.0では`OPSDECK_RELEASE_STORAGE_ADDON=false`にし、容量追加の購入画面・処理を利用できない状態にします。将来の公開は、料金・税設定・Stripe実接続を確認してから別途判断します。

Google Sheets連携、導入効果レポート、紹介、質問票のAI下書き、作業時間計測も1.0の公開対象外です。実装コードが残っていても、公開環境では対応する`OPSDECK_RELEASE_*`設定を`false`にします。

プラン機能や上限を変更する場合は、画面表示だけでなくサーバー側の利用制御・テスト・Stripe価格設定との整合を確認してください。

## ローカル開発

### 必要なもの

- PHP 8.4、Composer 2
- PHP拡張: `mbstring`、`openssl`、`sodium`、`xmlreader`、`zip`
- DBに応じたPDO拡張: SQLiteは`pdo_sqlite`、MySQL/MariaDBは`pdo_mysql`（テストには`pdo_sqlite`と`openssl`が必要）
- Node.js 22、npm
- 開発用DB（`.env.example` の初期値はSQLite）

Windowsでは`php --ini`でCLIが読み込む`php.ini`を確認し、必要な拡張を有効にしてください。`php -m`で有効化された拡張を確認できます。

PowerShellでリポジトリのルートから実行します。

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
if (-not (Test-Path database/database.sqlite)) { New-Item -ItemType File database/database.sqlite | Out-Null }
php artisan migrate
npm ci --ignore-scripts
npm run build
php artisan serve --host=127.0.0.1 --port=8007
```

画面を開発しながら確認する場合は、`npm run build` の代わりに別のターミナルで `npm run dev` を実行できます。`.env` は各自のローカル用に作成し、Gitへ追加しないでください。本番シークレットをローカルへコピーしないでください。

### WindowsでローカルMySQLを使う場合

`.env`の接続先はMySQLサービスの待受先と一致させます。たとえば、サービス名が`MySQL84`で、PC内の3306番ポートで待ち受けている場合は次の設定です。`DB_PASSWORD`は例では空欄です。実際のパスワードは自分のPCの`.env`にだけ入力し、このファイルやチャットには貼り付けないでください。通常、DB名・ユーザー名・ホスト・ポートを引用符で囲む必要はありません。パスワードに空白や`#`などが含まれる場合は`.env`の引用符ルールに従ってください。アプリ専用ユーザー（例：`opsdeck_app`）を使い、`root`はアプリ接続に使わないでください。

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opsdeck
DB_USERNAME=opsdeck_app
DB_PASSWORD=
```

サービスが「実行中」でも、MySQLがTCP接続を受け付けているとは限りません。管理者として起動したPowerShellで、サービス状態と3306番の待受を別々に確認します。

```powershell
Get-Service -Name MySQL84 | Select-Object Status, Name
Get-NetTCPConnection -State Listen -LocalPort 3306 -ErrorAction SilentlyContinue |
    Select-Object LocalAddress, LocalPort
Test-NetConnection -ComputerName 127.0.0.1 -Port 3306
```

待受がなく接続テストが失敗する場合、ユーザー名・パスワード・GRANTではなく、MySQLの待受ポートやネットワーク設定を先に確認します。MySQLのポートが3306以外なら、`.env`の`DB_PORT`も同じ値にします。接続先を確認する前にマイグレーションを実行しないでください。接続後は`php artisan opsdeck:deployment-check --skip-provider-verification --json`でDB接続とマイグレーション状態を確認できます。このオプションはResend・Stripeへの外部確認を省略し、メール送信は行いません。外部確認が未実施のため、このチェック結果だけで本番公開可能とは判定されません。

### 外部サービス

メール送信、Stripe、AI列マッピングを使う場合は、各サービスの接続情報を環境変数または本番のSecret Managerに設定します。1.0対象外機能のGoogle Sheets連携などは公開環境で無効にしてください。キーやパスワードをソースコード、README、画面ログへ記載しないでください。外部サービスを設定しなくても、未設定の機能を除くローカル開発・テストができます。

本番のEvidence・Import元ファイルはCloudflare R2（Laravel Filesystemの非公開S3互換ディスク）を使用する方針です。S3アダプターを導入し、2026-10-23に開発用バケット`opsdeck-dev-files`で、証跡の画面経由アップロード・認可付きダウンロード・別組織からのアクセス拒否、CSV取込元ファイルの保存・Preview処理まで確認しました。さらに明示許可のもと、本番`opsdeck-prod-files`で顧客データを含まない一時オブジェクト1件の書込・読戻し・削除に成功しました。ローカル開発・テストでは通常`local`ディスクを維持し、本番キーを共有しません。本番起動時は設定されたバケットが`OPSDECK_STORAGE_EXPECTED_PRODUCTION_BUCKET`と一致し、`OPSDECK_STORAGE_EXPECTED_DEVELOPMENT_BUCKET`とは異なることも確認します。この疎通確認ではCloudflare Dashboardの公開アクセス設定・トークン範囲や本番業務フローを検証していません。バックアップ範囲と未確認事項は[外部ストレージ運用メモ](OPSDECK_OBJECT_STORAGE_OPERATIONS.md)を参照してください。

## テストとCI

```powershell
php artisan test --compact
npm run build
```

GitHub ActionsはPHP 8.4で依存関係監査・フォーマット・PHPテストを実行し、MySQL 8.4とMariaDB 11.4でもテストします。別ジョブでフロントエンドをビルドします。CIの成否はGitHub Actionsの実行結果で確認してください。

## 本番導入

本番はSQLiteではなくMySQLまたはMariaDBを使用し、HTTPS、`APP_DEBUG=false`、安全なセッションCookie、耐久性のあるQueue、共有Cache、監視可能なログ、メール送信経路、定期バックアップを設定してください。バックアップは取得だけでなく、アプリDBと分離した専用DBへの復元検証も必要です。

有料契約を開始する前にStripeの本番キー・有効なJPY月額Premium価格・Webhookを設定してください。販売者情報と特定商取引法・プライバシー・利用規約の内容も、実際の事業者情報に合わせて確認してください。

### XServer VPS（Ubuntu 26.04）の初回確認

対象として共有された構成は4GBメモリ・仮想4コア・NVMe 150GBです。150GBは他の予定サービスとも共有するため、OpsDeckが全容量を使える前提にしません。次の確認は読み取り専用で、サービス起動・ユーザー作成・設定変更は行いません。

XServer VPSの初期SSHユーザー名は通常`root`です。すでに自分で管理ユーザーを作成している場合は、その名前を使います。これは初期設定を行う管理用アカウントで、後述のアプリ実行ユーザーとは別です。WindowsのPowerShellから接続します。

```powershell
ssh root@<VPSのIPアドレス>
```

OSセットアップ時にSSH鍵を登録した場合は、パスワード認証が無効になっていることがあります。その場合は、PCに保存した秘密鍵を指定します。

```powershell
ssh -i "$env:USERPROFILE\.ssh\<秘密鍵ファイル名>" root@<VPSのIPアドレス>
```

秘密鍵は自分のPCから移動・共有せず、パスワードや秘密鍵の内容をチャットへ貼らないでください。接続できない場合は、XServerの[SSH接続方法](https://vps.xserver.ne.jp/support/manual/man_server_ssh_connect.php)で鍵設定と接続情報を確認してください。

SSH接続後、Linuxのターミナルで実行します。

```bash
whoami
cat /etc/os-release | grep -E '^(PRETTY_NAME|VERSION_ID)='
free -h
nproc
df -hT /
ss -tln
```

結果を確認するまでは、パッケージのインストール、DB作成、マイグレーション、ファイアウォール変更をしないでください。結果を共有する場合は、パスワード・秘密鍵・`.env`の内容を貼らないでください。

このVPSでは追加確認でApacheとPHP 8.5-FPMが稼働し、Apache/PHP-FPMプロセスは`www-data`でした。Nginxは検出されていないため、Nginxを追加インストールせず、まず既存のApache仮想ホストを確認します。

```bash
apache2ctl -S
apache2ctl -M | grep -E 'proxy_fcgi|rewrite|headers|ssl'
ss -xl | grep -E 'php.*fpm|fpm.*sock'
```

上の3つは読み取り専用です。最初のコマンドで公開ドメインと既存VirtualHostの設定ファイル、2つ目で有効なApacheモジュール、3つ目でPHP-FPMのUnixソケットを確認できます。該当する表示がない場合も、モジュールの有効化・サービス再起動はせず、そのまま結果を確認します。Apache/PHP-FPMの既存アプリを停止・変更しないため、設定ファイルを編集する前にこの情報を管理者と確認してください。

`/var/www`が`root:root`所有で、SSH用のデプロイユーザーに書き込み権限やsudo権限がない場合、そのユーザーからアプリ配置・Apache設定・DB作成はできません。管理者にOpsDeck用の独立した`/var/www/opsdeck`配置先と専用PHP-FPM実行ユーザー／Poolを準備してもらいます。既存アプリのディレクトリや共有PHP-FPM設定を上書きせず、`chmod 777`やデプロイユーザーへの広いsudo付与もしません。実際にどのユーザーと権限を設定するかは、Apache仮想ホストと既存サービスを確認してから決めてください。

MySQLが`127.0.0.1:3306`で待ち受けていても、`mysqladmin ping`が認証エラーになることがあります。これは指定したMySQLユーザーの認証・権限を確認できなかった状態で、単独ではDB停止を意味しません。アプリ専用DBユーザーを作成した後、`.env`の`DB_HOST`・`DB_DATABASE`・`DB_USERNAME`・`DB_PASSWORD`を合わせ、認証付きで接続を確認します。接続確認のためにパスワードなしログインやMySQLのTLS検証無効化を設定しないでください。

DBの管理者認証を確認する場合は、VPSの管理者から案内されたMySQL管理者アカウントを使います。`root`はそのアカウント名の例です。次のコマンドは接続確認のみで、パスワードは入力プロンプトに入力します。パスワードをコマンドへ直接書いたり、チャットへ貼ったりしないでください。

```bash
mysql --host=127.0.0.1 --port=3306 --user=root --password -e 'SELECT VERSION();'
```

アプリ用DB（`opsdeck`）と`opsdeck_app`ユーザーを管理者が作成し、VPS上の`.env`へ同じ接続情報を設定した後は、アプリユーザーで次を実行して確認します。これは読み取り専用の`SELECT 1`だけを実行します。

```bash
mysql --host=127.0.0.1 --port=3306 --user=opsdeck_app --password --database=opsdeck -e 'SELECT 1;'
```

`Access denied`なら接続先MySQLは応答していますが、入力したユーザー名・パスワード、接続元ホスト、またはDB権限を管理者に確認します。`Unknown database`なら`DB_DATABASE`と作成済みのDB名を確認します。管理者アカウントへログインできない場合、sudo権限のないデプロイユーザーだけではDBユーザーや権限を作れないため、VPS管理者に依頼してください。アプリユーザーで接続できるまでは、マイグレーションを実行しないでください。

アプリ実行用のLinuxユーザー名は`opsdeck`、DB接続専用ユーザー名は`opsdeck_app`、復元テスト専用DBユーザー名は`opsdeck_restore`を推奨します。用途が異なるユーザーです。`opsdeck`はアプリのファイルを所有する実行用アカウントで、SSHログインには使いません。初期セットアップ時の管理作業では`root`を使うことがありますが、アプリ実行やアプリからのDB接続に`root`を使わないでください。

本番の`.env`はVPS上にのみ作成します。次は設定項目の意味と値の決め方です。`<...>`は説明用の置換箇所で、そのまま入力しないでください。

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<決定した公開ドメイン>
APP_KEY=<このVPSのOpsDeck用にLaravelで生成>

LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=30

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opsdeck
DB_USERNAME=opsdeck_app
DB_PASSWORD=<VPS内で生成した専用の長いパスワード>

# Import元ファイルと証跡のOpsDeck全体上限（MiB）。他サービス・DB・バックアップの容量は含まない。
OPSDECK_PLATFORM_STORAGE_LIMIT_MIB=51200

# 既定は平文バックアップ。APP_KEYでマニフェストを認証するため、バックアップと同じ障害ドメインにだけ保管しないこと。
OPSDECK_BACKUP_ENCRYPTION_ENABLED=false
# 暗号化を明示的に有効化する場合だけ設定。過去の暗号化バックアップ復元にも必要。
OPSDECK_BACKUP_ENCRYPTION_KEY=<暗号化を有効にする場合だけ、Secret Managerに保存する32バイト鍵のBase64値>
OPSDECK_BACKUP_RESTORE_CONNECTION=backup_restore
OPSDECK_BACKUP_RESTORE_DB_DRIVER=mysql
OPSDECK_BACKUP_RESTORE_DB_HOST=127.0.0.1
OPSDECK_BACKUP_RESTORE_DB_PORT=3306
OPSDECK_BACKUP_RESTORE_DB_DATABASE=opsdeck_restore_test_prod
OPSDECK_BACKUP_RESTORE_DB_USERNAME=opsdeck_restore
OPSDECK_BACKUP_RESTORE_DB_PASSWORD=<復元テスト専用ユーザーのパスワード>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=resend
MAIL_PASSWORD=<ResendのSMTP認証キー>
MAIL_FROM_ADDRESS=info@kimegami.jp
MAIL_FROM_NAME="${APP_NAME}"
OPSDECK_ADMIN_MAIL_FROM_ADDRESS=info@kimegami.jp
CONTACT_TO_ADDRESS=support@kimegami.jp
MAIL_DELIVERY_JOB_BACKOFF_SECONDS=30,120
RESEND_API_TIMEOUT=10
```

Premium必須のAI列マッピングは、次の設定が揃うまで無効のままにしてください。`OPENAI_API_KEY`は本番VPS上のアクセス制限された環境設定またはSecret Managerに登録し、README・チャット・開発PCへ本番キーをコピーしません。接続先は`https://api.openai.com/v1/responses`です。顧客ファイルの行やセル値は送信せず、マッピングに必要な列名・データ種別・OpsDeck候補項目だけを送信します。利用者が候補を確認・修正してから取込を確定します。

本番で有効化する場合の設定名は以下です。月額上限は会社ごとに500円相当、Freeプランは0円です。為替値は運用開始時点のUSD/JPYを確認して設定し、モデル単価は[OpenAI API公式料金表](https://platform.openai.com/pricing)を確認のうえ、`.env.example`の`OPSDECK_AI_MODEL_PRICES_PER_MILLION_TOKENS`を更新してください。料金表と為替を確認できない場合はAIを有効化せず、手動マッピングを利用します。

```dotenv
OPSDECK_AI_ENABLED=true
OPSDECK_AI_PROVIDER=openai
OPENAI_API_KEY=
OPENAI_API_ENDPOINT=https://api.openai.com/v1/responses
OPSDECK_AI_USD_TO_JPY_RATE=<設定時点のUSD/JPY換算値>
OPSDECK_AI_PREMIUM_MONTHLY_BUDGET_JPY=500
```

メールの既存設定名は変えません。`MAIL_MAILER=smtp`、`MAIL_HOST=smtp.resend.com`、`MAIL_USERNAME=resend`、`MAIL_PASSWORD`が設定されている場合、重複送信を避けるためResend APIを自動選択します。追加の切替設定は不要です。既存の`MAIL_PASSWORD`（Resend APIキー）をAPI認証にも利用します。メールごとに安定した冪等キーを付け、Resendのキー保持期間（24時間）内は同じキーで再試行します。期間を過ぎた不明な送信結果は自動再送せず、管理者確認が必要です。`RESEND_IDEMPOTENT_MAIL_ENABLED=true`は、Resend SMTPの標準設定以外でAPI経路を明示的に有効にする場合に使います。Resend以外のSMTPでは従来のSMTP経路を使います。送信ドメイン確認では`RESEND_DOMAIN_CHECK_API_KEY`を一時的にコマンド環境へ渡します。`DB_HOST=127.0.0.1`はMySQLを同じVPSで動かす場合の例です。外部DBを使う場合は、その接続情報に置き換えます。公開ドメインとDBの配置が未確認の間は、該当値を確定・入力しません。`APP_KEY`はLaravelの導入時に一度だけ生成し、利用開始後は再生成しないでください。バックアップ暗号化キーもVPS用に生成し、他環境と共有しないでください。シークレットはREADME・Git・チャットへ貼り付けないでください。

`OPSDECK_PLATFORM_STORAGE_LIMIT_MIB=51200`は、決定済みのOpsDeck全体の保存上限50GiB（50 × 1,024MiB）です。これは本番R2に保存するImport元ファイルと証跡の合計に対するアプリ側の上限で、Cloudflare側のバケット制限や請求上限そのものではありません。VPSの150GB、他サービス、DB、ログ、ローカルバックアップの容量とは別です。未設定のまま本番にすると新しいファイル保存を拒否し、導入チェックも未準備として扱います。実VPSの`.env`はこの値を手動で設定してください。このリポジトリ変更ではVPSや実`.env`には触れていません。

バックアップ復元用DBはアプリDBと別に作成し、ユーザー`opsdeck_restore`にはその専用DBだけの必要な権限を付与します。接続名は`backup_restore`、DB名は`opsdeck_restore_test_`で始めます。バックアップ鍵は`.env.example`に記載されたPHPコマンドで生成し、バックアップファイルとは別の安全な保管先にも保管してください。鍵を失うとバックアップを復号できません。ここに示した設定は必要項目の抜粋です。全項目と条件は[`.env.example`](.env.example)および本番準備チェックの案内に従ってください。

メールの送信テストやQueue workerの起動は、送信上限、送信待ちジョブ、送信先を確認してから行います。設定だけではメールは送信されません。

設定確認コマンドは、プロジェクトディレクトリで実行します。

```bash
php artisan opsdeck:deployment-check
```

このコマンドは秘密値を表示せず、設定・DB接続とマイグレーション・バックアップ／復元ツール・Scheduler／Queue worker／Cache・メール・Stripe・販売者情報などを確認します。Schedulerは`opsdeck:scheduler:heartbeat`の最終更新が5分以内であることが必要です。Stripeの本番設定がある場合、Premium価格の状態をStripe APIへ照会します。出力が`not_ready`なら本番公開前に不足項目を解消してください。

手動でプロセスを一時確認する場合は、別々のターミナルで次を実行します。これは開発PCまたはVPS上での一時実行用です。常時運用ではサービスマネージャー等でプロセスを監視・再起動できるようにしてください。

```bash
# Scheduler: 登録済みスケジュールを毎分確認
php artisan schedule:work

# Queue worker: database接続のdefaultキューを処理
php artisan queue:work database --queue=default --sleep=3 --timeout=300
```

`schedule:work`は週次サマリーやメール再試行をキューへ登録し、Queue workerは未処理メールを実際に送る場合があります。メール事業者・送信上限・送信待ちジョブを確認する前に起動しないでください。`--timeout`は`OPSDECK_IMPORT_JOB_TIMEOUT_SECONDS`に合わせ、`DB_QUEUE_RETRY_AFTER`より短く設定します。

本番の設定項目・運用確認は[製品成長・導入仕様書](OPSDECK_PRODUCT_GROWTH_SPEC.md)と、アプリの`opsdeck:deployment-check`の実装・テストを参照してください。設定値の完全な一覧は[`.env.example`](.env.example)にありますが、そこには実シークレットを記載しないでください。

## 主な運用コマンド

利用可能なコマンドの一覧は次で確認できます。

```powershell
php artisan list
```

ヘルスチェック、バックアップ、復元検証、導入効果スナップショットなどのコマンドは、実行環境と対象DBを確認してから運用手順に沿って実行してください。特に復元テスト先は本番アプリDBと分離された専用DBでなければなりません。
