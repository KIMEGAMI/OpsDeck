<x-layouts.app>
    <section class="card">
        <h1>定型ビューを作成</h1>
        <form method="post" action="{{ route('saved-views.store') }}">
            @csrf
            <label for="name">表示名</label>
            <input id="name" name="name" value="{{ old('name') }}" maxlength="120" required>
            <label for="view_key">条件</label>
            <select id="view_key" name="view_key" required>
                @foreach ($viewTypes as $key => $label)<option value="{{ $key }}" @selected(old('view_key') === $key)>{{ $label }}</option>@endforeach
            </select>
            @if (in_array(request()->attributes->get('currentMembership')?->role?->value, ['owner', 'admin'], true))
                <label><input type="checkbox" name="is_shared" value="1" @checked(old('is_shared'))> 組織内で共有する</label>
            @endif
            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
