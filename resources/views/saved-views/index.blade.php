<x-layouts.app>
    <section class="card">
        <h1>定型ビュー</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p class="muted">更新期限、未割当資産、MFA未確認を保存した条件で確認できます。</p>
        @if (request()->attributes->get('currentMembership')?->role?->value !== 'viewer')<p><a href="{{ route('saved-views.create') }}">定型ビューを作成</a></p>@endif
        <ul>
            @forelse ($views as $view)
                <li><a href="{{ route('saved-views.show', $view) }}">{{ $view->name }}</a>（{{ config('opsdeck.saved_view_types.'.$view->view_key) }}{{ $view->is_shared ? '・共有' : '・個人' }}）</li>
            @empty
                <li>保存された定型ビューはありません。</li>
            @endforelse
        </ul>
        {{ $views->links() }}
    </section>
</x-layouts.app>
