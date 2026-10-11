<x-layouts.app>
    <section class="card" aria-labelledby="employee-title">
        <h1 id="employee-title">{{ $employee->name }}</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <dl>
            <dt>社員番号</dt><dd>{{ $employee->employee_number ?? '未設定' }}</dd>
            <dt>メールアドレス</dt><dd>{{ $employee->email ?? '未設定' }}</dd>
            <dt>部署</dt><dd>{{ $employee->department?->name ?? '未設定' }}</dd>
            <dt>雇用状態</dt><dd>{{ $employee->employment_status }}</dd>
        </dl>
        @can('update', $employee)<p><a href="{{ route('employees.edit', $employee) }}">編集する</a></p>@endcan
        <p><a href="{{ route('employees.index') }}">社員一覧へ戻る</a></p>
    </section>
</x-layouts.app>
