<x-layouts.app>
    <section class="card">
        <h1>ファイルの列を登録項目に結びつける：{{ $importBatch->source_name }}</h1>
        <x-import-progress :current-step="2" />
            <p class="muted">ファイルの列名を見ながら、どの項目として登録するか選びます。自動で選ばれた項目も、必ず元の表と見比べてください。</p>
        <p class="muted">今回は {{ $importBatch->header_row_number }}〜{{ $importBatch->resolvedHeaderEndRowNumber() }}行目を列名として読み、{{ $importBatch->resolvedDataStartRowNumber() }}行目からデータとして読みます。</p>
            <p class="muted">ここで設定を保存しても、会社のデータはまだ変わりません。行ごとの内容を確認し、最後に「取込を実行する」を押したときだけ反映されます。</p>
        <details>
            <summary>部署・担当者・端末なども一緒に結びつける（必要な場合のみ）</summary>
            <p class="muted">社員の部署も登録する場合は、先に部署の表を取り込みます。社員の「部署名」が登録済みの部署名と一致したときに結びつきます。部署名が空欄なら、新しい社員は「未設定」、登録済み社員は現在の部署のままです。</p>
            <p class="muted">契約にクラウドサービスや担当社員も登録する場合は、先にそれぞれの表を取り込みます。契約のサービス名・担当者メールが同じ会社に見つからないときは、確認が必要になります。</p>
            <p class="muted">セキュリティ管理項目に担当社員も登録する場合は、先に社員の表を取り込みます。担当者メールが同じ会社に見つからないときは確認が必要です。空欄の場合、登録済みの担当者はそのままです。</p>
            <p class="muted">端末を社員に割り当てる場合は、先に資産の表と在籍中の社員の表を取り込みます。資産番号・社員メール・割当日を使って照合します。資産は「在庫」で、まだ誰にも割り当てられていない必要があります。</p>
            <p class="muted">クラウドサービスの利用者を登録する場合は、先にクラウドサービスの表を取り込みます。サービス名と多要素認証の状態が必要です。社員に割り当てる場合は社員メールで照合します。社員を指定しない場合は、全社共通アカウントとして登録します。</p>
        </details>
        <p class="muted"><a href="{{ route('help.recovery', 'ai') }}">AI候補が使えないときの復旧ガイド</a></p>
        <section aria-labelledby="ai-mapping-request-title">
            <h2 id="ai-mapping-request-title">AIに列の対応を提案してもらう（任意）</h2>
            @if (! $aiMappingPlanAvailable)
                <p class="muted">AI候補は現在利用できません。列名は手動で設定できます。</p>
            @elseif (! $aiMappingOrganizationEnabled)
                <p class="muted">この組織ではAIの提案を使えません。手動で設定できます。必要な場合は組織の管理者に相談してください。</p>
            @elseif (! $aiMappingServiceConfigured)
                <p class="muted">AI接続または費用設定がないため、今は候補を取得できません。手動で列を設定してください。</p>
            @else
                <p class="muted">ボタンを押すと、取込元の列名・対象データ種別・候補項目だけを設定済みのAIサービスへ送信します。行データやファイル全体は送りません。列名にメールアドレス・電話番号らしき値があれば伏せますが、氏名などが含まれる場合は、先に列名を匿名化してください。</p>
                <p class="muted">GPT-6 Lunaのみを使用し、1組織あたり1日{{ number_format($aiMappingUsageSummary['daily_request_limit']) }}回まで利用できます。利用料金はかかりません。送信せず、手動で列を設定することもできます。</p>
                <p class="muted">本日のAI候補利用：{{ number_format($aiMappingUsageSummary['requests_used']) }}回／{{ number_format($aiMappingUsageSummary['daily_request_limit']) }}回（処理中 {{ number_format($aiMappingUsageSummary['requests_pending']) }}回、あと{{ number_format($aiMappingUsageSummary['requests_remaining']) }}回）。日付は組織のタイムゾーンで切り替わります。</p>
                <form method="post" action="{{ route('imports.mapping.ai', $importBatch) }}">
                    @csrf
                    <input type="hidden" name="ai" value="1">
                    @php($aiTargetTypeOption = $aiTargetType ?: ($suggestedTargetType ?: ($targetTypes[0] ?? '')))
                    <label for="ai_target_type">AIに対応を提案してもらう一覧</label>
                    <select id="ai_target_type" name="ai_target_type" required>
                        @foreach ($targetTypes as $targetType)
                            <option value="{{ $targetType }}" @selected($aiTargetTypeOption === $targetType)>{{ config('opsdeck.import_target_labels.'.$targetType, $targetType) }}</option>
                        @endforeach
                    </select>
                    <button type="submit">選んだ一覧の列名をAIへ送って候補を作る</button>
                </form>
            @endif
        </section>
        @if ($aiSuggestions !== [])
            <section aria-labelledby="ai-mapping-suggestions-title">
                <h2 id="ai-mapping-suggestions-title">AIが提案した列の対応</h2>
                <p class="muted">AI候補は提案です。信頼度は正しさの保証ではありません。内容を確認し、必要に応じて修正してから保存してください。</p>
                <table>
                            <thead><tr><th>ファイルの列名</th><th>候補項目</th><th>信頼度</th><th>理由</th><th>確認</th></tr></thead>
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
            <h2>入力形式の変換候補（確認が必要）</h2>
            <ul>@foreach ($normalizationSuggestions as $candidate)<li>{{ config('opsdeck.import_field_labels.'.$candidate['field'], $candidate['field']) }}：{{ $candidate['raw_preview'] }} → {{ $candidate['normalized'] }}（{{ $candidate['reason'] }}）</li>@endforeach</ul>
        @endif
        <form method="post" action="{{ route('imports.mapping.store', $importBatch) }}">
            @csrf
            <input type="hidden" name="ai_mapping_feedback_token" value="{{ old('ai_mapping_feedback_token', $aiMappingFeedbackToken ?? '') }}">
            @if ($profiles->isNotEmpty())
            <label for="profile_id">以前保存した設定を使う（同じ表の形の場合のみ）</label>
                <select id="profile_id" name="profile_id">
                    <option value="">使用しない</option>
                    @foreach ($profiles as $profile)
                        <option value="{{ $profile->id }}" @selected($profile->id === $selectedProfile?->id)>{{ $profile->name }}（{{ config('opsdeck.import_target_labels.'.$profile->target_type, $profile->target_type) }}・v{{ $profile->version }}）</option>
                    @endforeach
                </select>
            <p class="muted">以前の設定が入力されます。今回の表と列が合っているか確認してから使ってください。</p>
            @endif
            <p id="profile-layout-mismatch-notice" class="muted" role="note" @if (! $hasDifferentLayoutProfile) hidden @endif>この取込先には以前保存した設定がありますが、今回の表の列名や行位置が異なるため自動では使っていません。列名・見出し行・データ開始行を確認してマッピングし直し、今回の設定として保存してください。</p>
            <label for="target_type">この表は何の一覧ですか？</label>
            <select id="target_type" name="target_type" required>
                @foreach ($targetTypes as $targetType)<option value="{{ $targetType }}" data-profile-layout-mismatch="{{ in_array($targetType, $targetTypesWithDifferentLayoutProfiles, true) ? 'true' : 'false' }}" @selected($targetType === $suggestedTargetType)>{{ config('opsdeck.import_target_labels.'.$targetType, $targetType) }}</option>@endforeach
            </select>
            <h2>ファイルの列名と、登録する項目を対応させる</h2>
            <p class="muted">ファイルの列ごとに、内容に合う項目を選びます。「（必須）」の項目は、登録する各行に必要です。使わない列は「この列は使いません」のままでかまいません。</p>
            <p class="muted">質問票回答は「回答（文章）」または「回答（選択肢）」のどちらかに内容が必要です。</p>
            @php($mappingOptionsTargetType = $suggestedTargetType ?: ($targetTypes[0] ?? ''))
            @php($mappingOptionsForTarget = $mappingFieldOptions[$mappingOptionsTargetType] ?? [])
            @php($oldColumnMap = old('column_map', []))
            @foreach (($importBatch->headers_json ?? []) as $header)
                @php($suggestedField = $suggestions[$mappingOptionsTargetType][$loop->index] ?? '')
                @php($savedField = $initialColumnMap[$header] ?? $suggestedField)
                @php($selectedField = is_array($oldColumnMap) ? ($oldColumnMap[$header] ?? $savedField) : $savedField)
                <label for="column_{{ $loop->index }}">{{ $header }}</label>
                <select id="column_{{ $loop->index }}" name="column_map[{{ $header }}]" data-header-index="{{ $loop->index }}" data-initial-value="{{ $selectedField }}">
                    <option value="">この列は使いません</option>
                    @foreach ($mappingOptionsForTarget as $option)
                        <option value="{{ $option['value'] }}" @selected($selectedField === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                    @if ($selectedField !== '' && ! collect($mappingOptionsForTarget)->contains('value', $selectedField))
                        <option value="{{ $selectedField }}" selected>以前の設定（項目を確認してください）</option>
                    @endif
                </select>
            @endforeach
            @if ($normalizationRuleOptions->isNotEmpty())
                @if ($errors->any())
                    <details open>
                @else
                    <details>
                @endif
                    <summary>日付や金額の形式を整える（必要な場合のみ）</summary>
                    <p class="muted">通常は設定不要です。日付や金額が正しく読み取れないときだけ開いてください。</p>
                <fieldset>
                <legend>入力形式を整える（必要な場合のみ）</legend>
                    <p class="muted">変換は取込分析と実行の両方に適用され、プロファイルにも保存されます。候補は一部データの例です。必ず変換内容を確認してください。</p>
                    @foreach ($normalizationRuleOptions as $option)
                        @php($savedRule = old('normalization_rules.'.$option['field'], $selectedProfile?->normalization_rules[$option['field']] ?? null))
                        <label data-normalization-target="{{ $option['target_type'] }}">
                            @if (count($option['choices']) > 1)
                                {{ config('opsdeck.import_field_labels.'.$option['field'], $option['field']) }} — 日付形式
                                <select name="normalization_rules[{{ $option['field'] }}]" data-normalization-field="{{ $option['field'] }}">
                                    <option value="">変換しない（元の値のまま）</option>
                                    @foreach ($option['choices'] as $choice)
                                        <option value="{{ $choice['rule'] }}" @selected($option['target_type'] === $normalizationTargetType && $savedRule === $choice['rule'])>{{ $choice['label'] }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="checkbox" name="normalization_rules[{{ $option['field'] }}]" value="{{ $option['rule'] }}" data-normalization-field="{{ $option['field'] }}" data-normalization-rule="{{ $option['rule'] }}" @checked($option['target_type'] === $normalizationTargetType && $savedRule === $option['rule'])>
                                {{ config('opsdeck.import_field_labels.'.$option['field'], $option['field']) }} — {{ $option['label'] }}
                            @endif
                            @if ($option['suggestion'] !== null)
                                （例: {{ $option['suggestion']['raw_preview'] }} → {{ $option['suggestion']['normalized'] }}）
                            @else
                                （サンプルで変換候補は検出されていません）
                            @endif
                        </label>
                    @endforeach
                </fieldset>
                </details>
            @endif
            @if ($errors->any())
                <details open>
            @else
                <details>
            @endif
                <summary>表に書かれた状態や区分を置き換える（必要な場合のみ）</summary>
                <p class="muted">通常は設定不要です。表の値がOpsDeckの選択肢と異なるときだけ開いてください。</p>
            <fieldset>
                <legend>既存システムの値を置き換える（必要な場合のみ）</legend>
                <p class="muted">必要な場合だけ、表に書かれた状態や区分を、OpsDeckで同じ意味の値として扱うようにします。表示された候補を追加し、確認画面で変換後の表示を確かめてください。対応していない値は、取り込み前に確認できます。</p>
                <p class="muted">変換候補は自動では使われません。使う候補だけ追加し、保存後の確認画面で変換結果を見てください。</p>
                @foreach ($valueMappingFields as $mappingTargetType => $fields)
                    @foreach ($fields as $field)
                        <div data-value-mapping-target="{{ $mappingTargetType }}" data-value-mapping-field="{{ $field['field'] }}">
                            <strong>{{ $field['label'] }}</strong>
                            @foreach ($field['suggestions'] as $suggestion)
                                <button type="button"
                                    data-add-value-mapping="{{ $field['field'] }}"
                                    data-add-value-mapping-target="{{ $mappingTargetType }}"
                                    data-source="{{ $suggestion['source'] }}"
                                    data-target="{{ $suggestion['target'] }}">
                                    「{{ $suggestion['source'] }}」を「{{ $suggestion['target_label'] }}」として扱う候補を追加
                                </button>
                            @endforeach
                            <div data-value-mapping-rows="{{ $mappingTargetType }}.{{ $field['field'] }}"></div>
                            <button type="button" data-add-value-mapping="{{ $field['field'] }}" data-add-value-mapping-target="{{ $mappingTargetType }}">置換行を追加</button>
                        </div>
                    @endforeach
                @endforeach
            </fieldset>
            </details>
            @if ($errors->any())
                <details open>
            @else
                <details>
            @endif
                <summary>この設定を次回も使う（必要な場合のみ）</summary>
                <p class="muted">同じ形式の表を何度も取り込む場合、列の対応を組織内に保存できます。</p>
            <fieldset>
                <legend>取込プロファイル</legend>
                <label><input type="checkbox" name="save_profile" value="1" @checked(old('save_profile'))> このマッピングを組織内で再利用できるよう保存</label>
                <label for="profile_name">プロファイル名</label>
                <input id="profile_name" name="profile_name" value="{{ old('profile_name', $selectedProfile?->name ?? '') }}" maxlength="120" placeholder="例: 社員台帳">
                <p class="muted">既存プロファイルを選んで保存すると新しい版として記録します。過去の版も選択して再利用できます。</p>
            </fieldset>
            </details>
            <button type="submit">この対応を保存して内容を確認する</button>
        </form>
        <script>
            const mappingSuggestions = {{ Js::from($suggestions) }};
            const mappingFieldOptions = {{ Js::from($mappingFieldOptions) }};
            const profileSuggestions = {{ Js::from($profiles->keyBy('id')->map(fn ($profile) => ['target_type' => $profile->target_type, 'column_map' => $profile->column_map, 'normalization_rules' => $profile->normalization_rules ?? [], 'value_mappings' => $profile->value_mappings ?? [], 'name' => $profile->name])) }};
            const initialValueMappings = {{ Js::from(old('value_mappings', $selectedProfile?->value_mappings ?? [])) }};
            const targetType = document.getElementById('target_type');
            const aiTargetType = document.getElementById('ai_target_type');
            const profileSelect = document.getElementById('profile_id');
            const profileLayoutMismatchNotice = document.getElementById('profile-layout-mismatch-notice');
            const profileName = document.getElementById('profile_name');
            const nextValueMappingIndexes = {};
            const populateColumnOptions = (select, selectedValue = '') => {
                const options = mappingFieldOptions[targetType.value] ?? [];
                select.replaceChildren(new Option('この列は使いません', ''));
                options.forEach((field) => select.add(new Option(field.label, field.value)));
                if (selectedValue && !options.some((field) => field.value === selectedValue)) {
                    select.add(new Option('以前の設定（項目を確認してください）', selectedValue));
                }
                select.value = selectedValue;
            };
            const updateSuggestions = (preserveInitialValues = false) => document.querySelectorAll('[data-header-index]').forEach((select) => {
                const suggestedValue = mappingSuggestions[targetType.value]?.[select.dataset.headerIndex] ?? '';
                const selectedValue = preserveInitialValues ? (select.dataset.initialValue || suggestedValue) : suggestedValue;
                populateColumnOptions(select, selectedValue);
            });
            targetType.addEventListener('change', () => {
                if (aiTargetType) {
                    aiTargetType.value = targetType.value;
                }
            });
            const clearNormalizationRules = () => document.querySelectorAll('[data-normalization-field]').forEach((input) => {
                if (input instanceof HTMLSelectElement) {
                    input.value = '';
                } else {
                    input.checked = false;
                }
            });
            const updateNormalizationOptions = () => document.querySelectorAll('[data-normalization-target]').forEach((label) => {
                const isVisible = label.dataset.normalizationTarget === targetType.value;
                label.hidden = !isVisible;
                label.querySelectorAll('[data-normalization-field]').forEach((input) => { input.disabled = !isVisible; });
            });
            const updateProfileLayoutMismatchNotice = () => {
                profileLayoutMismatchNotice.hidden = targetType.selectedOptions[0]?.dataset.profileLayoutMismatch !== 'true';
            };
            const addValueMappingRow = (mappingTargetType, field, source = '', target = '') => {
                const container = document.querySelector(`[data-value-mapping-rows="${mappingTargetType}.${field}"]`);
                if (!container) {
                    return;
                }
                const mappingKey = `${mappingTargetType}.${field}`;
                const index = nextValueMappingIndexes[mappingKey] ?? 0;
                nextValueMappingIndexes[mappingKey] = index + 1;
                const row = document.createElement('div');
                const sourceLabel = document.createElement('label');
                const sourceInput = document.createElement('input');
                const targetLabel = document.createElement('label');
                const targetInput = document.createElement('input');
                const removeButton = document.createElement('button');
                sourceInput.type = 'text';
                sourceInput.name = `value_mappings[${field}][${index}][source]`;
                sourceInput.maxLength = {{ (int) config('opsdeck.import.value_mapping_max_value_length') }};
                sourceInput.value = source;
                sourceLabel.textContent = '取込元';
                sourceLabel.append(sourceInput);
                targetInput.type = 'text';
                targetInput.name = `value_mappings[${field}][${index}][target]`;
                targetInput.maxLength = {{ (int) config('opsdeck.import.value_mapping_max_value_length') }};
                targetInput.value = target;
                targetLabel.textContent = 'OpsDeckの値';
                targetLabel.append(targetInput);
                removeButton.type = 'button';
                removeButton.textContent = '削除';
                removeButton.addEventListener('click', () => row.remove());
                row.append(sourceLabel, targetLabel, removeButton);
                container.append(row);
            };
            const renderValueMappings = (mappings) => {
                document.querySelectorAll('[data-value-mapping-rows]').forEach((container) => { container.replaceChildren(); });
                Object.keys(nextValueMappingIndexes).forEach((field) => { delete nextValueMappingIndexes[field]; });
                document.querySelectorAll('[data-value-mapping-target]').forEach((group) => {
                    group.hidden = group.dataset.valueMappingTarget !== targetType.value;
                });
                Object.entries(mappings ?? {}).forEach(([field, entries]) => {
                    const rows = Array.isArray(entries)
                        ? entries
                        : Object.entries(entries ?? {}).map(([source, target]) => ({ source, target }));
                    rows.forEach((entry) => { addValueMappingRow(targetType.value, field, entry.source ?? '', entry.target ?? ''); });
                });
            };
            const applyProfile = (profile) => {
                targetType.value = profile.target_type;
                updateProfileLayoutMismatchNotice();
                updateNormalizationOptions();
                document.querySelectorAll('[data-header-index]').forEach((select) => {
                    const header = select.name.match(/^column_map\[(.*)\]$/)?.[1];
                    populateColumnOptions(select, profile.column_map[header] ?? '');
                });
                if (profileName) {
                    profileName.value = profile.name;
                }
                clearNormalizationRules();
                document.querySelectorAll('[data-normalization-field]').forEach((input) => {
                    const savedRule = profile.normalization_rules[input.dataset.normalizationField] ?? '';
                    if (input instanceof HTMLSelectElement) {
                        input.value = savedRule;
                    } else {
                        input.checked = savedRule === input.dataset.normalizationRule;
                    }
                });
                renderValueMappings(profile.value_mappings);
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
                renderValueMappings({});
                updateProfileLayoutMismatchNotice();
            });
            document.querySelectorAll('[data-add-value-mapping]').forEach((button) => {
                button.addEventListener('click', () => {
                    const field = button.dataset.addValueMapping;
                    const mappingTargetType = button.dataset.addValueMappingTarget ?? targetType.value;
                    addValueMappingRow(mappingTargetType, field, button.dataset.source ?? '', button.dataset.target ?? '');
                });
            });
            updateSuggestions(true);
            updateNormalizationOptions();
            updateProfileLayoutMismatchNotice();
            renderValueMappings(initialValueMappings);
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
