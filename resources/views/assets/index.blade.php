<x-layouts.app>
    <section class="card" aria-labelledby="assets-title">
        <h1 id="assets-title">端末</h1>
        <form method="get" action="{{ route('assets.index') }}">
            <label for="search">資産番号・メーカー・型番</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}">
            <label for="statuses">状態（複数選択可）</label>
            <select id="statuses" name="statuses[]" multiple>
                @foreach (config('opsdeck.asset_statuses', []) as $status)
                    <option value="{{ $status }}" @selected(in_array($status, $filters['statuses'] ?? [], true))>{{ $status }}</option>
                @endforeach
            </select>
            <label><input type="checkbox" name="unassigned" value="1" @checked($filters['unassigned'] ?? false)> 未返却の割当がない端末のみ</label>
            <label><input type="checkbox" name="needs_attention" value="1" @checked($filters['needs_attention'] ?? false)> 未割当または退職者に紐づく端末のみ</label>
            <button type="submit">絞り込む</button>
        </form>
        @can('manageOperationalData', $organization)<p><a href="{{ route('assets.create') }}">端末を登録する</a></p>@endcan
        <table><thead><tr><th>資産番号</th><th>種別</th><th>メーカー / 型番</th><th>状態</th></tr></thead><tbody>
            @forelse ($assets as $asset)<tr><td><a href="{{ route('assets.show', $asset) }}">{{ $asset->asset_number }}</a></td><td>{{ $asset->asset_type }}</td><td>{{ trim(($asset->manufacturer ?? '').' '.($asset->model ?? '')) ?: '未設定' }}</td><td>{{ $asset->status }}</td></tr>
            @empty<tr><td colspan="4">端末は未登録です。</td></tr>@endforelse
        </tbody></table>
        {{ $assets->links() }}
    </section>
</x-layouts.app>
