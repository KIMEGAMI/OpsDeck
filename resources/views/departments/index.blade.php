<x-layouts.app>
    <section class="card" aria-labelledby="departments-title">
        <h1 id="departments-title">部署</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @can('manageOperationalData', $organization)<p><a href="{{ route('departments.create') }}">部署を登録する</a></p>@endcan
        <table>
            <thead><tr><th>部署名</th><th>親部署</th><th>状態</th><th></th></tr></thead>
            <tbody>
                @forelse ($departments as $department)
                    <tr>
                        <td>{{ $department->name }}</td><td>{{ $department->parent?->name ?? 'なし' }}</td><td>{{ $department->is_active ? '有効' : '無効' }}</td>
                        <td>@can('update', $department)<a href="{{ route('departments.edit', $department) }}">編集</a>@endcan</td>
                    </tr>
                @empty
                    <tr><td colspan="4">部署は未登録です。</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $departments->links() }}
    </section>
</x-layouts.app>
