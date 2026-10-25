# OpsDeck 外部ストレージ運用メモ

## 方針

- 本番のオブジェクトストレージはCloudflare R2を想定し、LaravelのS3互換Filesystem経由で扱います。アプリケーションコードからR2固有APIを呼び出しません。
- 開発・テストは`local`ディスクを使い、本番バケットへ接続しません。
- 本番・開発・テストは別バケットと別認証情報を用意し、バケットは非公開にします。
- Cloudflareアカウントを他サービスと共用する場合も、OpsDeckはOpsDeck専用バケットと専用トークンだけを使います。FurimaDeckの画像データ・バケット・認証情報はこのアプリの対象外です。
- 証跡と取込元ファイルの保存先は`OPSDECK_EVIDENCE_DISK`と`OPSDECK_IMPORT_DISK`で個別に選びます。本番でR2を有効化する際は両方を`opsdeck_objects`に設定します。
- `source_storage_disk`が未設定の既存Import履歴は`OPSDECK_LEGACY_IMPORT_DISK`から読みます（初期値`local`）。R2へ切り替える場合も、既存ローカルファイルを移行・検証するまでこの値と旧ファイルを保持し、Import設定の切替で過去データを失わないようにします。
- `opsdeck_objects`は`private`可視性で設定され、公開URLは構成しません。証跡はログイン・組織権限を確認するアプリケーション経由のダウンロードです。
- ファイル名はアップロード時に生成し、組織ID配下のキーに保存します。DBには保存ディスク、オブジェクトキー、MIME型、サイズ等を保持します。
- XLSX解析はFilesystemからストリームで一時ファイルへコピーし、解析後に削除します。CSVはストリーム処理を継続します。

## 環境変数

開発・テストでは既定値の`local`を維持します。R2を使う環境だけ、その環境専用の値を秘密情報管理機能から設定してください。値をGitやこの文書へ記録しないでください。

| サービス | 環境 | 非公開バケット | R2 APIトークン名 | 環境変数 |
| --- | --- | --- | --- | --- |
| OpsDeck | 本番 | `opsdeck-prod-files` | `opsdeck-prod-storage` | `OPSDECK_STORAGE_*` |
| OpsDeck | 開発 | `opsdeck-dev-files` | `opsdeck-dev-storage` | `OPSDECK_TEST_STORAGE_*` |

トークン名はCloudflare Dashboard上の識別用ラベルです。実際の接続には各トークンから発行されたAccess Key IDとSecret Access Keyを使い、本番と開発で共有しません。開発環境では通常`OPSDECK_EVIDENCE_DISK=local`、`OPSDECK_IMPORT_DISK=local`のままにし、R2確認時だけ`opsdeck_objects_test`ディスクを明示して利用します。通常の開発テストは開発用バケットだけを対象にします。本番接続確認を行う場合は、別途の明示許可を得たうえで顧客データを使わない一時オブジェクトに限定します。

Cloudflare DashboardのR2から専用APIトークンを作成し、権限は`Object Read & Write`、対象はOpsDeck専用バケットだけに絞ります。アカウント全バケットの管理権限は付与しません。R2バケットは初期状態で非公開です。`r2.dev`のPublic Development URLと公開カスタムドメインは有効化しないでください。トークン作成後に一度だけ表示されるSecret Access Keyは安全なSecret Managerへ保存します。

