<x-layouts.app>
    <section class="card" aria-labelledby="ai-cost-title">
        <h1 id="ai-cost-title">AI原価</h1>
        <p><a href="{{ route('admin.dashboard') }}">運営管理へ戻る</a></p>
        <p class="muted">USD原価は記録トークン数と設定モデル料金による推定です。JPYはOPSDECK_AI_USD_TO_JPY_RATE設定時のみ換算します。価格が未登録のモデルは原価を0とせず、件数を別表示します。</p>
        <table>
            <thead><tr><th>Provider</th><th>Model</th><th>利用回数</th><th>入力tokens</th><th>出力tokens</th><th>推定原価（USD）</th><th>推定原価（JPY）</th><th>価格未登録</th></tr></thead>
            <tbody>
                @forelse ($usage as $row)
                    <tr>
                        <td>{{ $row->provider }}</td><td>{{ $row->model }}</td><td>{{ $row->request_count }}</td>
                        <td>{{ number_format((int) $row->input_tokens) }}</td><td>{{ number_format((int) $row->output_tokens) }}</td>
                        <td>${{ number_format((float) $row->cost_usd, 6) }}</td>
                        <td>{{ $usdToJpyRateConfigured ? number_format((float) $row->cost_jpy, 2) : '—' }}</td>
                        <td>{{ (int) $row->unpriced_request_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">利用記録はありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
