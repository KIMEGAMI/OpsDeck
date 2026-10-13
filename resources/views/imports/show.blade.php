<x-layouts.app>
    <section class="card">
        <h1>{{ $importBatch->source_name }}</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p>状態: {{ $importBatchStatusLabels[$importBatch->status] ?? $importBatch->status }} / 種別: {{ $importSourceTypeLabels[$importBatch->source_type] ?? $importBatch->source_type }} / データ行数: {{ $importBatch->total_rows }}</p>
        @if ($importBatch->selected_sheet_name !== null)<p class="muted">対象シート: {{ $importBatch->selected_sheet_name }}</p>@endif
        @if (in_array($importBatch->source_type, ['csv', 'xlsx', 'google_sheets'], true))<p class="muted">見出し行: {{ $importBatch->header_row_number }}〜{{ $importBatch->resolvedHeaderEndRowNumber() }} / データ開始行: {{ $importBatch->resolvedDataStartRowNumber() }}</p>@endif
        @if ($importBatch->status === 'queued')
            <p role="status">Importは処理待ちです。準備ができると自動で実行されます。この画面を更新すると状態を確認できます。</p>
        @elseif ($importBatch->status === 'previewing')
            <p role="status">{{ $importBatch->diff_source_batch_id !== null ? 'Google Sheetsの差分Previewを準備しています。処理中はこの画面を更新して状態を確認してください。' : 'ファイルを解析してPreviewを準備しています。処理中はこの画面を更新して状態を確認してください。' }}</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @elseif ($importBatch->status === 'analyzing')
            <p role="status">列マッピング後の重複候補と件数を分析しています。この間はImportできません。画面を更新して状態を確認してください。</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @elseif ($importBatch->status === 'executing')
            <p role="status">Importをバックグラウンドで処理中です。この画面を更新すると状態を確認できます。</p>
        @elseif ($importBatch->status === 'retrying')
            <p role="status">大容量Importの再試行用データをバックグラウンドで準備しています。この画面を更新すると状態を確認できます。</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @elseif ($importBatch->status === 'rolling_back')
            <p role="status">大容量Importの取消をバックグラウンドで処理しています。この画面を更新すると状態を確認できます。</p>
            <p><a href="{{ route('imports.show', $importBatch) }}">状態を更新</a></p>
        @endif
        @if ($importBatch->status === 'sheet_selection' && (($importBatch->source_type === 'xlsx' && $capabilities['xlsx_preview']) || ($importBatch->source_type === 'google_sheets' && $capabilities['google_sheets'])))
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
        @elseif ($importBatch->status === 'sheet_selection')
            <p class="muted">この形式のImportはPremiumプランで利用できます。<a href="{{ route('billing.index') }}">プランを確認する</a></p>
        @endif
        @if ($availableAdditionalSheets !== [] && $capabilities['xlsx_preview'])
            @can('create', [App\Models\ImportBatch::class, $organization])
                <details>
                    <summary>このXLSXの別シートを取り込む</summary>
                    <p class="muted">別シートは独立したImportバッチとしてPreview・マッピング・承認を行います。異なる台帳の列や社員・資産などの関係を自動で結合しません。</p>
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
                        <button type="submit">別シートのPreviewを作成</button>
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
        @if ($importBatch->source_file_sha256 !== null)
            <dl><dt>元ファイル SHA-256</dt><dd><code>{{ $importBatch->source_file_sha256 }}</code></dd></dl>
        @endif
        @if ($importBatch->status === 'failed' || (in_array($importBatch->status, ['completed', 'cancelled'], true) && $importBatch->error_rows > 0))
            @if ($importBatch->errors_reviewed_at === null)
                @can('update', $importBatch)
                    <form method="post" action="{{ route('imports.review-errors', $importBatch) }}">
                        @csrf
                        <label><input type="checkbox" name="confirm" value="1" required> エラーまたは実行失敗を確認し、対処方針を決めました</label>
                        <button type="submit">確認済みにする</button>
                    </form>
                @endcan
            @else
                <p role="status">このバッチのエラーは確認済みとして記録されています（{{ $importBatch->errors_reviewed_at->timezone($organization->timezone)->format('Y-m-d H:i') }}）。</p>
            @endif
        @endif
        @if ($importBatch->status === 'preview' || $importBatch->status === 'ready')
            <h2>Import Preview</h2>
            <dl><dt>新規候補</dt><dd>{{ $importBatch->preview_new_rows }}</dd><dt>更新候補</dt><dd>{{ $importBatch->preview_updated_rows }}</dd><dt>確認必要</dt><dd>{{ $importBatch->preview_confirmation_rows }}</dd><dt>エラー候補</dt><dd>{{ $importBatch->preview_error_rows }}</dd></dl>
            @can('update', $importBatch)
                <form method="post" action="{{ route('imports.rows.selection', ['importBatch' => $importBatch, 'row_results_page' => $rowResults->currentPage()]) }}">
                    @csrf
                    <p>このページのImport対象行を選択してください（未選択行は反映されません）。他のページの選択状態は保持されます。</p>
                    @foreach ($selectableRowResults as $result)
                        <input type="hidden" name="visible_row_numbers[]" value="{{ $result->source_row_number }}">
                        <label><input type="checkbox" name="selected_row_numbers[]" value="{{ $result->source_row_number }}" @checked($result->is_selected)> 行 {{ $result->source_row_number }}（{{ $importActionLabels[$result->action] ?? $result->action }}）</label>
                    @endforeach
                    <button type="submit">このページの選択を保存</button>
                </form>
            @endcan
        @endif
        @if ($importBatch->status === 'preview' || $importBatch->status === 'ready')
            <p class="muted">これはプレビューです。業務テーブルへの反映はまだ実行されていません。</p>
        @endif
        @if (in_array($importBatch->status, ['preview', 'ready'], true))
            @can('update', $importBatch)<p><a href="{{ route('imports.mapping', $targetTypeSuggestion === null ? $importBatch : [$importBatch, 'target_type' => $targetTypeSuggestion]) }}">{{ $targetTypeSuggestion === null ? '列マッピングを設定する' : config('opsdeck.import_target_labels.'.$targetTypeSuggestion, $targetTypeSuggestion).'の列マッピングを設定する' }}</a></p>@endcan
        @endif
        @if ($importBatch->status === 'ready' && $capabilities['import_execution'])
            <form method="post" action="{{ route('imports.execute', $importBatch) }}">
                @csrf
                <label><input type="checkbox" name="confirm" value="1" required> プレビュー内容を確認し、{{ $importTargetLabel }}データへの反映を実行します</label>
                <button type="submit">Importを実行</button>
            </form>
        @elseif ($importBatch->status === 'ready')
            <p class="muted">Importの実行はPremiumプランで利用できます。<a href="{{ route('billing.index') }}">プランを確認する</a></p>
        @endif
        @if ($importBatch->status === 'completed')
            @if ($importBatch->inserted_rows + $importBatch->updated_rows > 0)
                <p><a href="{{ route('impact-report.show') }}">導入効果レポートでImport前後を確認する</a></p>
            @endif
            @if ($importBatch->source_type === 'google_sheets')
                <form method="post" action="{{ route('imports.google-sheet.sync', $importBatch) }}">
                    @csrf
                    <button type="submit">Google Sheetsを再取得して差分Preview</button>
                </form>
            @endif
            <form method="post" action="{{ route('imports.rollback', $importBatch) }}">
                @csrf
                <label><input type="checkbox" name="confirm" value="1" required> 現在のデータがImport後に変更されていないことを確認しました</label>
                <button type="submit">Importを取消</button>
            </form>
        @endif
        @if ($importBatch->status === 'failed')
            @if ($importBatch->failure_message !== null)
                <p class="errors" role="alert">{{ $importBatch->failure_message }}</p>
            @endif
            <p class="errors" role="alert">{{ $mapping?->status === 'failed' ? '列マッピングの分析を完了できませんでした。' : 'Importを完了できませんでした。' }} 運営へ問い合わせる場合は、参照番号 IMP-{{ $importBatch->id }} をお伝えください。<a href="{{ route('support.create', ['category' => 'import', 'error_reference' => 'IMP-'.$importBatch->id]) }}">このImportについて問い合わせる</a></p>
            <p class="muted"><a href="{{ route('help.recovery', 'import') }}">Import失敗の復旧ガイド</a></p>
            <form method="post" action="{{ route('imports.retry', $importBatch) }}">
                @csrf
                <label><input type="checkbox" name="confirm" value="1" required> エラー内容を確認し、再試行を開始します</label>
                <button type="submit">{{ $mapping?->status === 'failed' ? 'マッピング分析を再試行' : ($importBatch->diff_source_batch_id !== null || ($importBatch->mapping_id === null && ($importBatch->source_file_path !== null || $importBatch->source_external_id !== null)) ? 'Previewを再試行' : 'Importを再実行') }}</button>
            </form>
        @endif
        @if ($importBatch->status === 'completed' && $importBatch->failure_message !== null)
            <p class="errors" role="alert">{{ $importBatch->failure_message }}</p>
        @endif
        <table><thead><tr><th>元行</th><th>対象</th><th>判定</th><th>取込予定の内容</th><th>確認事項</th></tr></thead><tbody>
        @forelse ($rowResults as $result)
            <tr>
                <td>{{ $result->source_row_number }}</td>
                <td>{{ config('opsdeck.import_target_labels.'.$result->target_type, $result->target_type) }}</td>
                <td>{{ $importActionLabels[$result->action] ?? $result->action }}</td>
                <td>
                    @if (is_array($result->after_json) && $result->after_json !== [])
                        <dl>
                            @foreach ($result->after_json as $field => $value)
                                <dt>{{ $importFieldLabels[$field] ?? $field }}</dt>
                                <dd>{{ is_scalar($value) || $value === null ? ($value === null ? '—' : (string) $value) : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—') }}</dd>
                            @endforeach
                        </dl>
                    @else
                        —
                    @endif
                </td>
                <td>
                    @forelse (($result->errors_json ?? []) as $error)
                        <p class="errors">{{ is_string($error) ? ($importErrorLabels[$error] ?? $error) : (json_encode($error, JSON_UNESCAPED_UNICODE) ?: '—') }}</p>
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
            <table><thead><tr><th>種別</th><th>行</th><th>信頼度</th><th>状態</th><th>判断</th></tr></thead><tbody>
            @foreach ($reviewCandidates as $candidate)
                <tr><td>{{ $importCandidateTypeLabels[$candidate->candidate_type] ?? $candidate->candidate_type }}</td><td>{{ implode(', ', $candidate->source_row_numbers_json ?? []) }}</td><td>{{ number_format($candidate->confidence * 100, 0) }}%</td><td>{{ $importCandidateStatusLabels[$candidate->status] ?? $candidate->status }}</td><td>@if($candidate->status === 'pending')<form method="post" action="{{ route('imports.candidates.review', [$importBatch, $candidate]) }}">@csrf<select name="status" required><option value="accepted">統合候補として採用</option><option value="rejected">別データ</option><option value="deferred">保留</option></select><button type="submit">保存</button></form>@else—@endif</td></tr>
            @endforeach
            </tbody></table>
            {{ $reviewCandidates->links() }}
        @endif
        <p><a href="{{ route('imports.index') }}">一覧へ戻る</a></p>
    </section>
</x-layouts.app>