標準エンドポイントは`https://<ACCOUNT_ID>.r2.cloudflarestorage.com`です。R2のAPIトークンは発行前にR2の購入・有効化が必要です。権限名・バケットスコープ・エンドポイントはCloudflareの[認証と権限の公式手順](https://developers.cloudflare.com/r2/api/tokens/)を、公開アクセスを無効にする手順は[Public Bucketsの公式手順](https://developers.cloudflare.com/r2/buckets/public-buckets/)を参照してください。

```dotenv
APP_ENV=production
OPSDECK_EVIDENCE_DISK=opsdeck_objects
OPSDECK_IMPORT_DISK=opsdeck_objects
OPSDECK_LEGACY_IMPORT_DISK=local
OPSDECK_STORAGE_EXPECTED_PRODUCTION_BUCKET=opsdeck-prod-files
OPSDECK_STORAGE_EXPECTED_DEVELOPMENT_BUCKET=opsdeck-dev-files
OPSDECK_STORAGE_DRIVER=s3
OPSDECK_STORAGE_KEY=<環境専用のアクセスキーID>
OPSDECK_STORAGE_SECRET=<環境専用のシークレット>
OPSDECK_STORAGE_REGION=auto
OPSDECK_STORAGE_BUCKET=opsdeck-prod-files
OPSDECK_STORAGE_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
OPSDECK_STORAGE_PATH_STYLE=true
```

開発用R2接続確認では、本番項目を流用せず、別バケット用のテスト項目だけを設定します。通常の開発保存先は引き続き`local`です。

```dotenv
OPSDECK_TEST_STORAGE_DRIVER=s3
OPSDECK_TEST_STORAGE_KEY=<opsdeck-dev-storageのAccess Key ID>
OPSDECK_TEST_STORAGE_SECRET=<opsdeck-dev-storageのSecret Access Key>
OPSDECK_TEST_STORAGE_REGION=auto
OPSDECK_TEST_STORAGE_BUCKET=opsdeck-dev-files
OPSDECK_TEST_STORAGE_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
OPSDECK_TEST_STORAGE_PATH_STYLE=true
```

旧環境との互換性のため`OPSDECK_OBJECT_STORAGE_*`も読み込みますが、新旧両方が設定されている場合は`OPSDECK_STORAGE_*`を優先します。`OPSDECK_STORAGE_DRIVER`は`s3`を指定してください。`opsdeck_objects`はアプリ内部の論理ディスク名で、証跡と取込の保存先を有効化するには`OPSDECK_EVIDENCE_DISK`と`OPSDECK_IMPORT_DISK`もこの値に切り替えます。

本番では証跡と取込の保存先が`opsdeck_objects`であること、必須値がすべて設定されていること、PHP依存アダプターが導入済みであることをデプロイ前に確認します。デプロイ準備チェックは`OPSDECK_STORAGE_BUCKET`が`OPSDECK_STORAGE_EXPECTED_PRODUCTION_BUCKET`と一致しない場合、または`OPSDECK_STORAGE_EXPECTED_DEVELOPMENT_BUCKET`と同一の場合に失敗します。現在の本番値は`opsdeck-prod-files`、開発値は`opsdeck-dev-files`です。将来プロバイダーまたはバケット名を変更するときは、期待値と実際の接続先を秘密情報管理側で適切に更新します。`<ACCOUNT_ID>`、Access Key ID、Secret Access Keyは各環境の実値へ置き換えます。秘密値はユーザーの本番サーバーへ手作業で設定し、このリポジトリの`.env`へコピーしません。

## アップロード・ダウンロード・削除の現状

- 証跡・取込ファイルは、アップロード時に認証・組織権限・形式・サイズ・保存容量を確認し、組織ID配下へUUIDと許可済み形式から得た拡張子を使って保存します。元のファイル名を保存キーに使わず、DBには保存先ディスクとキーを記録します。
- Cloudflare R2のS3 APIはオブジェクトACLをサポートしません。導入済みFlysystem S3アダプターは既定で`private` ACLを送るため、R2ディスクはSDKのアップロード前コールバックでACLを除去します。現在のアップロード上限（証跡10MB、取込5MB）では単一`PutObject`を使い、将来の上限変更時もマルチパート開始前にR2互換性を再検証します。`visibility=private`だけを公開防止の根拠にせず、R2バケットを非公開に保ち、ダウンロードはOpsDeckの認可済み経路に限定します。
- 保存処理が失敗した場合は、事前に生成したキーを使って補償削除を試み、画面には接続情報を含まない再試行案内を表示します。補償削除にも失敗した場合は、キーやファイル情報を含まない固定メッセージだけをログに記録します。実R2でのタイムアウト時の挙動は未検証です。
- 証跡のダウンロードは組織スコープと閲覧権限を確認してから、DBレコードに記録された保存先から、DB記録のMIME型と許可済み拡張子を付けて配信します。ストリーム開始前に検出できる保存先の読取・応答準備障害は固定ログと日本語の503画面で案内し、ファイル不存在の404と区別します。ヘッダー送信後のストリーム途中障害は通常のHTTP応答へ切り替えられず、不完全なダウンロードになる可能性があります。実R2での障害時挙動は未検証です。Import処理も各バッチに記録された保存先を使うため、現在の保存先設定を切り替えても既存ファイルの参照先は変わりません。
- 証跡の利用者向け削除は現在無効です。`EvidencePolicy`が削除を拒否し、ルートも`destroy`を公開していません。登録失敗時にアップロード済みファイルを補償削除する処理はありますが、これは利用者向け削除機能ではありません。誤削除からの復元機能も未実装です。
- Fake Storageで保存・認可付きダウンロード・登録失敗時の補償削除を、AWS SDKモックでは実際の`UploadedFile`→`putFileAs`→S3 `PutObject`、`GetObject`、`DeleteObject`要求を検証しています。S3アダプターは`league/flysystem-aws-s3-v3`で導入済みです。2026-10-23に`opsdeck_objects_test`ディスクと`PrivateUploadStorageService`から合成`UploadedFile`を`opsdeck-dev-files`へ保存し、読戻し後に削除できることを確認しました。さらに同日、開発用バケットのみを使うHTTP統合テストで、証跡の画面経由アップロード・認可付きダウンロード・別組織からの404拒否・削除後404、CSV取込元ファイルの保存・Preview JobによるR2読取・Preview画面表示・別組織からのプレビュー拒否・オブジェクト削除、およびR2上の証跡・CSV取込元を既存の暗号化バックアップへ含める処理・検証・復号後の開発用R2への書戻しを確認しました（3テスト、53アサーション）。統合バックアップテストでは専用の一時SQLite・ローカルバックアップ先を使い、R2のソース・復元テストオブジェクトは削除済みです。ユーザーの明示許可に基づき、同日`opsdeck-prod-files`に顧客データを含まない一時オブジェクトを1件だけ書き込み、読戻しと削除を確認しました（preflight・write・read・delete成功）。削除後に対象オブジェクトが存在しないことも確認済みです。さらに2026-10-23、ユーザーからCloudflare Dashboardで本番バケットの`r2.dev` URL・公開カスタムドメインが無効であり、`opsdeck-prod-storage`トークンの対象が本番専用バケットに限定されているとの確認を得ました（ユーザー確認であり、こちらでDashboardを独立検査したものではありません）。開発用`opsdeck-dev-storage`トークンも`opsdeck-dev-files`だけに限定されているとのユーザー確認を得ました（こちらでDashboardを独立検査したものではありません）。実R2でのタイムアウト・部分失敗時の挙動は未確認です。
- 2026-10-24に開発用R2統合テストを再実行し、3テスト・56アサーションが成功しました。テストは専用一時SQLiteと`opsdeck-dev-files`だけを使い、一時オブジェクトの削除まで成功しています。OpsDeckバックアップは引き続きローカル保存のみで、FurimaDeckのバケット・データにはアクセスしていません。

開発用R2のHTTP統合テストは通常のテスト一式ではスキップされ、明示的なプロセス環境変数がある場合だけ実行します。PowerShellでは`$env:OPSDECK_RUN_R2_INTEGRATION_TESTS='1'`を設定してから`php artisan test --compact tests/Feature/OpsDeckR2StorageIntegrationTest.php`を実行し、終了後は`Remove-Item Env:\OPSDECK_RUN_R2_INTEGRATION_TESTS`でフラグを解除します。テストは`opsdeck_objects_test`のバケット名が`opsdeck-dev-files`でなければ失敗し、アプリ環境が`testing`以外なら実行しません。秘密値をコマンド行や出力へ記載しません。

## バックアップ

オブジェクトストレージはバックアップそのものではありません。Cloudflare R2は本番の証跡・CSV/XLSX取込元ファイルを保存するクラウド上の場所です。DBはVPSのMySQLにあります。`opsdeck:backup`はDBのダンプに加え、DBが参照する証跡と取込元ファイルをそれぞれの保存先（本番ではR2）から読み、`OPSDECK_BACKUP_PATH`へひとまとめに保存します。したがってバックアップ一式はR2ではなく、VPSのローカルディスクに保存できます。開発PCで実行する場合は開発PC内、本番VPSで実行する場合はVPS内に保存されます。既定の保存先は`storage/app/backups`です。

OpsDeck 1.0ではバックアップを実行サーバー内のローカル保存先にのみ保管し、R2へ複製しません。`.env`では`OPSDECK_BACKUP_REMOTE_ENABLED=false`、`OPSDECK_BACKUP_LOCAL_RETENTION_DAYS=30`を使用してください。バックアップ用R2バケット、資格情報、保持期限の設定は不要です。`.env.example`にあるその他の`OPSDECK_BACKUP_REMOTE_*` / `OPSDECK_BACKUP_STORAGE_*`項目は、残存する回帰テスト用の互換設定であり、1.0では変更しません。外部バックアップを有効化する設定が本番に入ると、バックアップ処理と本番準備チェックは失敗します。`opsdeck:backup:verify`はローカルバックアップ内容を検証し、`opsdeck:backup:restore-test`は専用の空DBを安全確認した後、ローカル世代を検証・復元します。

証跡バックアップは現在の`OPSDECK_EVIDENCE_DISK`だけを走査せず、各DBレコードに保存された`storage_disk`と`storage_path`を使います。保存先切替後も旧ディスク上の参照ファイルを対象にし、`metadata-only`とDB未参照のオブジェクトは含めません。DBが参照するファイルを読み込めない場合はバックアップ全体を失敗させ、不完全なバックアップを成功扱いしません。検証処理は従来形式の証跡インデックスも受け入れます。

現在の決定ではバックアップ内容は平文です（`OPSDECK_BACKUP_ENCRYPTION_ENABLED=false`）。マニフェストは`APP_KEY`でHMAC認証し、各ファイルはSHA-256で整合性を検証します。これは暗号化ではなく、DB・証跡・取込元の内容を秘匿しません。Linuxではローカルバックアップディレクトリを`0700`、ファイルを`0600`に制限します。Windowsでは対象フォルダーのNTFS ACLを管理者とサービス実行ユーザーに限定してください。暗号化を明示的に有効にする場合のみ`sodium`と`OPSDECK_BACKUP_ENCRYPTION_KEY`が必要です。既存の暗号化形式の世代は同じ鍵で検証・復元できます。

バックアップを同じVPS内にだけ置く方針では、VPS本体の故障・侵害からバックアップを守れません。別の保管先への複製はOpsDeck 1.0では行わず、必要になった場合は別途方針決定と復元試験が必要です。DBの復元テストは専用DBへの復元・検証を行います。R2上の証跡・Importファイル自体は通常そのまま残ります。R2上のファイルが欠損した場合は、DBを同じバックアップ世代から先に復旧した後、`opsdeck:backup:restore-files <世代ディレクトリ>`でローカルバックアップとの照合だけを行えます。このコマンドは既定でdry-runです。書き戻す場合はメンテナンスモードにしてQueue workerを停止し、dry-runの結果を確認したうえで`--apply`を付けて実行し、確認プロンプトに応答します。既存ファイルは上書きせず、異なる内容があれば書込み前に中止します。復元先はDB記録のOpsDeck専用プライベートディスクに限定され、R2を使う場合は環境に応じて本番`opsdeck-prod-files`または開発`opsdeck-dev-files`だけが許可されます。FurimaDeck用バケットには接続しません。この機能はオブジェクトファイルの復元だけを行い、本番DBの復元は行いません。DBの復元方法・作業者・停止時間は別途運用手順として確定が必要です。

## 将来のIDrive e2移行手順（未実行）

この節は将来の運用手順であり、今回IDrive e2を契約・設定・接続するものではありません。S3互換であってもプロバイダー間の属性・暗号化・チェックサム動作が同一とは限らないため、非本番環境で先に検証します。

1. 移行判断の前に、最新のDB・証跡・Importバックアップを作成し、`opsdeck:backup:verify`と専用DBへの`opsdeck:backup:restore-test`が成功することを確認します。実行できない場合は移行を開始しません。
2. DBの`evidence.storage_disk`と`import_batches.source_storage_disk`をディスク別に棚卸しします。`opsdeck_objects`以外（例：旧`local`）を参照するファイルは、R2バケットのコピー対象に含まれません。旧ディスクのファイルとDB参照は保持し、別途移行を計画するか、そのディスクを引き続き利用できる状態にします。
3. IDrive e2で専用の非公開バケットと対象バケット限定のアクセスキーを作成します。リージョンとS3 endpointはIDrive e2のアカウント画面・[公式Endpoint一覧](https://www5.idrive.com/s3-storage-e2/e2-endpoint-urls)で確認します。R2側も対象バケットに限定した読み取りキーを使い、キーをコマンド引数・チャット・Git・ログへ書きません。
4. サーバー管理者アカウントで`rclone config`を実行し、R2 remote（provider `Cloudflare`、region `auto`、R2 endpoint）とIDrive e2 remote（provider `IDrive`、アカウントで確認したendpoint）を設定します。設定ファイルの場所は`rclone config file`で確認し、他ユーザーから読めない権限にしたうえで、`rclone config encryption set`で設定ファイルを暗号化し、復号パスフレーズは別の安全な場所に保管します。rcloneの[公式S3設定](https://rclone.org/s3/)およびIDrive e2の[Rclone移行手順](https://status.idrive.com/s3-storage-e2/rclone)を参照します。
5. R2からe2へは、削除を行わない`copy`で初回コピーをします。まずdry-runで対象を確認し、問題がなければ同じコピーを実行します。例のremote名・バケット名は実値へ置き換えます。

   ```sh
   rclone copy --dry-run --checksum r2:SOURCE_BUCKET e2:TARGET_BUCKET
   rclone copy --checksum r2:SOURCE_BUCKET e2:TARGET_BUCKET
   ```

   `sync`は宛先にだけあるデータを削除し得るため、この移行手順では使いません。IDrive e2バケットでデフォルト暗号化を有効にする場合、rclone側の暗号化設定とチェックサムへの影響をIDrive e2公式手順で確認してから実行します。
6. コピー前後の件数・合計サイズ・キー一覧を比較し、rcloneの`check`で整合性を検証します。両方の`rclone size`と次の比較結果を保存します。

   ```sh
   rclone size r2:SOURCE_BUCKET
   rclone size e2:TARGET_BUCKET
   rclone check r2:SOURCE_BUCKET e2:TARGET_BUCKET
   ```

   両サービスでハッシュ比較できない場合は、[rclone公式のデータ整合性手順](https://rclone.org/s3/)に従い`rclone check --download`で内容を比較します。e2バケットが非公開であること、暗号化設定、必要なオブジェクト属性・メタデータも確認します。差異が1件でもあれば切替を中止し、対象キーを特定して再コピー・再検証します。
7. 切替時はOpsDeckをメンテナンス状態にし、Web書込み、Queue worker、Schedulerによるファイル変更が止まったことを確認して最終差分を`copy`・再検証します。その後、アプリの秘密情報管理で`OPSDECK_STORAGE_KEY`、`SECRET`、`REGION`、`BUCKET`、`ENDPOINT`をe2用へ差し替えます。`OPSDECK_EVIDENCE_DISK`と`OPSDECK_IMPORT_DISK`は同じ論理ディスク名`opsdeck_objects`を維持し、設定キャッシュを更新してから、証跡アップロード・認可付きダウンロード・Import Preview・削除/失敗時の挙動を非本番データで確認します。
8. 問題があれば書込みを止めます。e2側で切替後に作成されたデータをR2へ戻して整合性を確認してから、アプリ設定をR2へ戻します。切替後すぐにR2を削除せず、バックアップ・読み書き・復元を確認できる期間は旧バケットを非公開のまま保持します。

この手順はプロバイダー間コピーと切替の計画であり、OpsDeckがオブジェクトを自動復元・自動移行する機能を意味しません。DBを復元しただけではファイルは復元されないため、オブジェクト復旧も別途検証が必要です。

## 外部接続テストのゲート

接続先の疎通だけを確認する場合は、対象環境の`.env`と環境変数を設定したアプリケーション上で、次のコマンドを実行します。確認用UUIDオブジェクトを1件だけ書き込み、読戻し後に削除します。DB・顧客ファイル・メール送信には触れません。`--environment=production`は`APP_ENV=production`でのみ許可され、期待バケット名が一致しない場合は書込み前に終了します。

```powershell
php artisan opsdeck:storage:connectivity-test --environment=production
php artisan opsdeck:storage:connectivity-test --environment=development
```

削除に失敗した場合は、コマンドがバケット名と一時オブジェクトキーを表示します。その場合だけ、Cloudflare Dashboardから表示された当該オブジェクトを削除し、削除を確認してください。

ComposerのS3アダプターは導入済みです。2026-10-23に非本番の`opsdeck-dev-files`でLaravel Filesystem、`PrivateUploadStorageService`、証跡・CSV ImportのHTTP経路、証跡・CSV取込元をバックアップへ含める処理を確認しました。過去の統合テストでは暗号化バックアップ形式を使いましたが、現在の既定は平文です。証跡のアップロード・認可付きダウンロード・他社アクセス拒否・削除後404、CSV取込元の保存・Preview Jobからの読取・画面表示・他社プレビュー拒否、Fake Storageを使った両ファイルのバックアップ検証を確認済みです（3テスト、53アサーション）。同日、本番`opsdeck-prod-files`では許可を得て一時オブジェクト1件の書込・読戻し・削除を確認しました。ユーザーから、本番バケットの公開アクセスが無効で、`opsdeck-prod-storage`トークンが本番専用バケットに限定されていること、開発用`opsdeck-dev-storage`も`opsdeck-dev-files`だけに限定されていることの確認を得ています（いずれもDashboardの独立検査ではありません）。さらに、分離されたローカルMySQLの`opsdeck`から平文バックアップを一時領域に作成・検証し、`opsdeck_restore_test_local`への復元とテーブル清掃に成功しました。一時バックアップは試験後に削除済みです。外部バックアップ用バケットからの復元、R2のタイムアウト・部分失敗、完全なImport実行・取消は未確認です。顧客データを使う本番業務フローの試験は行っていません。

2026-10-23の全PHPUnit回帰テストは1,153件中1,148件成功、5件スキップ、9,096アサーションでした。平文バックアップの作成・APP_KEYによるマニフェスト認証・改ざん検出、平文/暗号化双方のImport元ファイル検証、平文の証跡・Importファイルを含むリモート世代のステージングを含みます。Evidenceテストを先に実行すると隔離SQLiteに残った古いファイル参照をバックアップテストが拾う順序依存があったため、バックアップテストの準備でテストDB内の旧Evidence・Importファイル参照だけを除外しました。Evidence先行の関連テスト123件も949アサーションで成功しています。これはテストデータの分離であり、本番DBや業務コードの変更ではありません。

将来のIDrive e2等への移行は、同じS3互換ディスク設定を差し替える前提です。実際の移行、同期、削除、契約はこの作業に含みません。
