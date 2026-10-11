<x-layouts.app>
    <section class="card" aria-labelledby="admin-health-title">
        <h1 id="admin-health-title">運用ヘルス</h1>
        <p><a href="{{ route('admin.dashboard') }}">運営管理へ戻る</a></p>
        <p><a href="{{ route('admin.queue-errors') }}">Queue・Webhookエラー詳細</a> / <a href="{{ route('admin.product-metrics') }}">製品利用状況</a></p>
        <dl>
            <dt>総合状態</dt><dd role="status" aria-live="polite">{{ $healthStatus }}</dd>
            <dt>最新バックアップ</dt><dd>{{ $backupStatus }}</dd>
            <dt>最新バックアップ確認日時</dt><dd>{{ $latestBackupAt }}</dd>
            <dt>バックアップ復元テスト</dt><dd>{{ $backupRestoreStatus }}</dd>
            <dt>復元テスト完了日時</dt><dd>{{ $backupRestoreReport['completed_at'] }}</dd>
            <dt>DBマイグレーション</dt><dd>{{ match ($migrationStatus) { 'ok' => '適用済み', 'pending' => '未適用 '.$pendingMigrationCount.'件', default => '未確認（DBまたはmigration履歴を確認できません）' } }}</dd>
            <dt>Scheduler稼働確認</dt><dd>{{ match ($schedulerHeartbeatStatus) { 'ok' => '正常', 'stale' => '更新が停止しています', default => '未確認（Schedulerの初回実行待ち）' } }}</dd>
            <dt>Queue失敗件数</dt><dd>{{ $failedJobs ?? '未確認（failed_jobsテーブルがありません）' }}</dd>
            <dt>Queue監視</dt><dd>{{ $staleQueuedJobs === null ? '未確認（現在のQueue方式またはjobsテーブルを監視できません）' : '監視中' }}</dd>
            <dt>滞留Queue件数</dt><dd>{{ $staleQueuedJobs ?? '未確認' }}</dd>
            <dt>ImportタイムアウトとQueue再配信猶予</dt><dd>{{ match ($queueTimeoutStatus) { 'ok' => '正常', 'invalid' => '設定不整合', 'unknown' => '未確認（Queue基盤側の設定確認が必要）', default => '対象外（非本番の同期Queue）' } }}</dd>
            <dt>Queueワーカー</dt><dd>{{ match ($queueWorkerStatus) { 'ok' => '稼働中（待機中を含む）', 'stale' => '更新停止（Worker未起動または停止）', 'invalid' => '本番で同期Queueを使用中', 'unknown' => '未確認（監視対象外のQueue方式）', default => '対象外（非本番の同期Queue）' } }}</dd>
            <dt>Webhookエラー件数</dt><dd>{{ $webhookErrors }}</dd>
            <dt>処理停止Webhook件数</dt><dd>{{ $staleWebhooks }}</dd>
            <dt>滞留Import件数</dt><dd>{{ $staleImportBatches }}</dd>
        </dl>
        <p class="muted">詳細確認や厳格な終了コード判定は、CLIの <code>php artisan opsdeck:health --strict</code> を使用してください。</p>
        <p class="muted">定期タスクの実行には、サーバー側で <code>php artisan schedule:run</code> を毎分起動する設定が必要です。初回実行後、ここに稼働状態が表示されます。</p>
    </section>
</x-layouts.app>
