<x-layouts.app>
    <section class="card" aria-labelledby="assignment-title">
        <h1 id="assignment-title">{{ $service->name }} のSaaSアカウントを編集</h1>
        <form method="post" action="{{ $formAction }}">
            @csrf
            @method($formMethod)
            <label for="employee_id">社員</label>
            <select id="employee_id" name="employee_id"><option value="">社員に紐付けない</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}" @selected((string) old('employee_id', $assignment->employee_id) === (string) $employee->id)>{{ $employee->name }}</option>@endforeach</select>
            <label for="account_identifier">アカウント識別子</label><input id="account_identifier" name="account_identifier" value="{{ old('account_identifier', $assignment->account_identifier) }}">
            <label for="license_name">ライセンス名</label><input id="license_name" name="license_name" value="{{ old('license_name', $assignment->license_name) }}">
            <label for="status">状態</label><input id="status" name="status" value="{{ old('status', $assignment->status) }}" required>
            <label for="mfa_status">MFA状態</label><select id="mfa_status" name="mfa_status" required>@foreach (config('opsdeck.saas_assignment_mfa_statuses') as $mfaStatus)<option value="{{ $mfaStatus }}" @selected(old('mfa_status', $assignment->mfa_status) === $mfaStatus)>{{ $mfaStatus }}</option>@endforeach</select>
            <label for="assigned_at">割当日</label><input id="assigned_at" type="date" name="assigned_at" value="{{ old('assigned_at', $assignment->assigned_at?->format('Y-m-d')) }}">
            <label for="last_reviewed_at">最終確認日</label><input id="last_reviewed_at" type="date" name="last_reviewed_at" value="{{ old('last_reviewed_at', $assignment->last_reviewed_at?->format('Y-m-d')) }}">
            <label for="ended_at">終了日</label><input id="ended_at" type="date" name="ended_at" value="{{ old('ended_at', $assignment->ended_at?->format('Y-m-d')) }}">
            <label for="memo">メモ</label><textarea id="memo" name="memo">{{ old('memo', $assignment->memo) }}</textarea>
            <button type="submit">更新する</button>
        </form>
        <p><a href="{{ route('saas-services.show', $service) }}">SaaS詳細へ戻る</a></p>
    </section>
</x-layouts.app>
