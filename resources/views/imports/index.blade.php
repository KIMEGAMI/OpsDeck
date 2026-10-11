<x-layouts.app>
    <section class="card">
        <h1>Migration Studio</h1>
        @can('create', [App\Models\ImportBatch::class, $organization])
            <p>
                <a href="{{ route('imports.create') }}">{{ $capabilities['xlsx_preview'] ? 'CSV / XLSXプレビューを作成する' : 'CSVプレビューを作成する' }}</a>
                @if ($capabilities['google_sheets']) | <a href="{{ route('imports.google-sheets') }}">Google Sheetsから取り込む</a>@endif
                | <a href="{{ route('help.recovery', 'import') }}">Import復旧ガイド</a>
            </p>
        @endcan
        <form method="get"><label for="status">状態で絞り込む</label><select id="status" name="status"><option value="">すべて</option>@foreach (config('opsdeck.import_batch_statuses') as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $importBatchStatusLabels[$status] ?? $status }}</option>@endforeach</select> <label><input type="checkbox" name="has_errors" value="1" @checked((bool) ($filters['has_errors'] ?? false))> エラー行があるバッチ</label> <label><input type="checkbox" name="unreviewed" value="1" @checked((bool) ($filters['unreviewed'] ?? false))> 未確認のみ</label> @if (! empty($filters['created_after']))<input type="hidden" name="created_after" value="{{ $filters['created_after'] }}"><span class="muted">{{ $filters['created_after'] }}以降</span>@endif @if (! empty($filters['created_before']))<input type="hidden" name="created_before" value="{{ $filters['created_before'] }}"><span class="muted">{{ $filters['created_before'] }}まで</span>@endif <button type="submit">絞り込む</button></form>
        <table><thead><tr><th>取込名</th><th>種別</th><th>状態</th><th>行数</th><th>作成日時</th></tr></thead><tbody>
        @forelse ($importBatches as $batch)
            <tr><td><a href="{{ route('imports.show', $batch) }}">{{ $batch->source_name }}</a></td><td>{{ $importSourceTypeLabels[$batch->source_type] ?? $batch->source_type }}</td><td>{{ $importBatchStatusLabels[$batch->status] ?? $batch->status }}</td><td>{{ $batch->total_rows }}</td><td>{{ $batch->created_at->format('Y-m-d H:i') }}</td></tr>
        @empty
            <tr><td colspan="5">プレビュー履歴はありません。</td></tr>
        @endforelse
        </tbody></table>
        {{ $importBatches->links() }}
    </section>
</x-layouts.app>
