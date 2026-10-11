<x-layouts.app>
    <section class="card">
        <h1>データExport</h1>
        <p class="muted">現在の組織データをCSVで出力します。ViewerはExportできません。</p>
        <p class="muted">表計算ソフトで数式として実行される可能性がある文字列には、先頭にタブを付けて出力します。CSVを他のシステムへ再取込する場合は、値の差分をご確認ください。</p>
        <ul>@foreach (config('opsdeck.export_resources') as $resource)<li><a href="{{ route('exports.download', $resource) }}">{{ $resource }}をExport</a></li>@endforeach</ul>
    </section>
</x-layouts.app>
