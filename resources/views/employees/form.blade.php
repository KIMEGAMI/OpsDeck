<x-layouts.app>
    <section class="card" aria-labelledby="employee-form-title">
        <h1 id="employee-form-title">{{ $employee->exists ? '社員情報を編集' : '社員を登録' }}</h1>
        @php
            $employeeStatuses = config('opsdeck.employee_statuses', []);
            $employeeStatusDefault = in_array($employee->employment_status, $employeeStatuses, true) ? $employee->employment_status : ($employeeStatuses[0] ?? null);
            $selectedEmploymentStatus = in_array(old('employment_status'), $employeeStatuses, true) ? old('employment_status') : $employeeStatusDefault;
            $oldDepartmentId = old('department_id', $employee->department_id);
            $selectedDepartmentId = is_string($oldDepartmentId) || is_int($oldDepartmentId) ? (string) $oldDepartmentId : '';
        @endphp
        <form method="post" action="{{ $formAction }}">
            @csrf
            @if ($formMethod !== 'POST') @method($formMethod) @endif
            <label for="name">氏名</label>
            <input id="name" name="name" value="{{ old('name', $employee->name) }}" required @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
            <x-field-error field="name" />
            <label for="employee_number">社員番号</label>
            <input id="employee_number" name="employee_number" value="{{ old('employee_number', $employee->employee_number) }}" @error('employee_number') aria-invalid="true" aria-describedby="employee_number-error" @enderror>
            <x-field-error field="employee_number" />
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email', $employee->email) }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            <x-field-error field="email" />
            <label for="department_id">部署</label>
            <select id="department_id" name="department_id" @error('department_id') aria-invalid="true" aria-describedby="department_id-error" @enderror>
                <option value="" @selected($selectedDepartmentId === '')>未設定</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected($selectedDepartmentId === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
            <x-field-error field="department_id" />
            <label for="employment_status">雇用状態</label>
            <select id="employment_status" name="employment_status" required @error('employment_status') aria-invalid="true" aria-describedby="employment_status-error" @enderror>
                @foreach ($employeeStatuses as $status)
                    <option value="{{ $status }}" @selected($selectedEmploymentStatus === $status)>{{ config('opsdeck.employee_status_labels.'.$status, $status) }}</option>
                @endforeach
            </select>
            <x-field-error field="employment_status" />
            <label for="joined_at">入社日</label>
            <input id="joined_at" name="joined_at" type="date" value="{{ old('joined_at', $employee->joined_at?->format('Y-m-d')) }}" @error('joined_at') aria-invalid="true" aria-describedby="joined_at-error" @enderror>
            <x-field-error field="joined_at" />
            <label for="left_at">退職日</label>
            <input id="left_at" name="left_at" type="date" value="{{ old('left_at', $employee->left_at?->format('Y-m-d')) }}" @error('left_at') aria-invalid="true" aria-describedby="left_at-error" @enderror>
            <x-field-error field="left_at" />
            <label for="job_title">役職</label>
            <input id="job_title" name="job_title" value="{{ old('job_title', $employee->job_title) }}" @error('job_title') aria-invalid="true" aria-describedby="job_title-error" @enderror>
            <x-field-error field="job_title" />
            <label for="memo">メモ</label>
            <textarea id="memo" name="memo" @error('memo') aria-invalid="true" aria-describedby="memo-error" @enderror>{{ old('memo', $employee->memo) }}</textarea>
            <x-field-error field="memo" />
            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
