<div>
    <!-- Act only according to that maxim whereby you can, at the same time, will that it should become a universal law. - Immanuel Kant -->
</div>
<x-layouts.app>
    <section class="card" aria-labelledby="sample-preview-title">
        <h1 id="sample-preview-title">{{ $targetLabel }}サンプルプレビュー（取込なし）</h1>
        <p role="status">これは列と自動マッピング候補の確認用です。業務データの保存、AI処理、メール送信は行いません。</p>
        <p class="muted">CSVヘッダーとサンプル値が、Import時にどのOpsDeck項目へ対応するかを表示しています。</p>
        <table>
            <thead><tr><th>CSVの列</th><th>OpsDeck項目</th><th>サンプル値</th></tr></thead>
            <tbody>
                @foreach ($columns as $column)
                    <tr>
                        <td>{{ $column['header'] }}</td>
                        <td>{{ $column['field'] ?? '自動候補なし' }}</td>
                        <td>{{ $column['value'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p><a href="{{ route('imports.samples.download', $targetType) }}">このサンプルCSVをダウンロード</a></p>
        <p><a href="{{ route('imports.create') }}">Import画面へ戻る</a></p>
    </section>
</x-layouts.app>
