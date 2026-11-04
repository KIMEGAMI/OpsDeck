<x-layouts.app>
    <section class="card" aria-labelledby="ai-cost-title">
        <h1 id="ai-cost-title">AI原価</h1>
        <p><a href="{{ route('admin.dashboard') }}">運営管理へ戻る</a></p>
        <p class="muted">USD原価は記録トークン数と設定モデル料金による推定です。JPYはOPSDECK_AI_USD_TO_JPY_RATE設定時のみ換算します。価格が未登録のモデルは原価を0とせず、件数を別表示します。</p>
        <p>無料公開のAI運営上限（1組織・月額）：{{ number_format($aiMonthlyBudgetJpy) }}円相当</p>
        <p class="muted">この金額はOpsDeckが組織ごとに制御するAI原価上限です。OpenAI API側の利用枠・レート制限は別に適用され、契約やプロジェクト設定によって異なります。API側の制限に達した場合は、手動で列を設定してください。</p>
        @if ($aiMonthlyBudgetUsd !== null)
            <p class="muted">現在の運営上限換算：約${{ number_format($aiMonthlyBudgetUsd, 4) }}/月（1 USD = {{ number_format($usdToJpyRate, 2) }}円）。為替レートは設定値で、自動更新ではありません。利用可否の判定はこの換算額とモデル単価を使います。実際の原価は利用トークン数により異なり、円換算額は為替や決済条件で変わることがあります。</p>
        @else
            <p class="muted" role="status">換算レートが未設定です。AI利用を有効にする場合は、環境変数 OPSDECK_AI_USD_TO_JPY_RATE を設定してください。未設定の間、AI列候補は利用できません。</p>
        @endif
        <h2>今月のOpsDeck AI利用（{{ $monthStart->format('Y年n月') }}・アプリのタイムゾーン基準）</h2>
        <p>利用回数：{{ number_format((int) $monthlyUsage->request_count) }}回 ／ 入力：{{ number_format((int) $monthlyUsage->input_tokens) }} tokens ／ 出力：{{ number_format((int) $monthlyUsage->output_tokens) }} tokens</p>
        <p>推定原価：${{ number_format((float) $monthlyUsage->cost_usd, 6) }}（{{ $usdToJpyRateConfigured ? number_format((float) $monthlyUsage->cost_jpy, 2).'円' : '円換算なし' }}）</p>
        @if ((int) $monthlyUsage->unpriced_request_count > 0)
            <p role="status">価格未登録の利用：{{ number_format((int) $monthlyUsage->unpriced_request_count) }}回。原価合計に反映できていない可能性があります。</p>
        @endif
        <p class="muted">この集計はOpsDeck内に記録された当月分のみです。ほかのサービスの利用やOpenAIアカウント残高は含まず、OpenAIの請求額・残高を示すものではありません。会社別の月額上限は各社のタイムゾーンで計算されるため、月初・月末はこの全社集計と期間境界が異なる場合があります。</p>
        <h2>会社別の当月利用</h2>
        <table>
            <thead><tr><th>会社</th><th>利用回数</th><th>入力tokens</th><th>出力tokens</th><th>推定原価（USD）</th><th>推定原価（JPY）</th><th>価格未登録</th></tr></thead>
            <tbody>
                @forelse ($monthlyOrganizationUsage as $row)
                    <tr>
                        <td>{{ $row->organization_name }}（ID: {{ $row->organization_id }}）</td>
                        <td>{{ number_format((int) $row->request_count) }}</td>
                        <td>{{ number_format((int) $row->input_tokens) }}</td>
                        <td>{{ number_format((int) $row->output_tokens) }}</td>
                        <td>${{ number_format((float) $row->cost_usd, 6) }}</td>
                        <td>{{ $usdToJpyRateConfigured ? number_format((float) $row->cost_jpy, 2) : '—' }}</td>
                        <td>{{ number_format((int) $row->unpriced_request_count) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">今月の利用記録はありません。</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $monthlyOrganizationUsage->links() }}
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
