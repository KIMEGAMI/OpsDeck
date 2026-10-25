@php
    $stripeEventTypeLabels = config('opsdeck.stripe_webhook.event_type_labels', []);
    $queueNameLabels = config('opsdeck.queue.name_labels', []);
@endphp

<x-layouts.app>
    <section class="card" aria-labelledby="queue-errors-title">
        <h1 id="queue-errors-title">バックグラウンド処理・Stripe通知のエラー</h1>
        <p><a href="{{ route('admin.dashboard') }}">運営管理へ戻る</a></p>
        <h2>Import失敗</h2>
        <form method="get" action="{{ route('admin.queue-errors') }}">
            <label for="import_reference">利用者から受け取ったImport参照番号</label>
            <input id="import_reference" name="import_reference" value="{{ $importReference }}" placeholder="IMP-123">
            <button type="submit">参照番号で検索</button>
        </form>
        <ul>
            @forelse ($failedImports as $batch)
                <li>IMP-{{ $batch->id }} / {{ config('opsdeck.import_source_type_labels.'.$batch->source_type, '取込ファイル') }} / {{ $batch->created_at }}</li>
            @empty
                <li>該当するImport失敗はありません。</li>
            @endforelse
        </ul>
        <h2>処理が滞留しているImport</h2>
        <p>設定時間を超えて、取込の状態が更新されていません。運用担当者に、実行待ちの処理（Queue）に残っていないか、処理を動かすプログラム（Worker）が稼働しているか確認してもらってください。画面の状態が変わった場合は、完了または失敗になるまで待ってから再読み込みしてください。この画面から自動で再実行することはありません。</p>
        <ul>
            @forelse ($staleImports as $batch)
                <li>
                    IMP-{{ $batch->id }} / {{ config('opsdeck.import_source_type_labels.'.$batch->source_type, '取込ファイル') }} / 状態：{{ config('opsdeck.import_batch_status_labels.'.$batch->status, '状態を確認してください') }} / 状態更新：{{ $batch->updated_at }} / 実行開始：{{ $batch->started_at ?? '未記録' }}
                    @if ($batch->status === 'retrying')
                        <p>再試行の準備が止まっています。運用担当者に処理を動かすプログラム（Worker）の稼働を確認してもらい、状態が変わるまで利用者には再試行ボタンを押さないよう案内してください。</p>
                    @elseif ($batch->status === 'rolling_back')
                        <p>取消処理が止まっています。実行待ちの処理（Queue）に対象処理が残っていないことと、処理を動かすプログラム（Worker）が停止していることを運用担当者に確認してください。確認が取れても、データベースを直接修正したり取消を再実行したりせず、運用担当者へ相談してください。</p>
                    @else
                        <p>実行待ちの処理（Queue）と処理を動かすプログラム（Worker）を運用担当者に確認してもらい、処理が完了または失敗に変わるまで再実行しないでください。</p>
                    @endif
                </li>
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
                <p>照会番号：STRIPE-{{ $stripeReferenceEvent->stripe_event_id }} / 通知内容：{{ $stripeEventTypeLabels[$stripeReferenceEvent->event_type] ?? 'その他のStripe通知' }}（{{ $stripeReferenceEvent->event_type }}） / 状態：{{ $stripeReferenceEvent->processing_error !== null ? '処理エラー' : ($stripeReferenceEvent->processed_at !== null ? '処理済み' : '未処理') }} / 受信日時：{{ $stripeReferenceEvent->created_at }}</p>
            @else
                <p>該当するStripeイベントはありません。</p>
            @endif
        @endif
        <h2>バックグラウンド処理（Queue）の失敗</h2>
        <p>失敗した処理はこの画面から自動再実行しません。再実行すると、登録の重複やメールの二重送信が起きる場合があります。再実行が必要な場合は、サーバーログで原因を調べ、外部サービスへの処理が未完了で、再実行しても二重処理にならないと運用担当者が確認できたものだけを、サーバーのコマンドで <code>php artisan queue:retry &lt;UUID&gt;</code> のように1件ずつ再試行してください。全件一括の再試行は避けてください。</p>
        <p>メールが送信されたか分からない場合は、失敗した処理を再実行せず、メール事業者の配信履歴を確認してください。Resendの重複送信確認待ちは<a href="{{ route('admin.mail-delivery-reviews.index') }}">メール配信の手動確認</a>で確認できます。</p>
        <ul>
            @forelse ($failedJobs as $job)
                <li>再実行ID：{{ $job->uuid }} / 処理先：{{ $queueNameLabels[$job->queue] ?? 'バックグラウンド処理' }}（{{ $job->queue }}） / 失敗日時：{{ $job->failed_at }}</li>
            @empty
                <li>失敗したバックグラウンド処理はありません。</li>
            @endforelse
        </ul>
        <h2>Stripe通知の処理エラー</h2>
        <ul>
            @forelse ($webhookErrors as $event)
                <li>{{ $event->stripe_event_id }} / {{ $stripeEventTypeLabels[$event->event_type] ?? 'その他のStripe通知' }}（{{ $event->event_type }}） / {{ $event->created_at }}</li>
            @empty
                <li>Webhookエラーはありません。</li>
            @endforelse
        </ul>
        <h2>Stripe通知の処理待ち</h2>
        <p>Stripeから通知を受け取ってから{{ config('opsdeck.stripe_webhook.stale_after_minutes') }}分を超えても、アプリ側の処理が完了していません。Stripe側の再送だけでは解消しないことがあるため、運用担当者にバックグラウンド処理と実行プログラム（Worker）の稼働を確認してもらってください。手動で再処理する場合は、二重登録などが起きないことを先に調べてください。</p>
        <ul>
            @forelse ($staleWebhooks as $event)
                <li><a href="{{ route('admin.queue-errors', ['stripe_reference' => 'STRIPE-'.$event->stripe_event_id]) }}">STRIPE-{{ $event->stripe_event_id }}</a> / {{ $stripeEventTypeLabels[$event->event_type] ?? 'その他のStripe通知' }}（{{ $event->event_type }}） / {{ $event->created_at }}</li>
            @empty
                <li>処理停止Webhookはありません。</li>
            @endforelse
        </ul>
    </section>
</x-layouts.app>
