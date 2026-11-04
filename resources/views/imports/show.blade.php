<x-layouts.app>
    <section class="card">
        <h1>{{ $importBatch->source_name }}</h1>
        <x-import-progress
            :current-step="$importBatch->status === 'completed' ? 4 : ($mapping !== null ? 3 : 2)"
            :completed="$importBatch->status === 'completed'"
        />
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p>取込の状態: {{ $importBatchStatusLabels[$importBatch->status] ?? '状態を確認してください' }} / ファイル形式: {{ $importSourceTypeLabels[$importBatch->source_type] ?? 'ファイル' }} / データ件数: {{ $importBatch->total_rows }}行</p>
        @if ($importBatch->selected_sheet_name !== null)<p class="muted">対象シート: {{ $importBatch->selected_sheet_name }}</p>@endif
        @if (in_array($importBatch->source_type, ['csv', 'xlsx', 'google_sheets'], true))<p class="muted">見出し行: {{ $importBatch->header_row_number }}〜{{ $importBatch->resolvedHeaderEndRowNumber() }} / データ開始行: {{ $importBatch->resolvedDataStartRowNumber() }}</p>@endif
        @if ($mapping?->target_type === 'asset_assignments')
            <section aria-labelledby="asset-assignment-import-order-title">
                <h2 id="asset-assignment-import-order-title">端末の割当を取り込む順番</h2>
                <p class="muted">社員と端末・資産がまだ登録されていない場合は、先にそれぞれの一覧を取り込んでください。すでに登録済みなら、この割当の取込を続けられます。</p>
                <ol>
                    <li><a href="{{ route('imports.create', ['target_type' => 'employees']) }}">社員台帳を先に確認・取り込む</a></li>
                    <li><a href="{{ route('imports.create', ['target_type' => 'assets']) }}">端末・資産台帳を確認・取り込む</a></li>
                    <li>この画面で割当内容を確認してから取り込む</li>
                </ol>
                <p class="muted">割当表の社員メールアドレスと資産番号は、登録済みの社員・端末の情報と一致している必要があります。一致しない行は下の一覧で確認してください。</p>
            </section>
        @elseif ($mapping?->target_type === 'saas_assignments')
            <section aria-labelledby="saas-assignment-import-order-title">
                <h2 id="saas-assignment-import-order-title">クラウドサービスの利用者を登録する順番</h2>
                <p class="muted">社員とクラウドサービスがまだ登録されていない場合は、先にそれぞれの一覧を取り込んでください。すでに登録済みなら、この割当の取込を続けられます。</p>
                <ol>
                    <li><a href="{{ route('imports.create', ['target_type' => 'employees']) }}">社員台帳を先に確認・取り込む</a></li>
                    <li><a href="{{ route('imports.create', ['target_type' => 'saas_services']) }}">クラウドサービスの一覧を確認・取り込む</a></li>
                    <li>この画面で割当内容を確認してから取り込む</li>
                </ol>
                <p class="muted">割当表の社員メールアドレスとサービス名は、登録済みの情報と一致している必要があります。一致しない行は下の一覧で確認してください。</p>
            </section>
        @endif
        @if ($importBatch->status === 'queued')
            <p role="status">取込を受け付けました。処理が始まるまで順番待ちです。画面を更新して状態を確認してください。状態が変わらない場合は管理者にお問い合わせください。重ねて実行しないでください。</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @elseif ($importBatch->status === 'previewing')
            <p role="status">{{ $importBatch->diff_source_batch_id !== null ? '前回の取込内容と今回のファイルの違いを調べています。この画面を更新して状態を確認してください。' : 'ファイルを確認しています。この画面を更新して状態を確認してください。' }}</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @elseif ($importBatch->status === 'analyzing')
            <p role="status">列の対応を設定した内容を調べています。この間は取込を実行できません。画面を更新して状態を確認してください。</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @elseif ($importBatch->status === 'executing')
            <p role="status">取込を実行中です。画面を更新すると状態を確認できます。完了するまで、もう一度実行しないでください。</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @elseif ($importBatch->status === 'retrying')
            <p role="status">取込を再試行する準備をしています。この画面を更新すると状態を確認できます。</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @elseif ($importBatch->status === 'rolling_back')
            <p role="status">取込の取消処理中です。この画面を更新すると状態を確認できます。</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @endif
        @if ($isImportBatchStale)
            <p class="errors" role="alert">設定時間（{{ $staleAfterMinutes }}分）を超えて状態が変わっていません。何度も実行・取消ボタンを押さず、状態を更新してください。続く場合は参照番号 IMP-{{ $importBatch->id }} を伝えて管理者に連絡してください。</p>
            <p><a href="{{ route('support.create', ['category' => 'import', 'error_reference' => 'IMP-'.$importBatch->id]) }}">この取込について問い合わせる（IMP-{{ $importBatch->id }}）</a></p>
        @endif
        @if ($importBatch->status === 'sheet_selection' && (($importBatch->source_type === 'xlsx' && $capabilities['xlsx_preview']) || ($importBatch->source_type === 'google_sheets' && $capabilities['google_sheets'])))
            @if ($canManageImport)
                <form method="post" action="{{ $importBatch->source_type === 'google_sheets' ? route('imports.google-sheet.store', $importBatch) : route('imports.sheet.store', $importBatch) }}"><input type="hidden" name="_token" value="{{ csrf_token() }}"><label for="sheet_name">シート</label><select id="sheet_name" name="sheet_name" required>@foreach (($importBatch->available_sheets_json ?? []) as $sheet)<option value="{{ $sheet }}">{{ $sheet }}</option>@endforeach</select>
                    @if ($importBatch->source_type === 'google_sheets')
                        <label for="google_header_row_number">見出し範囲の開始行</label>
                        <input id="google_header_row_number" type="number" name="header_row_number" value="{{ old('header_row_number', config('opsdeck.import.default_header_row_number')) }}" min="1" max="{{ config('opsdeck.import.max_header_row_number') }}" required>
                        <label for="google_header_end_row_number">見出し範囲の終了行</label>
                        <input id="google_header_end_row_number" type="number" name="header_end_row_number" value="{{ old('header_end_row_number', old('header_row_number', config('opsdeck.import.default_header_row_number'))) }}" min="{{ (int) old('header_row_number', config('opsdeck.import.default_header_row_number')) }}" max="{{ config('opsdeck.import.max_header_row_number') }}" required>
                        <label for="google_data_start_row_number">データ開始行（任意）</label>
                        <input id="google_data_start_row_number" type="number" name="data_start_row_number" value="{{ old('data_start_row_number') }}" min="{{ (int) old('header_end_row_number', old('header_row_number', config('opsdeck.import.default_header_row_number'))) + 1 }}" max="{{ config('opsdeck.import.max_source_row_number') }}">
                    @endif
                    <button type="submit">シートを選択</button></form>
            @else
                <p class="muted">シートの選択は、会社のデータを管理する担当者に依頼してください。</p>
            @endif
        @elseif ($importBatch->status === 'sheet_selection')
            <p class="muted">この形式の取込は現在利用できません。</p>
        @endif
        @if ($workbookImports->count() > 1)
            <section aria-labelledby="workbook-imports-title">
                <h2 id="workbook-imports-title">同じExcelファイルから取り込んだシート</h2>
                <p class="muted">シートごとに内容確認・列の対応・承認を行います。別の一覧や社員・資産の関係は自動で結びつきません。</p>
                <ul>
                    @foreach ($workbookImports as $workbookImport)
                        <li>
                            @if ($workbookImport->id === $importBatch->id)
                                {{ $workbookImport->selected_sheet_name }}（表示中）
                            @else
                                <a href="{{ route('imports.show', $workbookImport) }}">{{ $workbookImport->selected_sheet_name }}</a>
                            @endif
                            — {{ $importBatchStatusLabels[$workbookImport->status] ?? '状態を確認してください' }}
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
        @if ($availableAdditionalSheets !== [] && $capabilities['xlsx_preview'])
            @can('create', [App\Models\ImportBatch::class, $organization])
                <details>
                    <summary>このXLSXの別シートを取り込む</summary>
                    <p class="muted">別シートは別々に内容確認・列の対応・承認を行います。別の一覧や社員・資産の関係は自動で結びつきません。</p>
                    <form method="post" action="{{ route('imports.sheet.additional', $importBatch) }}">
                        @csrf
                        <label for="additional_sheet_name">未使用のシート</label>
                        <select id="additional_sheet_name" name="sheet_name" required>
                            @foreach ($availableAdditionalSheets as $sheet)<option value="{{ $sheet }}">{{ $sheet }}</option>@endforeach
                        </select>
                        <label for="additional_header_row_number">見出し範囲の開始行</label>
                        <input id="additional_header_row_number" type="number" name="header_row_number" value="{{ old('header_row_number', config('opsdeck.import.default_header_row_number')) }}" min="1" max="{{ config('opsdeck.import.max_header_row_number') }}" required>
                        <label for="additional_header_end_row_number">見出し範囲の終了行</label>
                        <input id="additional_header_end_row_number" type="number" name="header_end_row_number" value="{{ old('header_end_row_number', old('header_row_number', config('opsdeck.import.default_header_row_number'))) }}" min="{{ (int) old('header_row_number', config('opsdeck.import.default_header_row_number')) }}" max="{{ config('opsdeck.import.max_header_row_number') }}" required>
                        <label for="additional_data_start_row_number">データ開始行（任意）</label>
                        <input id="additional_data_start_row_number" type="number" name="data_start_row_number" value="{{ old('data_start_row_number') }}" min="{{ (int) old('header_end_row_number', old('header_row_number', config('opsdeck.import.default_header_row_number'))) + 1 }}" max="{{ min(config('opsdeck.import.max_source_row_number'), config('opsdeck.import.xlsx_max_rows')) }}">
                        <button type="submit">別シートの内容を確認する</button>
                    </form>
                    <script>
                        const additionalHeaderStart = document.getElementById('additional_header_row_number');
                        const additionalHeaderEnd = document.getElementById('additional_header_end_row_number');
                        const additionalDataStart = document.getElementById('additional_data_start_row_number');
                        let previousAdditionalHeaderStart = Number(additionalHeaderStart.value);
                        const updateAdditionalImportRowBounds = () => {
                            const headerStart = Number(additionalHeaderStart.value || 1);
                            if (Number(additionalHeaderEnd.value) < headerStart || Number(additionalHeaderEnd.value) === previousAdditionalHeaderStart) {
                                additionalHeaderEnd.value = String(headerStart);
                            }
                            additionalHeaderEnd.min = String(headerStart);
                            additionalDataStart.min = String(Number(additionalHeaderEnd.value || headerStart) + 1);
                            previousAdditionalHeaderStart = headerStart;
                        };
                        additionalHeaderStart.addEventListener('input', updateAdditionalImportRowBounds);
                        additionalHeaderEnd.addEventListener('input', updateAdditionalImportRowBounds);
                    </script>
                </details>
            @endcan
        @endif
        <dl><dt>新規登録</dt><dd>{{ $importBatch->inserted_rows }}</dd><dt>更新</dt><dd>{{ $importBatch->updated_rows }}</dd><dt>スキップ</dt><dd>{{ $importBatch->skipped_rows }}</dd><dt>エラー</dt><dd>{{ $importBatch->error_rows }}</dd></dl>
        @if ($importBatch->status === 'completed' && $importBatch->error_rows > 0)
            <p class="errors" role="alert">取込処理は完了しましたが、エラーが{{ number_format($importBatch->error_rows) }}行あります。エラー行は登録・変更されていません。</p>
            <p class="muted">下の「確認事項」を見て元の表を修正し、新しい取込として内容を確認してください。すでに取り込まれた行が含まれる場合は「変更候補」になります。内容を確認してから実行してください。</p>
            @if ($hasErrorRows)
                <p><a href="{{ route('imports.errors.download', $importBatch) }}">エラー行一覧をCSVでダウンロード</a></p>
                <p class="muted">元行番号・取込対象・確認事項だけを出力します。氏名・メールアドレスなどの業務データは含みません。</p>
            @endif
            <p><a href="{{ route('help.recovery', 'import') }}">取込エラーの対処方法を見る</a></p>
            <p><a href="{{ route('imports.create', ['target_type' => $mapping?->target_type ?? $targetTypeSuggestion]) }}">修正したファイルの内容を確認する</a></p>
        @endif
        @if ($hasErrorRows && ! ($importBatch->status === 'completed' && $importBatch->error_rows > 0))
            <p><a href="{{ route('imports.errors.download', $importBatch) }}">エラー行一覧をCSVでダウンロード</a></p>
            <p class="muted">元行番号・取込対象・確認事項だけを出力します。氏名・メールアドレスなどの業務データは含みません。</p>
        @endif
        @if ($importBatch->source_file_sha256 !== null)
            <dl><dt>元ファイル SHA-256</dt><dd><code>{{ $importBatch->source_file_sha256 }}</code></dd></dl>
        @endif
        @if ($importBatch->status === 'failed' || (in_array($importBatch->status, ['completed', 'cancelled'], true) && $importBatch->error_rows > 0))
            @if ($importBatch->errors_reviewed_at === null)
                @can('update', $importBatch)
                    <p class="muted">「確認済み」は確認した記録です。エラーや会社のデータが修正されるわけではありません。</p>
                    <form method="post" action="{{ route('imports.review-errors', $importBatch) }}">
                        @csrf
                        <label><input type="checkbox" name="confirm" value="1" required> エラーまたは実行失敗を確認し、対処方針を決めました</label>
                        <button type="submit">確認済みにする</button>
                    </form>
                @endcan
            @else
                <p role="status">このバッチのエラーは確認済みとして記録されています（{{ $importBatch->errors_reviewed_at->timezone($organization->timezone)->format('Y-m-d H:i') }}）。エラーや会社のデータは修正されていません。</p>
            @endif
        @endif
        @if ($importBatch->status === 'preview' || $importBatch->status === 'ready')
            <h2>取り込み前の確認</h2>
            <p class="muted">下の件数は見込みです。ここではまだ会社の情報は変わりません。</p>
            <dl><dt>新しく登録される行</dt><dd>{{ $importBatch->preview_new_rows }}行</dd><dt>変更される行</dt><dd>{{ $importBatch->preview_updated_rows }}行</dd><dt>人の確認が必要な行</dt><dd>{{ $importBatch->preview_confirmation_rows }}行</dd><dt>エラーがある行</dt><dd>{{ $importBatch->preview_error_rows }}行</dd></dl>
            <p class="muted">この件数は取込前の見込みです。新規登録・変更の候補でも、チェックを外した行は今回の対象外になります。</p>
            <p class="muted">エラー行はチェックを付けても登録・変更されません。元のファイルを直して、もう一度内容を確認してください。</p>
            <p class="muted">重複候補は下の一覧で判断してください。未判断または保留中の候補が残っている間は、取込を実行できません。</p>
            @can('update', $importBatch)
                <form method="post" action="{{ route('imports.rows.selection', ['importBatch' => $importBatch, 'row_results_page' => $rowResults->currentPage()]) }}">
                    @csrf
                    <p>今回の処理対象にする行にチェックを付けて、このページの選択を保存してください。チェックを外した行は今回の対象外として残ります。別ページで選んだ行はそのままです。</p>
                    @php($bulkSelectableRowResults = $selectableRowResults->where('action', '!=', 'error'))
                    @if ($bulkSelectableRowResults->isNotEmpty())
                        <label><input type="checkbox" data-select-visible-import-rows> このページのエラー以外の行をすべて選択</label>
                        <p class="muted">一括選択・解除は表示中ページのエラー以外の行に反映され、エラー行のチェック状態は変わりません。</p>
                    @else
                        <p class="muted">このページには一括選択できるエラー以外の行がありません。</p>
                    @endif
                    @foreach ($selectableRowResults as $result)
                        <input type="hidden" name="visible_row_numbers[]" value="{{ $result->source_row_number }}">
                    <label><input type="checkbox" name="selected_row_numbers[]" value="{{ $result->source_row_number }}" data-selectable-import-row="{{ $result->action === 'error' ? 'false' : 'true' }}" @checked($result->is_selected)> 行 {{ $result->source_row_number }}（{{ $importActionLabels[$result->action] ?? '処理内容を確認してください' }}）</label>
                    @endforeach
                    <button type="submit">このページの選択を保存</button>
                </form>
                @if ($bulkSelectableRowResults->isNotEmpty())
                    <script>
                        const selectVisibleImportRows = document.querySelector('[data-select-visible-import-rows]');
                        const selectableImportRows = [...document.querySelectorAll('[data-selectable-import-row="true"]')];
                        const updateSelectVisibleImportRows = () => {
                            const selectedRows = selectableImportRows.filter((row) => row.checked).length;
                            selectVisibleImportRows.checked = selectedRows === selectableImportRows.length;
                            selectVisibleImportRows.indeterminate = selectedRows > 0 && selectedRows < selectableImportRows.length;
                        };
                        selectVisibleImportRows.addEventListener('change', () => {
                            selectableImportRows.forEach((row) => { row.checked = selectVisibleImportRows.checked; });
                            updateSelectVisibleImportRows();
                        });
                        selectableImportRows.forEach((row) => row.addEventListener('change', updateSelectVisibleImportRows));
                        updateSelectVisibleImportRows();
                    </script>
                @endif
            @endcan
        @endif
        @if ($importBatch->status === 'preview' || $importBatch->status === 'ready')
            <p class="muted">まだ会社の情報は変更されていません。下の内容と選択行を確認してから実行してください。</p>
        @endif
        @if ($importBatch->diff_source_batch_id !== null && in_array($importBatch->status, ['preview', 'ready'], true))
            <p class="muted">この比較画面では前回の取込時に使った列の対応を引き継いでいます。列の対応を変える場合は、新しくファイルの内容確認からやり直してください。</p>
        @elseif (in_array($importBatch->status, ['preview', 'ready'], true))
            @can('update', $importBatch)<p><a href="{{ route('imports.mapping', $targetTypeSuggestion === null ? $importBatch : [$importBatch, 'target_type' => $targetTypeSuggestion]) }}">{{ $targetTypeSuggestion === null ? '列マッピングを設定する' : config('opsdeck.import_target_labels.'.$targetTypeSuggestion, $targetTypeSuggestion).'の列マッピングを設定する' }}</a></p>@endcan
        @endif
        @if ($importBatch->status === 'ready' && $capabilities['import_execution'])
            @if ($canManageImport)
                <form method="post" action="{{ route('imports.execute', $importBatch) }}">
                    @csrf
                    <label><input type="checkbox" name="confirm" value="1" required> 内容を確認しました。{{ $importTargetLabel }}の一覧に反映します</label>
                    <button type="submit">この内容で取り込む</button>
                </form>
            @else
                <p class="muted">取込の実行は、会社のデータを管理する担当者に依頼してください。</p>
            @endif
        @elseif ($importBatch->status === 'ready')
            <p class="muted">この取込は現在実行できません。利用できる形式を確認してください。</p>
        @endif
        @if ($importBatch->status === 'completed')
            @if ($importBatch->inserted_rows + $importBatch->updated_rows > 0)
                @if (app(\App\Services\PlanFeatureService::class)->isAvailable(request()->attributes->get('currentOrganization'), 'impact_report'))
                    <p><a href="{{ route('impact-report.show') }}">導入効果レポートで取込前後を確認する</a></p>
                @endif
            @endif
            @unless ($canManageImport)
                <p class="muted">取込内容の再取得や取消などの変更操作は、会社のデータを管理する担当者に依頼してください。</p>
            @endunless
            @if ($importBatch->source_type === 'google_sheets' && $capabilities['google_sheets'])
                @if ($canManageImport)
                    <form method="post" action="{{ route('imports.google-sheet.sync', $importBatch) }}">
                        @csrf
                        <button type="submit">Google Sheetsを再取得して前回との差を確認する</button>
                    </form>
                @endif
            @endif
            @if (in_array($importBatch->source_type, ['csv', 'xlsx'], true))
                <p><a href="{{ route('imports.create', ['diff_source_batch_id' => $importBatch->id]) }}">新しいファイルを使って前回との差を確認する</a></p>
            @endif
            @if ($canManageImport)
                <form method="post" action="{{ route('imports.rollback', $importBatch) }}">
                    @csrf
                    <label><input type="checkbox" name="confirm" value="1" required> 取込後に対象データが変更されていないことを確認しました</label>
                    <button type="submit">この取込を取り消す</button>
                </form>
            @endif
        @endif
        @if ($importBatch->status === 'failed')
            <p role="status">この取込は完了していません。今回の取込によるデータ変更は保存されていません。内容を確認してから再試行してください。</p>
            @if ($importBatch->failure_message !== null)
                <p class="errors" role="alert">{{ $importBatch->failure_message }}</p>
            @endif
            <p class="errors" role="alert">{{ $mapping?->status === 'failed' ? '列の対応を調べられませんでした。' : '取込を完了できませんでした。' }} 運営へ問い合わせる場合は、参照番号 IMP-{{ $importBatch->id }} をお伝えください。<a href="{{ route('support.create', ['category' => 'import', 'error_reference' => 'IMP-'.$importBatch->id]) }}">この取込について問い合わせる</a></p>
            <p class="muted"><a href="{{ route('help.recovery', 'import') }}">取込に失敗したときの対処方法</a></p>
            @if ($canManageImport)
                <form method="post" action="{{ route('imports.retry', $importBatch) }}">
                    @csrf
                    <label><input type="checkbox" name="confirm" value="1" required> エラー内容を確認し、再試行を開始します</label>
                    <button type="submit">{{ $mapping?->status === 'failed' ? '列の対応確認をやり直す' : ($importBatch->diff_source_batch_id !== null || ($importBatch->mapping_id === null && ($importBatch->source_file_path !== null || $importBatch->source_external_id !== null)) ? '内容確認をやり直す' : '取込を再実行する') }}</button>
                </form>
            @else
                <p class="muted">再試行は、会社のデータを管理する担当者に依頼してください。</p>
            @endif
        @endif
        @if ($importBatch->status === 'completed' && $importBatch->failure_message !== null)
            <p class="errors" role="alert">{{ $importBatch->failure_message }}</p>
        @endif
        <table><thead><tr><th>元行</th><th>対象</th><th>判定</th><th>取込予定の内容</th><th>確認事項</th></tr></thead><tbody>
        @forelse ($rowResults as $result)
            <tr>
                <td>{{ $result->source_row_number }}</td>
                <td>{{ config('opsdeck.import_target_labels.'.$result->target_type, '取込対象') }}</td>
                <td>{{ $importActionLabels[$result->action] ?? '処理結果を確認してください' }}</td>
                <td>
                    @if (is_array($result->after_json) && $result->after_json !== [])
                        <dl>
                            @foreach ($result->after_json as $field => $value)
                                @php($valueLabel = is_string($value) ? ($importValueLabels[$field][$value] ?? null) : null)
                                <dt>{{ $importFieldLabels[$field] ?? $field }}</dt>
                                <dd>{{ $valueLabel ?? (is_scalar($value) || $value === null ? ($value === null ? '—' : (string) $value) : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—')) }}</dd>
                            @endforeach
                        </dl>
                    @else
                        —
                    @endif
                </td>
                <td>
                    @forelse (($result->errors_json ?? []) as $error)
                        @php($errorMessage = is_string($error) ? ($importErrorLabels[$error] ?? (preg_match('/[^\x00-\x7F]/u', $error) === 1 ? $error : 'この行を取り込めないため、項目と入力形式を確認してください。')) : (json_encode($error, JSON_UNESCAPED_UNICODE) ?: '—'))
                        <p class="errors">{{ $errorMessage }}</p>
                    @empty
                        —
                    @endforelse
                </td>
            </tr>
        @empty
            <tr><td colspan="5">データ行はありません。</td></tr>
        @endforelse
        </tbody></table>
        {{ $rowResults->links() }}
        @if ($reviewCandidates->total() > 0)
            <h2>確認が必要な候補</h2>
            <p class="muted">同じメールアドレスや管理番号などが複数の行にある候補です。自動ではまとめません。下に行番号と識別情報を並べています。内容を比べてから判断してください。</p>
            <ul>
                <li>同じ情報の重複なら、1行だけ取り込む判断にします。</li>
                <li>別人・別の資産なのに識別情報が同じ場合は、元の表を直すか、取り込まない行のチェックを外します。別データと判断するだけでは、その行は登録されずエラーになります。</li>
                <li>あとで確認する場合は、判断が終わるまで取込を実行できません。</li>
            </ul>
            <table><thead><tr><th>種別</th><th>比べる行の番号・値</th><th>確認内容</th><th>状態</th><th>判断</th></tr></thead><tbody>
            @foreach ($reviewCandidates as $candidate)
                @php($candidateTargetType = $candidate->details_json['target_type'] ?? '')
                @php($candidateIdentityFields = config('opsdeck.import_duplicate_identity_fields', [])[$candidateTargetType] ?? [])
                <tr>
                    <td>{{ $importCandidateTypeLabels[$candidate->candidate_type] ?? '候補' }}</td>
                    <td>
                        @foreach ($candidate->source_row_numbers_json ?? [] as $sourceRowNumber)
                            @php($candidateRowResult = $candidateRowResultsByNumber->get((int) $sourceRowNumber))
                            <div>
                                <strong>行 {{ number_format((int) $sourceRowNumber) }}</strong>
                                @if ($candidateRowResult === null)
                                    <p class="muted">取込予定の値を確認できません。</p>
                                @elseif ($candidateIdentityFields !== [])
                                    <ul>
                                        @foreach ($candidateIdentityFields as $identityField)
                                            @if (array_key_exists($identityField, $candidateRowResult->after_json ?? []))
                                                @php($identityValue = $candidateRowResult->after_json[$identityField])
                                                <li>{{ $importFieldLabels[$identityField] ?? $identityField }}：{{ is_scalar($identityValue) ? (string) $identityValue : '—' }}</li>
                                            @endif
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="muted">識別項目の比較表示はありません。</p>
                                @endif
                            </div>
                        @endforeach
                    </td>
                    <td>同じ識別情報が複数の行で見つかりました。</td>
                    <td>{{ $importCandidateStatusLabels[$candidate->status] ?? '確認してください' }}</td>
                    <td>
                        @if ($candidate->status === 'pending')
                            @if ($canManageImport)
                                <form method="post" action="{{ route('imports.candidates.review', [$importBatch, $candidate]) }}">
                                    @csrf
                                    <label for="candidate_status_{{ $candidate->id }}">この候補の扱い</label>
                                    <select id="candidate_status_{{ $candidate->id }}" name="status" required>
                                        <option value="accepted">同じ識別情報が重複：1行だけ取り込む</option>
                                        <option value="rejected">別データと判断：重複行はエラーとして残る</option>
                                        <option value="deferred">あとで確認：決めるまで取込できません</option>
                                    </select>
                                    <button type="submit">判断を保存する</button>
                                </form>
                            @else
                                <p class="muted">重複候補の判断は、会社のデータを管理する担当者に依頼してください。</p>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody></table>
            {{ $reviewCandidates->links() }}
        @endif
        <p><a href="{{ route('imports.index') }}">一覧へ戻る</a></p>
    </section>
</x-layouts.app>
