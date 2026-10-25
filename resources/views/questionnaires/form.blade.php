<x-layouts.app>
    <section class="card" aria-labelledby="questionnaire-form-title">
        <h1 id="questionnaire-form-title">{{ $questionnaire->exists ? '質問票を編集' : '質問票を登録' }}</h1>
        @php
            $questionnaireStatuses = config('opsdeck.questionnaire_statuses', []);
            $draftQuestionnaireStatus = config('opsdeck.questionnaire_status.draft');
            $questionnaireStatusDefault = in_array($questionnaire->status, $questionnaireStatuses, true)
                ? $questionnaire->status
                : (in_array($draftQuestionnaireStatus, $questionnaireStatuses, true) ? $draftQuestionnaireStatus : ($questionnaireStatuses[0] ?? null));
            $selectedQuestionnaireStatus = in_array(old('status'), $questionnaireStatuses, true) ? old('status') : $questionnaireStatusDefault;
        @endphp
        <form method="post" action="{{ $formAction }}">
            @csrf
            @if ($formMethod !== 'POST')
                @method($formMethod)
            @endif

            <label for="title">タイトル</label>
            <input id="title" name="title" value="{{ old('title', $questionnaire->title) }}" required @error('title') aria-invalid="true" aria-describedby="title-error" @enderror>
            <x-field-error field="title" />

            <label for="requester_name">依頼元</label>
            <input id="requester_name" name="requester_name" value="{{ old('requester_name', $questionnaire->requester_name) }}" @error('requester_name') aria-invalid="true" aria-describedby="requester_name-error" @enderror>
            <x-field-error field="requester_name" />

            <label for="requested_at">依頼を受けた日</label>
            <input id="requested_at" name="requested_at" type="date" value="{{ old('requested_at', $questionnaire->requested_at?->format('Y-m-d')) }}" @error('requested_at') aria-invalid="true" aria-describedby="requested_at-error" @enderror>
            <x-field-error field="requested_at" />

            <label for="due_at">回答期限</label>
            <input id="due_at" name="due_at" type="date" value="{{ old('due_at', $questionnaire->due_at?->format('Y-m-d')) }}" @error('due_at') aria-invalid="true" aria-describedby="due_at-error" @enderror>
            <x-field-error field="due_at" />

            <label for="status">状態</label>
            <select id="status" name="status" required @error('status') aria-invalid="true" aria-describedby="status-error" @enderror>
                @foreach ($questionnaireStatuses as $status)
                    <option value="{{ $status }}" @selected($selectedQuestionnaireStatus === $status)>{{ config('opsdeck.questionnaire_status_labels.'.$status, $status) }}</option>
                @endforeach
            </select>
            <x-field-error field="status" />

            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
