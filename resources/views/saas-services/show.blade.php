<x-layouts.app>
    <section class="card" aria-labelledby="service-title">
        <h1 id="service-title">{{ $service->name }}</h1>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        <dl><dt>ベンダー</dt><dd>{{ $service->vendor ?? '未設定' }}</dd><dt>カテゴリ</dt><dd>{{ $service->category ?? '未設定' }}</dd><dt>状態</dt><dd>{{ config('opsdeck.saas_service_status_labels.'.$service->status, $service->status) }}</dd></dl>
        @can('update', $service)<p><a href="{{ route('saas-services.edit', $service) }}">編集する</a></p>@endcan

        @can('update', $service)
            <section aria-labelledby="assignment-title">
                <h2 id="assignment-title">利用者を登録する</h2>
                <form method="post" action="{{ route('saas-assignments.store') }}">
                    @csrf
                    <input type="hidden" name="saas_service_id" value="{{ $service->id }}">
                    <label for="employee_id">利用する社員</label>
                    <select id="employee_id" name="employee_id" @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
                        <option value="">社員を選ばない</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) old('employee_id', $selectedEmployeeId) === (string) $employee->id)>{{ $employee->name }}</option>
                        @endforeach
                    </select>
                    <x-field-error field="employee_id" />

                    <label for="account_identifier">ログインID・メールアドレスなど</label>
                    <input id="account_identifier" name="account_identifier" value="{{ old('account_identifier') }}" @error('account_identifier') aria-invalid="true" aria-describedby="account_identifier-error" @enderror>
                    <x-field-error field="account_identifier" />

                    <label for="license_name">ライセンス名</label>
                    <input id="license_name" name="license_name" value="{{ old('license_name') }}" @error('license_name') aria-invalid="true" aria-describedby="license_name-error" @enderror>
                    <x-field-error field="license_name" />

                    <label for="status">アカウントの状態</label>
                    <input id="status" name="status" list="saas-assignment-status-suggestions" value="{{ old('status', 'active') }}" @error('status') aria-invalid="true" aria-describedby="saas-assignment-status-help status-error" @else aria-describedby="saas-assignment-status-help" @enderror required>
                    <datalist id="saas-assignment-status-suggestions">
                        @foreach (config('opsdeck.saas_assignment_status_suggestions') as $status => $label)
                            <option value="{{ $status }}" label="{{ $label }}"></option>
                        @endforeach
                    </datalist>
                    <small id="saas-assignment-status-help">候補から「利用中」または「利用終了」を選べます。</small>
                    <x-field-error field="status" />

                    <label for="mfa_status">ログイン時の追加確認の状態</label>
                    <p class="muted">パスワード以外の方法（確認コードなど）でも本人確認する設定です。分からない場合は「未確認」を選んでください。</p>
                    <select id="mfa_status" name="mfa_status" required @error('mfa_status') aria-invalid="true" aria-describedby="mfa_status-error" @enderror>
                        @foreach (config('opsdeck.saas_assignment_mfa_statuses') as $mfaStatus)
                            <option value="{{ $mfaStatus }}" @selected(old('mfa_status', config('opsdeck.saas_assignment_mfa_status.unknown')) === $mfaStatus)>{{ config('opsdeck.saas_assignment_mfa_status_labels.'.$mfaStatus, $mfaStatus) }}</option>
                        @endforeach
                    </select>
                    <x-field-error field="mfa_status" />

                    <label for="assigned_at">割当日</label>
                    <input id="assigned_at" type="date" name="assigned_at" value="{{ old('assigned_at') }}" @error('assigned_at') aria-invalid="true" aria-describedby="assigned_at-error" @enderror>
                    <x-field-error field="assigned_at" />

                    <label for="last_reviewed_at">最終確認日</label>
                    <input id="last_reviewed_at" type="date" name="last_reviewed_at" value="{{ old('last_reviewed_at') }}" @error('last_reviewed_at') aria-invalid="true" aria-describedby="last_reviewed_at-error" @enderror>
                    <x-field-error field="last_reviewed_at" />

                    <label for="ended_at">終了日</label>
                    <input id="ended_at" type="date" name="ended_at" value="{{ old('ended_at') }}" @error('ended_at') aria-invalid="true" aria-describedby="ended_at-error" @enderror>
                    <x-field-error field="ended_at" />

                    <label for="memo">メモ</label>
                    <textarea id="memo" name="memo" @error('memo') aria-invalid="true" aria-describedby="memo-error" @enderror>{{ old('memo') }}</textarea>
                    <x-field-error field="memo" />
                    <button type="submit">割当を保存する</button>
                </form>
            </section>
        @endcan

        <h2>割当一覧</h2>
        <table><thead><tr><th>社員</th><th>アカウント</th><th>状態</th><th>ログイン時の追加確認</th><th>終了日</th><th>操作</th></tr></thead><tbody>
            @forelse ($service->assignments as $assignment)
                <tr><td>{{ $assignment->employee?->name ?? '社員の登録なし' }}</td><td>{{ $assignment->account_identifier ?? '未入力' }}</td><td>{{ config('opsdeck.saas_assignment_status_labels.'.$assignment->status, $assignment->status) }}</td><td>{{ config('opsdeck.saas_assignment_mfa_status_labels.'.$assignment->mfa_status, $assignment->mfa_status) }}</td><td>{{ $assignment->ended_at?->format('Y-m-d') ?? '利用中' }}</td><td>@can('update', $assignment)<a href="{{ route('saas-assignments.edit', $assignment) }}">編集</a>@endcan</td></tr>
            @empty
                <tr><td colspan="6">利用者はまだ登録されていません。</td></tr>
            @endforelse
        </tbody></table>
    </section>
</x-layouts.app>
