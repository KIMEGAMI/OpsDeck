<x-layouts.app>
    <section class="card">
        <h1>{{ $diffSourceBatch !== null ? '前回ファイルとの違いを確認' : '管理表を取り込む前に確認' }}</h1>
        <x-import-progress :current-step="1" />
        @if ($diffSourceBatch !== null)
            <p class="muted">完了済み「{{ $diffSourceBatch->source_name }}」の実行結果と新しいファイルを比較します。列マッピングを引き継ぎます。削除候補は表示のみで、業務データから自動削除しません。</p>
        @endif
        @if ($targetType !== null)
            <p class="muted">導入チェックリスト：{{ $targetTypeLabel }}の表を事前確認します。確認の次に進んでも、対象は選択済みです。</p>
        @endif
        <p class="muted">まずファイルの内容を確認します。この画面では会社のデータは登録・変更されません。元のExcelも変更しません。</p>
        <p class="muted" role="note">取込履歴・元ファイル・行ごとの確認結果は、あとで見直したり取込を取り消したりできるよう、期限を設けず保存します。取り込む範囲を確認し、業務に不要な個人情報は含めないでください。</p>
        <x-storage-quota :quota="$storageQuota" />
        @unless ($capabilities['xlsx_preview'])
            <p class="muted">FreeでもCSV/TXTから実データを登録できます。社員{{ number_format(config('opsdeck.plans.free.limits.employees')) }}名、資産{{ number_format(config('opsdeck.plans.free.limits.assets')) }}件、SaaSサービス{{ number_format(config('opsdeck.plans.free.limits.saas_services')) }}件まで登録できます。上限を超えた行は登録されず、エラーとして確認できます。PremiumではXLSXとセキュリティ管理項目・証跡・質問票の取込に対応します。Google Sheets連携は1.0の対象外です。
                @if ($canManageBilling)
                    <a href="{{ route('billing.index') }}">Free / Premiumの違いを見る</a>
                @else
                    Premium機能の契約については組織Ownerにご相談ください。
                @endif
            </p>
        @endunless
        @unless ($capabilities['import_execution'])
            <p class="muted">このプランでは内容の確認まで利用できます。会社のデータに登録・反映するには、利用可能なプランが必要です。
                @if ($canManageBilling)
                    <a href="{{ route('billing.index') }}">Free / Premiumの違いを見る</a>
                @else
                    Premium契約については組織Ownerにご相談ください。
                @endif
            </p>
        @endunless
        <p class="muted">1回に確認できるのは最大{{ number_format(config('opsdeck.import.max_rows')) }}行、ファイルは最大{{ number_format(config('opsdeck.import.max_size_kilobytes')) }}KBです。上限を超えるときは、表を分けてください。</p>
        <h2>まずは3つだけ</h2>
        <ol>
            <li>あとで分かる表の名前を付けます。</li>
            <li>元の表を残したまま、取り込むファイルを選びます。</li>
            <li>「内容を確認する」を押します。次の画面で確認するまで、会社のデータは変わりません。</li>
        </ol>
        <p><a href="#import-upload-form">入力欄へ進む</a></p>
        <p class="muted">まだファイルがない場合は、見本を登録せずに操作を試せます。</p>
        <p><a href="{{ route('imports.samples.preview', $samplePreviewTargetType) }}">{{ config('opsdeck.import_target_labels.'.$samplePreviewTargetType, '社員') }}のサンプルを登録せずに確認する</a></p>
        <details>
            <summary>Excel・社内管理表を取り込む詳しい手順とサンプル</summary>
            <ol>
                <li>元のExcelは残したままにします。取り込む表のコピーを用意してください。</li>
                <li>表の名前とファイルを選び、列名とデータの行を指定して「内容を確認する」を押します。この時点では会社のデータは登録・変更されません。</li>
                <li>内容を確認したら「取込内容の詳細」で、どの列を「社員名」「部署」などとして扱うか選びます。</li>
                <li>新規登録・変更の候補を確認し、今回扱わない行のチェックを外します。重複候補は判断し、エラー行は元の表を直して確認し直します。</li>
                @if ($capabilities['import_execution'])
                    <li>最後に取込を実行します。結果はいつでも履歴から確認できます。</li>
                @else
                    <li>
                        このプランではファイルの確認まで利用できます。会社のデータへの反映は別のプランが必要です。
                        @if ($canManageBilling)
                            <a href="{{ route('billing.index') }}">Free / Premiumの違いを確認</a>
                        @else
                            Premium契約の相談は組織Ownerにご依頼ください。
                        @endif
                    </li>
                @endif
            </ol>
            <p class="muted">会社ごとにExcelの列名や意味が異なるため、元ファイルは書き換えません。事前確認と履歴を残し、必要な項目に合わせて取り込みます。</p>
            <h2>サンプルファイル</h2>
            <p class="muted">列の名前や入力例を見るための見本です。実際の会社データは入っていません。まずは「取込なしでプレビュー」を選ぶと、会社のデータは変わりません。見本CSVを実データとして取込実行しないでください。</p>
            <ul>
                @foreach (['employees' => '社員', 'assets' => '資産', 'contracts' => '契約', 'saas_services' => 'SaaS'] as $sampleTargetType => $label)
                    <li>{{ $label }}：<a href="{{ route('imports.samples.preview', $sampleTargetType) }}">取込なしでプレビュー</a> / <a href="{{ route('imports.samples.download', $sampleTargetType) }}">サンプルCSVをダウンロード</a></li>
                @endforeach
            </ul>
        </details>
        <form id="import-upload-form" method="post" action="{{ route('imports.store') }}" enctype="multipart/form-data">
            @csrf
            @if ($diffSourceBatch !== null)<input type="hidden" name="diff_source_batch_id" value="{{ $diffSourceBatch->id }}">@endif
            @if ($targetType !== null)<input type="hidden" name="target_type" value="{{ $targetType }}">@endif
            <label for="source_name">あとで履歴から探すための名前</label>
            <input id="source_name" name="source_name" value="{{ old('source_name', $diffSourceBatch !== null ? $diffSourceBatch->source_name.' 差分' : ($targetTypeLabel !== null ? $targetTypeLabel.'管理表' : '')) }}" required maxlength="255" @error('source_name') aria-invalid="true" aria-describedby="source_name-error" @enderror>
            <x-field-error field="source_name" />
            <p class="muted">例：今使っている社員名簿。後で履歴を見たとき、何の表か分かる名前にしてください。</p>
            @if ($errors->hasAny(['header_row_number', 'header_end_row_number', 'data_start_row_number']))
                <details open>
            @else
                <details>
            @endif
                <summary>表の上にタイトルなどがある場合（通常は設定不要）</summary>
                <p class="muted">列名が1行目にあり、そのすぐ下からデータが始まる表なら、ここは変更しなくて大丈夫です。</p>
                <label for="header_row_number">列の名前が書かれた最初の行</label>
                <input id="header_row_number" type="number" name="header_row_number" value="{{ old('header_row_number', $diffSourceBatch?->header_row_number ?? config('opsdeck.import.default_header_row_number')) }}" min="1" max="{{ config('opsdeck.import.max_header_row_number') }}" required @error('header_row_number') aria-invalid="true" aria-describedby="header_row_number-error" @enderror>
                <x-field-error field="header_row_number" />
                <label for="header_end_row_number">列の名前が書かれた最後の行</label>
                <input id="header_end_row_number" type="number" name="header_end_row_number" value="{{ old('header_end_row_number', old('header_row_number', $diffSourceBatch?->resolvedHeaderEndRowNumber() ?? config('opsdeck.import.default_header_row_number'))) }}" min="{{ (int) old('header_row_number', $diffSourceBatch?->header_row_number ?? config('opsdeck.import.default_header_row_number')) }}" max="{{ config('opsdeck.import.max_header_row_number') }}" required @error('header_end_row_number') aria-invalid="true" aria-describedby="header_end_row_number-error" @enderror>
                <x-field-error field="header_end_row_number" />
                <p class="muted">Excel左端の行番号を入力します。列名が2行に分かれている表だけ、開始行と最後の行を別々にしてください（最大{{ number_format(config('opsdeck.import.max_header_row_number')) }}行）。</p>
                <label for="data_start_row_number">社員名や金額など、データが始まる行（空欄でOK）</label>
                <input id="data_start_row_number" type="number" name="data_start_row_number" value="{{ old('data_start_row_number', $diffSourceBatch?->data_start_row_number) }}" min="{{ (int) old('header_end_row_number', $diffSourceBatch?->resolvedHeaderEndRowNumber() ?? config('opsdeck.import.default_header_row_number')) + 1 }}" max="{{ min(config('opsdeck.import.max_source_row_number'), config('opsdeck.import.xlsx_max_rows')) }}" @error('data_start_row_number') aria-invalid="true" aria-describedby="data_start_row_number-error" @enderror>
                <x-field-error field="data_start_row_number" />
                <p class="muted">表の上にタイトルや説明がある場合だけ、最初のデータ行の番号を入力してください。</p>
            </details>
            <label for="file">{{ $capabilities['xlsx_preview'] ? 'CSVファイル / Excelファイル（XLSX）' : 'CSVファイル' }}</label>
            @if ($capabilities['xlsx_preview'])
                <p class="muted">使える形式：CSV / TXT / Excel（.xlsx）。古い形式のExcel（.xls）は使えません。Excelに複数のシートがある場合は、次の画面で取り込むシートを選べます。</p>
            @else
                <p class="muted">このプランで選べる形式はCSV / TXTです。Excel（.xlsx）を直接選ぶにはPremiumが必要です。古い形式のExcel（.xls）も使えません。元のExcelを残し、コピーした表をCSV UTF-8で保存してください。</p>
            @endif
            <input id="file" type="file" name="file" accept="{{ $capabilities['xlsx_preview'] ? '.csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : '.csv,.txt,text/csv,text/plain' }}" required @error('file') aria-invalid="true" aria-describedby="file-help file-error" @else aria-describedby="file-help" @enderror>
            <x-field-error field="file" />
            <p id="file-help" class="muted">ファイル送信でエラーが出た場合は、ファイルをもう一度選んでください。</p>
            <p class="muted">文字化けや列ずれがないか、次の確認画面で必ず確認してください。</p>
            @if (! $capabilities['xlsx_preview'])
                <details>
                    <summary>Excelファイルを使う場合</summary>
                    <p class="muted">元のExcelは残し、コピーを開いて、取り込むシートをCSV形式で保存してください。「CSV UTF-8」が選べる場合はそれを選びます。保存したCSVをここで選び、次の画面で氏名・日付・金額などが正しく表示されるか確認してください。</p>
                </details>
            @endif
            @if ($capabilities['xlsx_preview'])
                <p class="muted">Excelファイル（XLSX）の日付は「年-月-日」の形に整えます。「¥1,000」のような金額は、記号を外した数値として扱います。次の画面で日付と金額を確認してから取り込みます。</p>
            @endif
            <button type="submit">内容を確認する（まだ登録しません）</button>
        </form>
        <script>
            const headerStartInput = document.getElementById('header_row_number');
            const headerEndInput = document.getElementById('header_end_row_number');
            const dataStartInput = document.getElementById('data_start_row_number');
            let previousHeaderStart = Number(headerStartInput.value);
            const updateImportRowBounds = () => {
                const headerStart = Number(headerStartInput.value || 1);
                if (Number(headerEndInput.value) < headerStart || Number(headerEndInput.value) === previousHeaderStart) {
                    headerEndInput.value = String(headerStart);
                }
                headerEndInput.min = String(headerStart);
                dataStartInput.min = String(Number(headerEndInput.value || headerStart) + 1);
                previousHeaderStart = headerStart;
            };
            headerStartInput.addEventListener('input', updateImportRowBounds);
            headerEndInput.addEventListener('input', updateImportRowBounds);
        </script>
    </section>
</x-layouts.app>
