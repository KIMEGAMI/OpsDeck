<x-layouts.app>
    <section class="card">
        <h1>列マッピング: {{ $importBatch->source_name }}</h1>
        <p class="muted">ここではファイルの列を組織内の対象データに対応づけます。対象種別を選び、各列のOpsDeck項目を確認してください。</p>
        <p class="muted">保存後、行ごとの新規・更新・確認待ち・エラーを分析します。分析中はImportできません。業務データはこの操作では変更されません。</p>
        <p><a href="{{ route('imports.mapping', [$importBatch, 'ai' => 1, 'ai_target_type' => 'employees']) }}">AI列マッピング候補を取得（社員）</a></p>
        <p class="muted"><a href="{{ route('help.recovery', 'ai') }}">AI候補が使えないときの復旧ガイド</a></p>
        <p class="muted">AI機能が有効な場合、この操作では取込元の列名・対象データ種別・OpsDeckの候補項目だけを設定済みのAIサービスへ送信します。取込元の行データやファイル／シート全体は送信しません。</p>
        @if ($aiSuggestions !== [])
            <section aria-labelledby="ai-mapping-suggestions-title">
                <h2 id="ai-mapping-suggestions-title">AI列マッピング候補</h2>
                <p class="muted">AI候補は提案です。信頼度は正しさの保証ではありません。内容を確認し、必要に応じて修正してから保存してください。</p>
                <table>
                    <thead><tr><th>CSV列</th><th>候補項目</th><th>信頼度</th><th>理由</th></tr></thead>
                    <tbody>
                        @foreach ($aiSuggestions as $header => $candidate)
                            <tr>
                                <td>{{ $header }}</td>
                                <td>{{ $candidate['field'] !== null ? config('opsdeck.import_field_labels.'.$candidate['field'], $candidate['field']) : '候補なし' }}</td>
                                <td>{{ number_format($candidate['confidence'] * 100, 0) }}%</td>
                                <td>{{ $candidate['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
        @if ($normalizationSuggestions !== [])
            <h2>正規化候補（確認が必要）</h2>
            <ul>@foreach ($normalizationSuggestions as $candidate)<li>{{ $candidate['field'] }}: {{ $candidate['raw_preview'] }} → {{ $candidate['normalized'] }}（{{ $candidate['reason'] }}）</li>@endforeach</ul>
        @endif
        <form method="post" action="{{ route('imports.mapping.store', $importBatch) }}">
            @csrf
            <label for="target_type">対象データ種別</label>
            <select id="target_type" name="target_type" required>
                @foreach ($targetTypes as $targetType)<option value="{{ $targetType }}">{{ config('opsdeck.import_target_labels.'.$targetType, $targetType) }}</option>@endforeach
            </select>
            <h2>CSV列</h2>
            @foreach (($importBatch->headers_json ?? []) as $header)
                <label for="column_{{ $loop->index }}">{{ $header }}</label>
                <input id="column_{{ $loop->index }}" name="column_map[{{ $header }}]" data-header-index="{{ $loop->index }}" value="" placeholder="OpsDeckの項目名（例: name）">
            @endforeach
            <button type="submit">マッピングを保存</button>
        </form>
        <script>
            const mappingSuggestions = {{ Js::from($suggestions) }};
            const targetType = document.getElementById('target_type');
            const updateSuggestions = () => document.querySelectorAll('[data-header-index]').forEach((input) => { input.value = mappingSuggestions[targetType.value]?.[input.dataset.headerIndex] ?? ''; });
            targetType.addEventListener('change', updateSuggestions);
            updateSuggestions();
        </script>
    </section>
</x-layouts.app>
