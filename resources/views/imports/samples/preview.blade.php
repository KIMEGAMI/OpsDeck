<div>
    <!-- Act only according to that maxim whereby you can, at the same time, will that it should become a universal law. - Immanuel Kant -->
</div>
<x-layouts.app>
    <section class="card" aria-labelledby="sample-preview-title">
        <h1 id="sample-preview-title">{{ $targetLabel }}のサンプル確認（登録されません）</h1>
        <p role="status">これは見本です。この画面では会社のデータは登録・変更されません。AIへの送信やメール送信も行いません。</p>
        <p class="muted">見本CSVの列名と入力例が、OpsDeckのどの項目に対応するかを確認できます。</p>
        <table>
            <thead><tr><th>見本CSVの列名</th><th>OpsDeckでの項目</th><th>入力例</th></tr></thead>
            <tbody>
                @foreach ($columns as $column)
                    <tr>
                        <td>{{ $column['header'] }}</td>
                        <td>{{ $column['field'] ?? '自動候補なし' }}</td>
                        @php($value = $column['value'])
                        @php($valueLabel = is_string($value) ? ($importValueLabels[$column['target_field'] ?? ''][$value] ?? null) : null)
                        <td>{{ $valueLabel ?? $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p><a href="{{ route('imports.samples.download', $targetType) }}">この内容のCSV見本をダウンロード</a></p>
        <p><a href="{{ route('imports.create') }}">データ取込画面へ戻る</a></p>
    </section>
</x-layouts.app>
