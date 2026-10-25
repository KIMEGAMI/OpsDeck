<x-layouts.app>
    <section class="card" aria-labelledby="assignment-title">
        <h1 id="assignment-title">{{ $service->name }} を利用する社員の情報を編集</h1>
        @php
            $oldEmployeeId = old('employee_id', $assignment->employee_id);
            $selectedEmployeeId = is_string($oldEmployeeId) || is_int($oldEmployeeId) ? (string) $oldEmployeeId : '';
            $mfaStatuses = config('opsdeck.saas_assignment_mfa_statuses', []);
            $unknownMfaStatus = config('opsdeck.saas_assignment_mfa_status.unknown');
            $mfaStatusDefault = in_array($assignment->mfa_status, $mfaStatuses, true)
                ? $assignment->mfa_status
                : (in_array($unknownMfaStatus, $mfaStatuses, true) ? $unknownMfaStatus : ($mfaStatuses[0] ?? null));
            $selectedMfaStatus = in_array(old('mfa_status'), $mfaStatuses, true) ? old('mfa_status') : $mfaStatusDefault;
        @endphp
        <form method="post" action="{{ $formAction }}">
            @csrf
            @method($formMethod)
            <label for="employee_id">利用する社員</label>
            <select id="employee_id" name="employee_id" @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
                <option value="" @selected($selectedEmployeeId === '')>社員を選ばない</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}" @selected($selectedEmployeeId === (string) $employee->id)>{{ $employee->name }}@if ($employee->employment_status === config('opsdeck.employee_status.retired'))（退職済み・終了処理用）@endif</option>
                @endforeach
            </select>
            <x-field-error field="employee_id" />

            <label for="account_identifier">ログインID・メールアドレスなど</label>
            <input id="account_identifier" name="account_identifier" value="{{ old('account_identifier', $assignment->account_identifier) }}" @error('account_identifier') aria-invalid="true" aria-describedby="account_identifier-error" @enderror>
            <x-field-error field="account_identifier" />

            <label for="license_name">ライセンス名</label>
            <input id="license_name" name="license_name" value="{{ old('license_name', $assignment->license_name) }}" @error('license_name') aria-invalid="true" aria-describedby="license_name-error" @enderror>
            <x-field-error field="license_name" />

            <label for="status">アカウントの状態</label>
            <input id="status" name="status" list="saas-assignment-status-suggestions" value="{{ old('status', $assignment->status) }}" @error('status') aria-invalid="true" aria-describedby="saas-assignment-status-help status-error" @else aria-describedby="saas-assignment-status-help" @enderror required>
            <datalist id="saas-assignment-status-suggestions">
                @foreach (config('opsdeck.saas_assignment_status_suggestions') as $status => $label)
                    <option value="{{ $status }}" label="{{ $label }}"></option>
                @endforeach
            </datalist>
            <small id="saas-assignment-status-help">候補から「利用中」または「利用終了」を選べます。候補にない既存の状態も編集できます。</small>
            <x-field-error field="status" />

            <label for="mfa_status">ログイン時の追加確認の状態</label>
            <p class="muted">パスワード以外の方法（確認コードなど）でも本人確認する設定です。分からない場合は「未確認」を選んでください。</p>
            <select id="mfa_status" name="mfa_status" required @error('mfa_status') aria-invalid="true" aria-describedby="mfa_status-error" @enderror>
                @foreach ($mfaStatuses as $mfaStatus)
                    <option value="{{ $mfaStatus }}" @selected($selectedMfaStatus === $mfaStatus)>{{ config('opsdeck.saas_assignment_mfa_status_labels.'.$mfaStatus, $mfaStatus) }}</option>
                @endforeach
            </select>
            <x-field-error field="mfa_status" />

            <label for="assigned_at">割当日</label>
            <input id="assigned_at" type="date" name="assigned_at" value="{{ old('assigned_at', $assignment->assigned_at?->format('Y-m-d')) }}" @error('assigned_at') aria-invalid="true" aria-describedby="assigned_at-error" @enderror>
            <x-field-error field="assigned_at" />

            <label for="last_reviewed_at">最終確認日</label>
            <input id="last_reviewed_at" type="date" name="last_reviewed_at" value="{{ old('last_reviewed_at', $assignment->last_reviewed_at?->format('Y-m-d')) }}" @error('last_reviewed_at') aria-invalid="true" aria-describedby="last_reviewed_at-error" @enderror>
            <x-field-error field="last_reviewed_at" />

            <label for="ended_at">終了日</label>
            <input id="ended_at" type="date" name="ended_at" value="{{ old('ended_at', $assignment->ended_at?->format('Y-m-d')) }}" @error('ended_at') aria-invalid="true" aria-describedby="ended_at-error" @enderror>
            <x-field-error field="ended_at" />

            <label for="memo">メモ</label>
            <textarea id="memo" name="memo" @error('memo') aria-invalid="true" aria-describedby="memo-error" @enderror>{{ old('memo', $assignment->memo) }}</textarea>
            <x-field-error field="memo" />
            <button type="submit">更新する</button>
        </form>
        <p><a href="{{ route('saas-services.show', $service) }}">クラウドサービスの詳細へ戻る</a></p>
    </section>
</x-layouts.app>
