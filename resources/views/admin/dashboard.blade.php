<x-layouts.app>
    <section class="card" aria-labelledby="admin-title">
        <h1 id="admin-title">OpsDeck運営管理</h1>
        <p class="muted">集計値のみを表示し、顧客のEvidence本文は表示しません。</p>
        <p><a href="{{ route('admin.announcements.index') }}">お知らせ管理</a></p>
        <p><a href="{{ route('admin.support.index') }}">お問い合わせ管理</a> / <a href="{{ route('admin.product-metrics') }}">製品利用状況</a></p>
        <p><a href="{{ route('admin.ai-cost') }}">AI原価詳細</a> / <a href="{{ route('admin.queue-errors') }}">Queue・Webhookエラー詳細</a></p>
        <p><a href="{{ route('admin.health') }}">運用ヘルス</a></p>
        <dl>
            <dt>契約社数</dt><dd>{{ $metrics['organizations'] }}</dd>
            <dt>Active / Trialing組織</dt><dd>{{ $metrics['activeOrganizations'] }}</dd>
            <dt>推定MRR（JPY）</dt><dd>{{ number_format($metrics['mrrJpy']) }}円</dd>
            <dt>社員レコード数</dt><dd>{{ $metrics['employees'] }}</dd>
            <dt>資産数</dt><dd>{{ $metrics['assets'] }}</dd>
            <dt>契約数</dt><dd>{{ $metrics['contracts'] }}</dd>
            <dt>AI利用回数</dt><dd>{{ $metrics['aiRequests'] }}</dd>
            <dt>AI推定原価（USD）</dt><dd>${{ number_format((float) $metrics['aiCostUsd'], 6) }}</dd>
            <dt>AI推定原価（JPY）</dt><dd>{{ $metrics['aiUsdToJpyRateConfigured'] ? number_format((float) $metrics['aiCostJpy'], 2).'円' : '為替レート未設定' }}</dd>
            <dt>価格未登録モデルの利用回数</dt><dd>{{ $metrics['aiPricingUnavailableRequests'] }}</dd>
            <dt>Import件数</dt><dd>{{ $metrics['imports'] }}</dd>
            <dt>Import処理失敗率（完了・失敗した処理）</dt><dd>{{ $metrics['importFailureRate'] }}%</dd>
            <dt>Google Sheets接続数</dt><dd>{{ $metrics['googleSheetsConnections'] }}</dd>
            <dt>Webhookエラー</dt><dd>{{ $metrics['webhookErrors'] }}</dd>
            <dt>Queueエラー</dt><dd>{{ $metrics['queueErrors'] ?? '未導入' }}</dd>
        </dl>
    </section>
</x-layouts.app>
