<x-layouts.app>
    <section class="card" aria-labelledby="employees-title">
        <h1 id="employees-title">社員</h1>
        <form method="get" action="{{ route('employees.index') }}">
            <label for="search">氏名・社員番号・メールアドレス</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}">
            <button type="submit">検索</button>
        </form>
        @can('manageOperationalData', $organization)
            <p><a href="{{ route('employees.create') }}">社員を登録する</a></p>
        @endcan
        <table>
            <thead><tr><th>氏名</th><th>部署</th><th>状態</th></tr></thead>
            <tbody>
                @forelse ($employees as $employee)
                    <tr>
                        <td><a href="{{ route('employees.show', $employee) }}">{{ $employee->name }}</a></td>
                        <td>{{ $employee->department?->name ?? '未設定' }}</td>
                        <td>{{ $employee->employment_status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">該当する社員はいません。</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $employees->links() }}
    </section>
</x-layouts.app>
