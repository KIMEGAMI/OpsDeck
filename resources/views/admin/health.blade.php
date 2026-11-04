<x-layouts.app>
    <section class="card" aria-labelledby="admin-health-title">
        <h1 id="admin-health-title">システムの稼働状況</h1>
        <p><a href="{{ route('admin.dashboard') }}">運営管理へ戻る</a></p>
        <p><a href="{{ route('admin.queue-errors') }}">バックグラウンド処理・連携エラーの詳細</a> / <a href="{{ route('admin.product-metrics') }}">製品利用状況</a></p>
        <dl>
            <dt>総合状態</dt><dd role="status" aria-live="polite">{{ $healthStatus }}</dd>
            <dt>最新バックアップ</dt><dd>{{ $backupStatus }}</dd>
            <dt>最新バックアップ確認日時</dt><dd>{{ $latestBackupAt }}</dd>
            <dt>バックアップの記録件数</dt><dd>{{ $backupReport['backup_count'] }}件</dd>
            <dt>計測済みバックアップデータ容量</dt><dd>{{ $backupReport['backup_payload_size_display'] }}（{{ $backupReport['measured_backup_count'] }}件分）</dd>
            @if ($backupReport['unmeasured_backup_count'] > 0)
                <dt>容量を計測できなかった過去バックアップ</dt><dd>{{ $backupReport['unmeasured_backup_count'] }}件</dd>
            @endif
            <dt>バックアップ保存先の空き容量</dt><dd>{{ $backupReport['disk_free_display'] }} / {{ $backupReport['disk_total_display'] }}</dd>
            <dt>OpsDeck顧客ファイル保存量（Import元・証跡）</dt><dd>{{ $platformStorage['used_display'] }}</dd>
            <dt>OpsDeckファイル保存枠（サービス全体）</dt><dd>{{ $platformStorage['limit_display'] }}（残り{{ $platformStorage['remaining_display'] }}@if ($platformStorage['used_percentage'] !== null)・使用率{{ $platformStorage['used_percentage'] }}%@endif）</dd>
            <dt>DBテーブル・索引容量（概算）</dt><dd>{{ $databaseStorage['size_display'] }}@if ($databaseStorage['table_count'] !== null)（{{ number_format($databaseStorage['table_count']) }}テーブル）@endif</dd>
            <dt>バックアップ復元テスト</dt><dd>{{ $backupRestoreStatus }}</dd>
            <dt>復元テスト完了日時</dt><dd>{{ $backupRestoreReport['completed_at'] }}</dd>
            <dt>データベースの更新状況</dt><dd>{{ match ($migrationStatus) { 'ok' => '適用済み', 'pending' => '未適用 '.$pendingMigrationCount.'件', default => '未確認（データベース接続または更新履歴を確認できません）' } }}</dd>
            <dt>定期処理の稼働確認</dt><dd>{{ match ($schedulerHeartbeatStatus) { 'ok' => '正常', 'stale' => '更新が停止しています', default => '未確認（定期処理の初回実行待ち）' } }}</dd>
            <dt>バックグラウンド処理の失敗件数</dt><dd>{{ $failedJobs ?? '未確認（失敗履歴を保存する表がありません）' }}</dd>
            <dt>処理待ちの状態</dt><dd>{{ match ($queueBacklogStatus) { 'ok' => '正常（設定時間を超えた待機処理なし）', 'stale' => '要対応（設定時間を超えた待機処理あり）', 'not_applicable' => '対象外（開発環境の即時実行方式）', 'invalid' => '設定不整合（本番で即時実行方式を使用中）', default => '未確認（この処理方式では最古の待機時間を自動判定できません）' } }}</dd>
            <dt>長時間待っている処理の件数</dt><dd>{{ $staleQueuedJobs ?? ($queueBacklogStatus === 'stale' ? '件数未確認（古い待機処理あり）' : '正確な件数は未取得') }}</dd>
            <dt>取込の制限時間と再試行設定</dt><dd>{{ match ($queueTimeoutStatus) { 'ok' => '正常', 'invalid' => '設定不整合', 'unknown' => '未確認（バックグラウンド処理基盤の設定確認が必要）', default => '対象外（開発環境の即時実行方式）' } }}</dd>
            <dt>バックグラウンド処理を動かすプログラム</dt><dd>{{ match ($queueWorkerStatus) { 'ok' => '稼働中（待機中を含む）', 'stale' => '停止中または未起動', 'invalid' => '設定不整合（本番で即時実行方式を使用中）', 'unknown' => '未確認（監視対象外の処理方式）', default => '対象外（開発環境の即時実行方式）' } }}</dd>
            <dt>Stripeからの契約通知で処理エラーになった件数</dt><dd>{{ $webhookErrors }}件</dd>
            <dt>受信後に処理待ちとなっているStripe契約通知</dt><dd>{{ $staleWebhooks }}件</dd>
            @if (config('opsdeck.release_features.storage_addon'))
                <dt>追加容量のStripe定期照合</dt><dd>{{ $storageAddonReconciliationStatus }}</dd>
                <dt>追加容量の最終照合日時</dt><dd>{{ $storageAddonReconciliationCheckedAt }}</dd>
                @if ($storageAddonReconciliation !== null)
                    <dt>照合した会社</dt><dd>{{ number_format((int) $storageAddonReconciliation->organizations_checked) }}社</dd>
                    <dt>契約と容量の差異</dt><dd>{{ number_format((int) $storageAddonReconciliation->mismatch_count) }}件</dd>
                    <dt>確認できなかった契約</dt><dd>{{ number_format((int) $storageAddonReconciliation->unavailable_count) }}件</dd>
                    @if ($storageAddonMismatchOrganizationIds !== [])
                        <dt>差異がある組織ID</dt><dd>{{ implode('、', array_map(static fn (mixed $organizationId): string => number_format((int) $organizationId), $storageAddonMismatchOrganizationIds)) }}</dd>
                    @endif
                    @if ($storageAddonUnavailableOrganizationIds !== [])
                        <dt>確認できなかった組織ID</dt><dd>{{ implode('、', array_map(static fn (mixed $organizationId): string => number_format((int) $organizationId), $storageAddonUnavailableOrganizationIds)) }}</dd>
                    @endif
                @endif
            @else
                <dt>組織ごとの保存容量</dt><dd>固定・追加容量の提供なし</dd>
            @endif
            <dt>長時間止まっているデータ取込の件数</dt><dd>{{ $staleImportBatches }}</dd>
        </dl>
        <p class="muted">処理エラーは、Stripeから通知を受け取った後にアプリ側の処理が失敗した状態です。処理待ちが設定時間を超えた場合も含め、件数や対応手順は<a href="{{ route('admin.queue-errors') }}">バックグラウンド処理・Stripe通知のエラー</a>で確認できます。</p>
        @if (config('opsdeck.release_features.storage_addon'))
            <p class="muted">追加容量は毎月Stripe契約と照合します。差異がある場合、この画面に対象組織IDが表示され、定期処理の実行結果が「要確認」になります。運営担当者はStripeと該当会社の契約を確認し、Webhookの再処理または手動調査を行ってください。照合処理は契約や容量を自動変更しません。</p>
            <details>
                <summary>追加容量の差異が出た場合の確認手順</summary>
                <ol>
                    <li>この画面の状態と組織IDを控えます。顧客名、メールアドレス、秘密値はログや問い合わせに貼り付けないでください。</li>
                    <li>Stripeの管理画面で該当する契約を確認し、契約状態、Premiumと追加容量のPrice・数量、次回更新日の予約内容をOpsDeck側の契約と照合します。</li>
                    <li>Stripe通知の処理待ち・失敗がある場合は、<a href="{{ route('admin.queue-errors') }}">バックグラウンド処理・Stripe通知のエラー</a>で確認します。Workerと支払い結果を確認できるまで再試行しないでください。</li>
                    <li>Stripe側と通知処理の状態を確認した後、サーバーで <code>php artisan opsdeck:storage-addon:reconcile</code> を実行して再確認します。このコマンドは照合結果を記録しますが、契約や容量を変更しません。</li>
                    <li>差異が残る、または確認できない場合は、容量の値を直接変更せず運営責任者へ引き継ぎます。保存済みファイルの削除や手動変更は行いません。</li>
                </ol>
            </details>
        @else
            <p class="muted">組織ごとの保存容量は固定です。追加容量の販売・Stripe定期照合は行っていません。</p>
        @endif
        <p class="muted">DB容量はMySQL/MariaDBの表と索引の概算です。実ディスクの空き容量やバックアップ容量とは異なります。今後作成するDB・証跡・取込元のバックアップは、実行サーバー内の設定されたローカル保存先に保存し、R2へ複製しません。既定では暗号化されません。新しいバックアップ全体の作成と検証が成功した後、{{ config('opsdeck.backup.local_retention_days', 30) }}日を過ぎた世代のうちローカル検証に成功したものを整理します。方針導入前の世代は自動削除しません。保存先の空き容量を定期的に確認してください。顧客ファイル保存量はImport元ファイルと証跡の合計です。OpsDeck以外のサービスは含みません。</p>
        @php
            $queueWorkerConnection = (string) config('queue.default');
            $queueWorkerQueue = (string) config('queue.connections.'.$queueWorkerConnection.'.queue', 'default');
            $queueWorkerTimeout = max((int) config('opsdeck.import.job_timeout_seconds'), (int) config('services.stripe.webhook_job_timeout_seconds'));
        @endphp
        @if ($queueWorkerConnection === 'sync')
            <p class="muted">現在は処理をその場で実行する設定です。本番では、データベースまたはRedisなどのバックグラウンド処理方式を設定してください。</p>
        @else
            <p class="muted">バックグラウンド処理が「停止中または未起動」の場合、本番サーバーで次のコマンドを使うプログラムを常時動かしてください。接続先・処理待ち場所・制限時間は現在の設定から表示しています。プロセス管理ツールなどで停止時に再起動する設定も行ってください。</p>
            <p><code>php artisan queue:work {{ $queueWorkerConnection }} --queue={{ $queueWorkerQueue }} --timeout={{ $queueWorkerTimeout }}</code></p>
        @endif
        <p class="muted">処理を開始すると、待機中のメール送信なども実行される可能性があります。開始前に待機中の処理と送信予定をサーバー担当者に確認してください。</p>
        <p class="muted">定期処理には、サーバー側で <code>php artisan schedule:run</code> を毎分起動する設定が必要です。初回実行後、ここに稼働状態が表示されます。さらに詳しく調べるときは、サーバーのコマンド画面で <code>php artisan opsdeck:health --strict</code> を実行してください。</p>
    </section>
</x-layouts.app>
