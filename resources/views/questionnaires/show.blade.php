<x-layouts.app>
    <section class="card">
        @php
            $questionnaireAnswerTypes = config('opsdeck.questionnaire_answer_type_labels', []);
            $defaultQuestionnaireAnswerType = array_key_first($questionnaireAnswerTypes);
            $selectedQuestionnaireAnswerType = old('answer_type', $defaultQuestionnaireAnswerType);
        @endphp
        <h1>{{ $questionnaire->title }}</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <dl>
            <dt>依頼元</dt><dd>{{ $questionnaire->requester_name ?? '未設定' }}</dd>
            <dt>依頼を受けた日</dt><dd>{{ $questionnaire->requested_at?->format('Y-m-d') ?? '未設定' }}</dd>
            <dt>期限</dt><dd>{{ $questionnaire->due_at?->format('Y-m-d') ?? '未設定' }}</dd>
            <dt>状態</dt><dd>{{ config('opsdeck.questionnaire_status_labels.'.$questionnaire->status, $questionnaire->status) }}</dd>
        </dl>

        @can('update', $questionnaire)
            <p><a href="{{ route('questionnaires.edit', $questionnaire) }}">編集する</a></p>
            <h2>質問を追加</h2>
            <form method="post" action="{{ route('questionnaire-questions.store') }}">
                @csrf
                <input type="hidden" name="questionnaire_id" value="{{ $questionnaire->id }}">
                <label for="question-section">章・分類（任意）</label>
                <input id="question-section" name="section" value="{{ old('section') }}" @error('section') aria-invalid="true" aria-describedby="section-error" @enderror>
                <x-field-error field="section" />

                <label for="question-number">設問番号（任意）</label>
                <input id="question-number" name="question_number" value="{{ old('question_number') }}" placeholder="例：1-2" @error('question_number') aria-invalid="true" aria-describedby="question_number-error" @enderror>
                <x-field-error field="question_number" />

                <label for="question_text">質問</label>
                <textarea id="question_text" name="question_text" required @error('question_text') aria-invalid="true" aria-describedby="question_text-error" @enderror>{{ old('question_text') }}</textarea>
                <x-field-error field="question_text" />

                <label for="answer_type">回答形式</label>
                <select id="answer_type" name="answer_type" required @error('answer_type') aria-invalid="true" aria-describedby="answer-type-help answer_type-error" @else aria-describedby="answer-type-help" @enderror>
                    @foreach ($questionnaireAnswerTypes as $answerType => $answerTypeLabel)
                        <option value="{{ $answerType }}" @selected($selectedQuestionnaireAnswerType === $answerType)>{{ $answerTypeLabel }}</option>
                    @endforeach
                </select>
                <small id="answer-type-help">選んだ形式を回答する人に案内します。現在は文章で回答する形式です。</small>
                <x-field-error field="answer_type" />

                <label for="sort_order">順番</label>
                <input id="sort_order" name="sort_order" type="number" min="1" value="{{ old('sort_order', $questionnaire->questions->count() + 1) }}" required @error('sort_order') aria-invalid="true" aria-describedby="sort_order-error" @enderror>
                <x-field-error field="sort_order" />

                <button type="submit">追加</button>
            </form>
        @endcan

        @if (config('opsdeck.release_features.questionnaire_ai_draft'))
            <p class="muted">登録済みの管理項目から回答候補を作ります。証跡との関連は自動で確認されないため、実際の運用状況と証跡を人が確認してから承認してください。</p>
        @else
            <p class="muted">回答案を入力し、登録情報と証跡を確認してから承認してください。</p>
        @endif
        <h2>質問一覧</h2>
        <ol>
            @forelse ($questionnaire->questions as $question)
                @php
                    $questionnaireAnswerSourceTypes = config('opsdeck.questionnaire_answer_source_types', []);
                    $unknownQuestionnaireAnswerSource = 'UNKNOWN';
                    $oldQuestionId = old('question_id');
                    $isTargetedNewAnswerForm = (is_string($oldQuestionId) || is_int($oldQuestionId))
                        && (string) $oldQuestionId === (string) $question->id;
                    $oldNewAnswerSourceType = old('source_type');
                    $selectedNewAnswerSourceType = $isTargetedNewAnswerForm && in_array($oldNewAnswerSourceType, $questionnaireAnswerSourceTypes, true)
                        ? $oldNewAnswerSourceType
                        : $unknownQuestionnaireAnswerSource;
                @endphp
                <li>
                    @if ($question->section || $question->question_number)
                        <span class="muted">{{ collect([$question->section, $question->question_number])->filter()->implode('・') }}：</span>
                    @endif
                    {{ $question->question_text }}（{{ config('opsdeck.questionnaire_answer_type_labels.'.$question->answer_type, $question->answer_type) }}）
                    @if ($question->answers->isNotEmpty())
                        @php
                            $answer = $question->answers->last();
                            $oldAnswerId = old('answer_id');
                            $isTargetedExistingAnswerForm = (is_string($oldAnswerId) || is_int($oldAnswerId))
                                && (string) $oldAnswerId === (string) $answer->id;
                            $oldExistingAnswerSourceType = old('source_type');
                            $selectedExistingAnswerSourceType = $isTargetedExistingAnswerForm && in_array($oldExistingAnswerSourceType, $questionnaireAnswerSourceTypes, true)
                                ? $oldExistingAnswerSourceType
                                : $answer->source_type;
                            $oldAnswerText = old('answer_text', $answer->answer_text);
                            $answerTextValue = is_string($oldAnswerText) ? $oldAnswerText : '';
                            $oldAnswerChoice = old('answer_choice', $answer->answer_choice);
                            $answerChoiceValue = is_string($oldAnswerChoice) ? $oldAnswerChoice : '';
                        @endphp
                        <p>
                            回答候補: {{ $answer->answer_text ?? $answer->answer_choice ?? '（回答なし）' }}
                            （{{ config('opsdeck.questionnaire_answer_source_type_labels.'.$answer->source_type, $answer->source_type) }}・{{ config('opsdeck.questionnaire_answer_status_labels.'.$answer->answer_status, $answer->answer_status) }}）
                        </p>
                        @can('update', $answer)
                            @if ($answer->answer_status === \App\Models\QuestionnaireAnswer::DRAFT_STATUS && $answer->approved_at === null)
                                <form method="post" action="{{ route('questionnaire-answers.update', $answer) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="answer_id" value="{{ $answer->id }}">
                                    <label for="answer-text-{{ $answer->id }}">回答を確認・修正する</label>
                                    <textarea id="answer-text-{{ $answer->id }}" name="answer_text" @if ($isTargetedExistingAnswerForm && $errors->has('answer_text')) aria-invalid="true" aria-describedby="answer-text-error-{{ $answer->id }}" @endif>{{ $isTargetedExistingAnswerForm ? $answerTextValue : $answer->answer_text }}</textarea>
                                    @if ($isTargetedExistingAnswerForm)
                                        <x-field-error field="answer_text" id="answer-text-error-{{ $answer->id }}" />
                                    @endif
                                    <label for="answer-choice-{{ $answer->id }}">選択回答（必要な場合）</label>
                                    <input id="answer-choice-{{ $answer->id }}" name="answer_choice" value="{{ $isTargetedExistingAnswerForm ? $answerChoiceValue : $answer->answer_choice }}" @if ($isTargetedExistingAnswerForm && $errors->has('answer_choice')) aria-invalid="true" aria-describedby="answer-choice-error-{{ $answer->id }}" @endif>
                                    @if ($isTargetedExistingAnswerForm)
                                        <x-field-error field="answer_choice" id="answer-choice-error-{{ $answer->id }}" />
                                    @endif
                                    <label for="answer-source-{{ $answer->id }}">情報の根拠</label>
                                    <select id="answer-source-{{ $answer->id }}" name="source_type" required @if ($isTargetedExistingAnswerForm && $errors->has('source_type')) aria-invalid="true" aria-describedby="answer-source-error-{{ $answer->id }}" @endif>
                                        @foreach ($questionnaireAnswerSourceTypes as $sourceType)
                                            <option value="{{ $sourceType }}" @selected($selectedExistingAnswerSourceType === $sourceType)>{{ config('opsdeck.questionnaire_answer_source_type_labels.'.$sourceType, $sourceType) }}</option>
                                        @endforeach
                                    </select>
                                    @if ($isTargetedExistingAnswerForm)
                                        <x-field-error field="source_type" id="answer-source-error-{{ $answer->id }}" />
                                    @endif
                                    <input type="hidden" name="answer_status" value="{{ \App\Models\QuestionnaireAnswer::DRAFT_STATUS }}">
                                    <button type="submit">回答案を保存</button>
                                </form>
                            @endif
                        @endcan
                        @if ($answer->answer_status === \App\Models\QuestionnaireAnswer::DRAFT_STATUS && $answer->approved_at === null)
                            @can('approve', $answer)
                                <p class="muted">内容と情報源を確認し、正しい場合だけ承認してください。承認後はこの回答を編集できません。</p>
                                <form method="post" action="{{ route('questionnaire-answers.approve', $answer) }}">
                                    @csrf
                                    <button type="submit">この回答を確認して承認</button>
                                </form>
                            @endcan
                        @endif
                    @endif

                    @if ($question->answers->isEmpty() || $question->answers->last()->approved_at !== null)
                        @can('update', $questionnaire)
                            <h3>回答を登録する</h3>
                            <form method="post" action="{{ route('questionnaire-answers.store') }}">
                                @csrf
                                <input type="hidden" name="question_id" value="{{ $question->id }}">
                                <label for="new-answer-text-{{ $question->id }}">回答文</label>
                                    <textarea id="new-answer-text-{{ $question->id }}" name="answer_text" @if ($isTargetedNewAnswerForm && $errors->has('answer_text')) aria-invalid="true" aria-describedby="new-answer-text-error-{{ $question->id }}" @endif>{{ $isTargetedNewAnswerForm && is_string(old('answer_text')) ? old('answer_text') : '' }}</textarea>
                                    @if ($isTargetedNewAnswerForm)
                                        <x-field-error field="answer_text" id="new-answer-text-error-{{ $question->id }}" />
                                    @endif
                                <label for="new-answer-choice-{{ $question->id }}">選択回答（必要な場合）</label>
                                    <input id="new-answer-choice-{{ $question->id }}" name="answer_choice" value="{{ $isTargetedNewAnswerForm && is_string(old('answer_choice')) ? old('answer_choice') : '' }}" @if ($isTargetedNewAnswerForm && $errors->has('answer_choice')) aria-invalid="true" aria-describedby="new-answer-choice-error-{{ $question->id }}" @endif>
                                    @if ($isTargetedNewAnswerForm)
                                        <x-field-error field="answer_choice" id="new-answer-choice-error-{{ $question->id }}" />
                                    @endif
                                <label for="new-answer-source-{{ $question->id }}">情報の根拠</label>
                                    <select id="new-answer-source-{{ $question->id }}" name="source_type" required @if ($isTargetedNewAnswerForm && $errors->has('source_type')) aria-invalid="true" aria-describedby="new-answer-source-error-{{ $question->id }}" @endif>
                                        @foreach ($questionnaireAnswerSourceTypes as $sourceType)
                                            <option value="{{ $sourceType }}" @selected($selectedNewAnswerSourceType === $sourceType)>{{ config('opsdeck.questionnaire_answer_source_type_labels.'.$sourceType, $sourceType) }}</option>
                                    @endforeach
                                </select>
                                    @if ($isTargetedNewAnswerForm)
                                        <x-field-error field="source_type" id="new-answer-source-error-{{ $question->id }}" />
                                    @endif
                                <input type="hidden" name="answer_status" value="{{ \App\Models\QuestionnaireAnswer::DRAFT_STATUS }}">
                                <button type="submit">回答案を登録</button>
                            </form>
                        @endcan
                    @endif

                    @if (config('opsdeck.release_features.questionnaire_ai_draft'))
                        @can('update', $questionnaire)
                        <form method="post" action="{{ route('questionnaires.ai-draft', $questionnaire) }}">
                            @csrf
                            <input type="hidden" name="question_id" value="{{ $question->id }}">
                            <button type="submit">登録情報から回答案を作成</button>
                        </form>
                        @endcan
                    @endif
                </li>
            @empty
                <li>質問は未登録です。</li>
            @endforelse
        </ol>
    </section>
</x-layouts.app>
