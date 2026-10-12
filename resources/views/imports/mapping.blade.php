<x-layouts.app>
    <section class="card">
        <h1>列マッピング: {{ $importBatch->source_name }}</h1>
        <p class="muted">ここではファイルの列を組織内の対象データに対応づけます。対象種別を選び、各列のOpsDeck項目を確認してください。</p>
        <p class="muted">使用する行: 見出し {{ $importBatch->header_row_number }}〜{{ $importBatch->resolvedHeaderEndRowNumber() }}行目、データ開始 {{ $importBatch->resolvedDataStartRowNumber() }}行目。</p>
        <p class="muted">保存後、行ごとの新規・更新・確認待ち・エラーを分析します。分析中はImportできません。業務データはこの操作では変更されません。</p>
        <p class="muted">社員の部署を紐付ける場合は、部署台帳を先にImportし、社員列を「部署名」に対応づけてください。前後空白を除き、同じ組織内で英字の大小を無視して一致する場合のみ紐付きます。空欄は新規社員では未割当、既存社員では現在の部署を維持します。</p>
        <p class="muted">契約にSaaSや担当社員を紐付ける場合は、SaaS・社員台帳を先にImportし、列を「SaaS名」「担当者メール」に対応づけてください。照合は同じ組織内に限定され、未一致の行はPreviewでエラーになります。</p>
        <p class="muted">セキュリティ管理項目の担当社員を紐付ける場合は、社員台帳を先にImportし、メール列を「担当者メール」に対応づけてください。照合は同じ組織内に限定され、未一致はPreviewでエラー、空欄は既存担当者を維持します。</p>
        <p><a href="{{ route('imports.mapping', [$importBatch, 'ai' => 1, 'ai_target_type' => 'employees']) }}">AI列マッピング候補を取得（社員）</a></p>
        <p class="muted"><a href="{{ route('help.recovery', 'ai') }}">AI候補が使えないときの復旧ガイド</a></p>
        <p class="muted">AI機能が有効な場合、この操作では取込元の列名・対象データ種別・OpsDeckの候補項目を設定済みのAIサービスへ送信します。列名に含まれるメールアドレス・電話番号らしき値は送信前に伏せますが、氏名などを含む場合は先に匿名化してください。取込元の行データやファイル／シート全体は送信しません。AIを使わず手動で設定することもできます。</p>
        @if ($aiSuggestions !== [])
            <section aria-labelledby="ai-mapping-suggestions-title">
                <h2 id="ai-mapping-suggestions-title">AI列マッピング候補</h2>
                <p class="muted">AI候補は提案です。信頼度は正しさの保証ではありません。内容を確認し、必要に応じて修正してから保存してください。</p>
                <table>
                    <thead><tr><th>CSV列</th><th>候補項目</th><th>信頼度</th><th>理由</th><th>確認</th></tr></thead>
                    <tbody>
                        @foreach ($aiSuggestions as $header => $candidate)
                            <tr>
                                <td>{{ $header }}</td>
                                <td>{{ $candidate['field'] !== null ? config('opsdeck.import_field_labels.'.$candidate['field'], $candidate['field']) : '候補なし' }}</td>
                                <td>{{ number_format($candidate['confidence'] * 100, 0) }}%</td>
                                <td>{{ $candidate['reason'] }}</td>
                                <td>
                                    @if (($candidate['source'] ?? null) === 'ai_escalated')
                                        <strong>Sol追加解析・人の確認が必要</strong>
                                    @elseif ($candidate['requires_review'] ?? true)
                                        <strong>人の確認が必要</strong>
                                    @else
                                        候補です。保存前に確認してください。
                                    @endif
                                    @if (is_string($candidate['question'] ?? null))<p>{{ $candidate['question'] }}</p>@endif
                                </td>
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
            @if ($profiles->isNotEmpty())
                <label for="profile_id">保存済みプロファイル（この組織・同じシート、列構成、行配置のみ）</label>
                <select id="profile_id" name="profile_id">
                    <option value="">使用しない</option>
                    @foreach ($profiles as $profile)
                        <option value="{{ $profile->id }}" @selected($profile->id === $selectedProfile?->id)>{{ $profile->name }}（{{ config('opsdeck.import_target_labels.'.$profile->target_type, $profile->target_type) }}・v{{ $profile->version }}）</option>
                    @endforeach
                </select>
                <p class="muted">プロファイルを選ぶとマッピング候補が入力されます。保存前に内容を確認してください。</p>
            @endif
            <label for="target_type">対象データ種別</label>
            <select id="target_type" name="target_type" required>
                @foreach ($targetTypes as $targetType)<option value="{{ $targetType }}" @selected($targetType === $suggestedTargetType)>{{ config('opsdeck.import_target_labels.'.$targetType, $targetType) }}</option>@endforeach
            </select>
            <h2>CSV列</h2>
            @foreach (($importBatch->headers_json ?? []) as $header)
                <label for="column_{{ $loop->index }}">{{ $header }}</label>
                <input id="column_{{ $loop->index }}" name="column_map[{{ $header }}]" data-header-index="{{ $loop->index }}" value="{{ $initialColumnMap[$header] ?? '' }}" placeholder="OpsDeckの項目名（例: name）">
            @endforeach
            @if ($normalizationRuleOptions->isNotEmpty())
                <fieldset>
                    <legend>値の正規化（任意・選択した項目だけ適用）</legend>
                    <p class="muted">変換は取込分析と実行の両方に適用され、プロファイルにも保存されます。候補は一部データの例です。必ず変換内容を確認してください。</p>
                    @foreach ($normalizationRuleOptions as $option)
                        @php($savedRule = old('normalization_rules.'.$option['field'], $selectedProfile?->normalization_rules[$option['field']] ?? null))
                        <label data-normalization-target="{{ $option['target_type'] }}">
                            <input type="checkbox" name="normalization_rules[{{ $option['field'] }}]" value="{{ $option['rule'] }}" data-normalization-field="{{ $option['field'] }}" data-normalization-rule="{{ $option['rule'] }}" @checked($option['target_type'] === $normalizationTargetType && $savedRule === $option['rule'])>
                            {{ $option['field'] }} — {{ $option['label'] }}
                            @if ($option['suggestion'] !== null)
                                （例: {{ $option['suggestion']['raw_preview'] }} → {{ $option['suggestion']['normalized'] }}）
                            @else
                                （サンプルで変換候補は検出されていません）
                            @endif
                        </label>
                    @endforeach
                </fieldset>
            @endif
            <fieldset>
                <legend>取込プロファイル</legend>
                <label><input type="checkbox" name="save_profile" value="1" @checked(old('save_profile'))> このマッピングを組織内で再利用できるよう保存</label>
                <label for="profile_name">プロファイル名</label>
                <input id="profile_name" name="profile_name" value="{{ old('profile_name', $selectedProfile?->name ?? '') }}" maxlength="120" placeholder="例: 社員台帳">
                <p class="muted">既存プロファイルを選んで保存すると新しい版として記録します。過去の版も選択して再利用できます。</p>
            </fieldset>
            <button type="submit">マッピングを保存</button>
        </form>
        <script>
            const mappingSuggestions = {{ Js::from($suggestions) }};
            const profileSuggestions = {{ Js::from($profiles->keyBy('id')->map(fn ($profile) => ['target_type' => $profile->target_type, 'column_map' => $profile->column_map, 'normalization_rules' => $profile->normalization_rules ?? [], 'name' => $profile->name])) }};
            const targetType = document.getElementById('target_type');
            const profileSelect = document.getElementById('profile_id');
            const profileName = document.getElementById('profile_name');
            const updateSuggestions = () => document.querySelectorAll('[data-header-index]').forEach((input) => { input.value = mappingSuggestions[targetType.value]?.[input.dataset.headerIndex] ?? ''; });
            const clearNormalizationRules = () => document.querySelectorAll('[data-normalization-field]').forEach((input) => { input.checked = false; });
            const updateNormalizationOptions = () => document.querySelectorAll('[data-normalization-target]').forEach((label) => {
                label.hidden = label.dataset.normalizationTarget !== targetType.value;
            });
            const applyProfile = (profile) => {
                targetType.value = profile.target_type;
                updateNormalizationOptions();
                document.querySelectorAll('[data-header-index]').forEach((input) => {
                    const header = input.name.match(/^column_map\[(.*)\]$/)?.[1];
                    input.value = profile.column_map[header] ?? '';
                });
                if (profileName) {
                    profileName.value = profile.name;
                }
                clearNormalizationRules();
                document.querySelectorAll('[data-normalization-field]').forEach((input) => {
                    input.checked = profile.normalization_rules[input.dataset.normalizationField] === input.dataset.normalizationRule;
                });
            };
            targetType.addEventListener('change', () => {
                if (profileSelect) {
                    profileSelect.value = '';
                }
                if (profileName) {
                    profileName.value = '';
                }
                clearNormalizationRules();
                updateNormalizationOptions();
                updateSuggestions();
            });
            updateSuggestions();
            updateNormalizationOptions();
            profileSelect?.addEventListener('change', () => {
                const profile = profileSuggestions[profileSelect.value];
                if (profile) {
                    applyProfile(profile);
                } else {
                    updateSuggestions();
                }
            });
            if (profileSelect?.value && profileSuggestions[profileSelect.value]) {
                applyProfile(profileSuggestions[profileSelect.value]);
            }
        </script>
    </section>
</x-layouts.app>
