<x-layouts.app>
    <section class="card">
        <h1>Migration Studio: {{ $capabilities['xlsx_preview'] ? 'CSV / XLSXプレビュー' : 'CSVプレビュー' }}</h1>
        @if ($targetType !== null)
            <p class="muted">導入チェックリスト：{{ $targetTypeLabel }}の管理表をプレビューします。Preview後の列マッピング画面でも対象種別が選択済みになります。</p>
        @endif
        <p class="muted">この操作ではDBの業務データを変更せず、CSVの行数とプレビュー履歴だけを保存します。</p>
        <p class="muted">1回の取込は最大{{ number_format(config('opsdeck.import.max_rows')) }}行、ファイルサイズは最大{{ number_format(config('opsdeck.import.max_size_kilobytes')) }}KBです。超える場合はデータを分割して取り込んでください。</p>
        <form method="post" action="{{ route('imports.store') }}" enctype="multipart/form-data">
            @csrf
            @if ($targetType !== null)<input type="hidden" name="target_type" value="{{ $targetType }}">@endif
            <label for="source_name">取込名</label>
            <input id="source_name" name="source_name" value="{{ old('source_name', $targetTypeLabel !== null ? $targetTypeLabel.'管理表' : '') }}" required maxlength="255">
            <label for="file">{{ $capabilities['xlsx_preview'] ? 'CSV / XLSXファイル' : 'CSVファイル' }}</label>
            <input id="file" type="file" name="file" accept="{{ $capabilities['xlsx_preview'] ? '.csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : '.csv,.txt,text/csv,text/plain' }}" required>
            <p class="muted">CSVはUTF-8（BOM付き可）またはShift_JIS（Windows-31J）に対応しています。文字化けや列ずれを避けるため、Previewで内容を必ず確認してください。</p>
            @if ($capabilities['xlsx_preview'])
                <p class="muted">XLSXはセルの表示書式を見て日付をYYYY-MM-DDに変換します。通貨表示の金額は表示記号を外した数値で扱います。Previewで日付と金額を確認してから実行してください。</p>
            @endif
            <button type="submit">プレビューを作成</button>
        </form>
        <h2>サンプルCSV</h2>
        <p class="muted">列名と値の形式を確認するためのサンプルです。実データは含まれていません。サンプル行をImportすると組織の業務データとして登録されるため、実データの代わりに取り込まないでください。</p>
        <h2>Excel・社内管理表を取り込む手順</h2>
        <ol>
            <li>元のExcelは変更せず、取り込むシートを{{ $capabilities['xlsx_preview'] ? 'CSVまたはXLSX' : 'CSV' }}としてコピーします。</li>
            <li>取込名とファイルを選び、「プレビューを作成」を押します。ここでは業務データに登録・更新されません。</li>
            <li>Previewの準備後、Import詳細画面の「列マッピングを設定する」で対象データ種別と列の対応を設定します。</li>
            <li>マッピング分析後、新規・更新・確認待ち・エラーを確認し、必要な行だけを選択します。</li>
            @if ($capabilities['import_execution'])
                <li>内容を確認したらImportを実行します。結果は履歴から確認できます。</li>
            @else
                <li>
                    FreeプランではCSVプレビューまで利用できます。業務データへの反映はPremiumプランで利用できます。
                    @if ($canManageBilling)
                        <a href="{{ route('billing.index') }}">Free / Premiumの違いを確認</a>
                    @else
                        Premium契約の相談は組織Ownerにご依頼ください。
                    @endif
                </li>
            @endif
        </ol>
        <p class="muted">会社ごとにExcelの列名や意味が異なるため、最初から元ファイルを直接書き換えません。Previewと履歴を残して段階的に正規化します。</p>
        <ul>
            @foreach (['employees' => '社員', 'assets' => '資産', 'contracts' => '契約', 'saas_services' => 'SaaS'] as $targetType => $label)
                <li><a href="{{ route('imports.samples.download', $targetType) }}">{{ $label }}サンプルCSVをダウンロード</a> / <a href="{{ route('imports.samples.preview', $targetType) }}">取込なしでプレビュー</a></li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
