<x-layouts.app>
    <section class="card">
        <h1>定型ビュー</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p class="muted">更新期限、未割当の端末、ログイン時の追加確認が済んでいないクラウドサービスのアカウントを、保存した条件で確認できます。</p>
        @if (request()->attributes->get('currentMembership')?->role?->value !== 'viewer')<p><a href="{{ route('saved-views.create') }}">定型ビューを作成</a></p>@endif
        <ul>
            @forelse ($views as $view)
                <li>
                    <a href="{{ route('saved-views.show', $view) }}">{{ $view->name }}</a>（{{ config('opsdeck.saved_view_types.'.$view->view_key) }}{{ $view->is_shared ? '・共有' : '・個人' }}）
                    @if ($view->is_shared ? in_array(request()->attributes->get('currentMembership')?->role?->value, ['owner', 'admin'], true) : $view->created_by === auth()->id())
                        <form method="post" action="{{ route('saved-views.destroy', $view) }}" style="display:inline" onsubmit="return confirm('この定型ビューを削除しますか？')">
                            @csrf
                            @method('delete')
                            <button type="submit" aria-label="{{ $view->name }}を削除">削除</button>
                        </form>
                    @endif
                </li>
            @empty
                <li>保存された定型ビューはありません。</li>
            @endforelse
        </ul>
        {{ $views->links() }}
    </section>
</x-layouts.app>
