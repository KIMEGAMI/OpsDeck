<x-layouts.app>
    <section class="card" aria-labelledby="employee-form-title">
        <h1 id="employee-form-title">{{ $employee->exists ? '社員情報を編集' : '社員を登録' }}</h1>
        <form method="post" action="{{ $formAction }}">
            @csrf
            @if ($formMethod !== 'POST') @method($formMethod) @endif
            <label for="name">氏名</label>
            <input id="name" name="name" value="{{ old('name', $employee->name) }}" required>
            <label for="employee_number">社員番号</label>
            <input id="employee_number" name="employee_number" value="{{ old('employee_number', $employee->employee_number) }}">
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email', $employee->email) }}">
            <label for="department_id">部署</label>
            <select id="department_id" name="department_id">
                <option value="">未設定</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected((string) old('department_id', $employee->department_id) === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
            <label for="employment_status">雇用状態</label>
            <select id="employment_status" name="employment_status" required>
                @foreach (config('opsdeck.employee_statuses') as $status)
                    <option value="{{ $status }}" @selected(old('employment_status', $employee->employment_status) === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <label for="joined_at">入社日</label>
            <input id="joined_at" name="joined_at" type="date" value="{{ old('joined_at', $employee->joined_at?->format('Y-m-d')) }}">
            <label for="left_at">退職日</label>
            <input id="left_at" name="left_at" type="date" value="{{ old('left_at', $employee->left_at?->format('Y-m-d')) }}">
            <label for="job_title">役職</label>
            <input id="job_title" name="job_title" value="{{ old('job_title', $employee->job_title) }}">
            <label for="memo">メモ</label>
            <textarea id="memo" name="memo">{{ old('memo', $employee->memo) }}</textarea>
            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
