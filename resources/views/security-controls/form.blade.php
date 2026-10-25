<x-layouts.app>
    <section class="card" aria-labelledby="security-control-form-title">
        <h1 id="security-control-form-title">{{ $control->exists ? '管理項目を編集' : '管理項目を登録' }}</h1>
        @php
            $securityControlStatuses = config('opsdeck.security_control_statuses', []);
            $unknownSecurityControlStatus = config('opsdeck.security_control_status.unknown');
            $securityControlStatusDefault = in_array($control->status, $securityControlStatuses, true)
                ? $control->status
                : (in_array($unknownSecurityControlStatus, $securityControlStatuses, true) ? $unknownSecurityControlStatus : ($securityControlStatuses[0] ?? null));
            $selectedSecurityControlStatus = in_array(old('status'), $securityControlStatuses, true) ? old('status') : $securityControlStatusDefault;
            $oldOwnerEmployeeId = old('owner_employee_id', $control->owner_employee_id);
            $selectedOwnerEmployeeId = is_string($oldOwnerEmployeeId) || is_int($oldOwnerEmployeeId) ? (string) $oldOwnerEmployeeId : '';
        @endphp
        <form method="post" action="{{ $formAction }}">
            @csrf
            @if ($formMethod !== 'POST')
                @method($formMethod)
            @endif

            <label for="control_code">コード</label>
            <input id="control_code" name="control_code" value="{{ old('control_code', $control->control_code) }}" required @error('control_code') aria-invalid="true" aria-describedby="control_code-error" @enderror>
            <x-field-error field="control_code" />

            <label for="title">項目名</label>
            <input id="title" name="title" value="{{ old('title', $control->title) }}" required @error('title') aria-invalid="true" aria-describedby="title-error" @enderror>
            <x-field-error field="title" />

            <label for="category">カテゴリ</label>
            <input id="category" name="category" value="{{ old('category', $control->category) }}" required @error('category') aria-invalid="true" aria-describedby="category-error" @enderror>
            <x-field-error field="category" />

            <label for="description">説明</label>
            <textarea id="description" name="description" required @error('description') aria-invalid="true" aria-describedby="description-error" @enderror>{{ old('description', $control->description) }}</textarea>
            <x-field-error field="description" />

            <label for="status">状態</label>
            <select id="status" name="status" required @error('status') aria-invalid="true" aria-describedby="status-error" @enderror>
                @foreach ($securityControlStatuses as $status)
                    <option value="{{ $status }}" @selected($selectedSecurityControlStatus === $status)>{{ config('opsdeck.security_control_status_labels.'.$status, $status) }}</option>
                @endforeach
            </select>
            <x-field-error field="status" />

            <label for="owner_employee_id">担当者（任意）</label>
            <select id="owner_employee_id" name="owner_employee_id" @error('owner_employee_id') aria-invalid="true" aria-describedby="owner_employee_id-error" @enderror>
                <option value="" @selected($selectedOwnerEmployeeId === '')>担当者を選ばない</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}" @selected($selectedOwnerEmployeeId === (string) $employee->id)>{{ $employee->name }}</option>
                @endforeach
            </select>
            <x-field-error field="owner_employee_id" />

            <label for="review_frequency">確認する頻度（任意）</label>
            <input id="review_frequency" name="review_frequency" value="{{ old('review_frequency', $control->review_frequency) }}" placeholder="例：毎月、半年ごと" @error('review_frequency') aria-invalid="true" aria-describedby="review_frequency-error" @enderror>
            <x-field-error field="review_frequency" />

            <label for="last_reviewed_at">最終確認日</label>
            <input id="last_reviewed_at" type="date" name="last_reviewed_at" value="{{ old('last_reviewed_at', $control->last_reviewed_at?->format('Y-m-d')) }}" @error('last_reviewed_at') aria-invalid="true" aria-describedby="last_reviewed_at-error" @enderror>
            <x-field-error field="last_reviewed_at" />

            <label for="next_review_at">次回確認日</label>
            <input id="next_review_at" type="date" name="next_review_at" value="{{ old('next_review_at', $control->next_review_at?->format('Y-m-d')) }}" @error('next_review_at') aria-invalid="true" aria-describedby="next_review_at-error" @enderror>
            <x-field-error field="next_review_at" />

            <label for="memo">メモ</label>
            <textarea id="memo" name="memo" @error('memo') aria-invalid="true" aria-describedby="memo-error" @enderror>{{ old('memo', $control->memo) }}</textarea>
            <x-field-error field="memo" />

            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
