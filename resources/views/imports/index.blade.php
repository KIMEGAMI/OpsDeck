<x-layouts.app>
    <section class="card">
        <h1>データの取込履歴</h1>
        @can('create', [App\Models\ImportBatch::class, $organization])
            <p>
                <a href="{{ route('imports.create') }}">管理表を取り込む前に確認する</a>
                @if ($capabilities['google_sheets']) | <a href="{{ route('imports.google-sheets') }}">Googleスプレッドシートから取り込む</a>@endif
                | <a href="{{ route('help.recovery', 'import') }}">取り込みに困ったとき</a>
            </p>
        @endcan
        <form method="get"><label for="status">状態で絞り込む</label><select id="status" name="status"><option value="">すべて</option>@foreach (config('opsdeck.import_batch_statuses') as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $importBatchStatusLabels[$status] ?? '状態を確認してください' }}</option>@endforeach</select> <label><input type="checkbox" name="has_errors" value="1" @checked((bool) ($filters['has_errors'] ?? false))> エラー行がある取込</label> <label><input type="checkbox" name="unreviewed" value="1" @checked((bool) ($filters['unreviewed'] ?? false))> 未確認のみ</label> @if (! empty($filters['created_after']))<input type="hidden" name="created_after" value="{{ $filters['created_after'] }}"><span class="muted">{{ $filters['created_after'] }}以降</span>@endif @if (! empty($filters['created_before']))<input type="hidden" name="created_before" value="{{ $filters['created_before'] }}"><span class="muted">{{ $filters['created_before'] }}まで</span>@endif <button type="submit">絞り込む</button></form>
        <table><thead><tr><th>取込名</th><th>ファイル形式</th><th>状態</th><th>行数</th><th>作成日時</th></tr></thead><tbody>
        @forelse ($importBatches as $batch)
            <tr><td><a href="{{ route('imports.show', $batch) }}">{{ $batch->source_name }}</a></td><td>{{ $importSourceTypeLabels[$batch->source_type] ?? 'ファイル' }}</td><td>{{ $importBatchStatusLabels[$batch->status] ?? '状態を確認してください' }}</td><td>{{ $batch->total_rows }}</td><td>{{ $batch->created_at->format('Y-m-d H:i') }}</td></tr>
        @empty
            <tr><td colspan="5">プレビュー履歴はありません。</td></tr>
        @endforelse
        </tbody></table>
        {{ $importBatches->links() }}
    </section>
</x-layouts.app>
