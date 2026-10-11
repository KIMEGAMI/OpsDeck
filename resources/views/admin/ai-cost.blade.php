<x-layouts.app>
    <section class="card" aria-labelledby="ai-cost-title">
        <h1 id="ai-cost-title">AI原価</h1>
        <p><a href="{{ route('admin.dashboard') }}">運営管理へ戻る</a></p>
        <table>
            <thead><tr><th>Provider</th><th>Model</th><th>利用回数</th><th>推定原価（JPY）</th></tr></thead>
            <tbody>
                @forelse ($usage as $row)
                    <tr><td>{{ $row->provider }}</td><td>{{ $row->model }}</td><td>{{ $row->request_count }}</td><td>{{ number_format((float) $row->cost_jpy, 2) }}</td></tr>
                @empty
                    <tr><td colspan="4">利用記録はありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
