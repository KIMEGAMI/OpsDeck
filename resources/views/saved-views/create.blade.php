<x-layouts.app>
    <section class="card">
        <h1>定型ビューを作成</h1>
        <p>よく確認する一覧の条件を保存します。作成しても契約や資産のデータは変更されません。</p>
        @if (in_array(request()->attributes->get('currentMembership')?->role?->value, ['owner', 'admin'], true))
            <p class="muted">組織内で共有すると、同じ組織のメンバー全員がこの一覧を見られます。</p>
        @else
            <p class="muted">作成した一覧は自分だけが見られます。組織内共有はOwner/Adminが設定できます。</p>
        @endif
        <form method="post" action="{{ route('saved-views.store') }}">
            @csrf
            <label for="name">表示名</label>
            <input id="name" name="name" value="{{ old('name') }}" maxlength="120" required placeholder="例：今月更新する契約" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
            <x-field-error field="name" />

            <label for="view_key">確認する一覧</label>
            <select id="view_key" name="view_key" required @error('view_key') aria-invalid="true" aria-describedby="view_key-error" @enderror>
                @foreach ($viewTypes as $key => $label)
                    <option value="{{ $key }}" @selected(old('view_key') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <x-field-error field="view_key" />

            @if (in_array(request()->attributes->get('currentMembership')?->role?->value, ['owner', 'admin'], true))
                <label>
                    <input type="checkbox" name="is_shared" value="1" @checked(old('is_shared'))>
                    組織内で共有する
                </label>
            @endif
            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
