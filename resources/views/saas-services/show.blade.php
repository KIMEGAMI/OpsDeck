<x-layouts.app>
    <section class="card" aria-labelledby="service-title">
        <h1 id="service-title">{{ $service->name }}</h1>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        <dl><dt>ベンダー</dt><dd>{{ $service->vendor ?? '未設定' }}</dd><dt>カテゴリ</dt><dd>{{ $service->category ?? '未設定' }}</dd><dt>状態</dt><dd>{{ $service->status }}</dd></dl>
        @can('update', $service)<p><a href="{{ route('saas-services.edit', $service) }}">編集する</a></p>@endcan

        @can('update', $service)
            <section aria-labelledby="assignment-title">
                <h2 id="assignment-title">SaaSアカウントを割り当てる</h2>
                <form method="post" action="{{ route('saas-assignments.store') }}">
                    @csrf
                    <input type="hidden" name="saas_service_id" value="{{ $service->id }}">
                    <label for="employee_id">社員</label>
                    <select id="employee_id" name="employee_id"><option value="">社員に紐付けない</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>{{ $employee->name }}</option>@endforeach</select>
                    <label for="account_identifier">アカウント識別子</label><input id="account_identifier" name="account_identifier" value="{{ old('account_identifier') }}">
                    <label for="license_name">ライセンス名</label><input id="license_name" name="license_name" value="{{ old('license_name') }}">
                    <label for="status">状態</label><input id="status" name="status" value="{{ old('status') }}" required>
                    <label for="mfa_status">MFA状態</label><select id="mfa_status" name="mfa_status" required>@foreach (config('opsdeck.saas_assignment_mfa_statuses') as $mfaStatus)<option value="{{ $mfaStatus }}" @selected(old('mfa_status', config('opsdeck.saas_assignment_mfa_status.unknown')) === $mfaStatus)>{{ $mfaStatus }}</option>@endforeach</select>
                    <label for="assigned_at">割当日</label><input id="assigned_at" type="date" name="assigned_at" value="{{ old('assigned_at') }}">
                    <label for="last_reviewed_at">最終確認日</label><input id="last_reviewed_at" type="date" name="last_reviewed_at" value="{{ old('last_reviewed_at') }}">
                    <label for="ended_at">終了日</label><input id="ended_at" type="date" name="ended_at" value="{{ old('ended_at') }}">
                    <label for="memo">メモ</label><textarea id="memo" name="memo">{{ old('memo') }}</textarea>
                    <button type="submit">割当を保存する</button>
                </form>
            </section>
        @endcan

        <h2>割当一覧</h2>
        <table><thead><tr><th>社員</th><th>アカウント</th><th>状態</th><th>MFA</th><th>終了日</th><th>操作</th></tr></thead><tbody>
            @forelse ($service->assignments as $assignment)
                <tr><td>{{ $assignment->employee?->name ?? '未紐付け' }}</td><td>{{ $assignment->account_identifier ?? '未設定' }}</td><td>{{ $assignment->status }}</td><td>{{ $assignment->mfa_status }}</td><td>{{ $assignment->ended_at?->format('Y-m-d') ?? '利用中' }}</td><td>@can('update', $assignment)<a href="{{ route('saas-assignments.edit', $assignment) }}">編集</a>@endcan</td></tr>
            @empty
                <tr><td colspan="6">SaaSアカウントの割当はありません。</td></tr>
            @endforelse
        </tbody></table>
    </section>
</x-layouts.app>
