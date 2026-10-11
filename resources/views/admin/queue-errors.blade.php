<x-layouts.app>
    <section class="card" aria-labelledby="queue-errors-title">
        <h1 id="queue-errors-title">Queue / Webhookエラー</h1>
        <p><a href="{{ route('admin.dashboard') }}">運営管理へ戻る</a></p>
        <h2>Import失敗</h2>
        <form method="get" action="{{ route('admin.queue-errors') }}">
            <label for="import_reference">利用者から受け取ったImport参照番号</label>
            <input id="import_reference" name="import_reference" value="{{ $importReference }}" placeholder="IMP-123">
            <button type="submit">参照番号で検索</button>
        </form>
        <ul>
            @forelse ($failedImports as $batch)
                <li>IMP-{{ $batch->id }} / {{ $batch->source_type }} / {{ $batch->created_at }}</li>
            @empty
                <li>該当するImport失敗はありません。</li>
            @endforelse
        </ul>
        <h2>処理が滞留しているImport</h2>
        <p>設定時間を超えて状態が更新されていません。QueueとWorkerを確認し、処理中でないことを確認してから利用者へ再試行方法を案内してください。ここでは自動再実行しません。</p>
        <ul>
            @forelse ($staleImports as $batch)
                <li>IMP-{{ $batch->id }} / {{ $batch->source_type }} / 状態：{{ $batch->status }} / 状態更新：{{ $batch->updated_at }} / 実行開始：{{ $batch->started_at ?? '未記録' }}</li>
            @empty
                <li>滞留しているImportはありません。</li>
            @endforelse
        </ul>
        <h2>Stripe照会</h2>
        <form method="get" action="{{ route('admin.queue-errors') }}">
            <label for="stripe_reference">利用者から受け取ったStripe照会番号</label>
            <input id="stripe_reference" name="stripe_reference" value="{{ $stripeReference }}" placeholder="STRIPE-evt_...">
            <button type="submit">照会番号で検索</button>
        </form>
        @if ($stripeReference !== null)
            @if ($stripeReferenceEvent !== null)
                <p>照会番号：STRIPE-{{ $stripeReferenceEvent->stripe_event_id }} / 種別：{{ $stripeReferenceEvent->event_type }} / 状態：{{ $stripeReferenceEvent->processing_error !== null ? '処理エラー' : ($stripeReferenceEvent->processed_at !== null ? '処理済み' : '未処理') }} / 受信日時：{{ $stripeReferenceEvent->created_at }}</p>
            @else
                <p>該当するStripeイベントはありません。</p>
            @endif
        @endif
        <h2>Queue</h2>
        <ul>
            @forelse ($failedJobs as $job)
                <li>{{ $job->uuid }} / {{ $job->queue }} / {{ $job->failed_at }}</li>
            @empty
                <li>Queueエラーはありません。</li>
            @endforelse
        </ul>
        <h2>Stripe Webhook</h2>
        <ul>
            @forelse ($webhookErrors as $event)
                <li>{{ $event->stripe_event_id }} / {{ $event->event_type }} / {{ $event->created_at }}</li>
            @empty
                <li>Webhookエラーはありません。</li>
            @endforelse
        </ul>
        <h2>処理停止Webhook</h2>
        <p>受信後{{ config('opsdeck.stripe_webhook.stale_after_minutes') }}分を超えて未処理のイベントです。Stripeからの再送状況を確認してください。</p>
        <ul>
            @forelse ($staleWebhooks as $event)
                <li><a href="{{ route('admin.queue-errors', ['stripe_reference' => 'STRIPE-'.$event->stripe_event_id]) }}">STRIPE-{{ $event->stripe_event_id }}</a> / {{ $event->event_type }} / {{ $event->created_at }}</li>
            @empty
                <li>処理停止Webhookはありません。</li>
            @endforelse
        </ul>
    </section>
</x-layouts.app>
