<x-layouts.app>
    <section class="card" aria-labelledby="department-form-title">
        <h1 id="department-form-title">{{ $department->exists ? '部署を編集' : '部署を登録' }}</h1>
        <form method="post" action="{{ $formAction }}">
            @csrf
            @if ($formMethod !== 'POST') @method($formMethod) @endif
            <label for="name">部署名</label>
            <input id="name" name="name" value="{{ old('name', $department->name) }}" required @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
            <x-field-error field="name" />

            <label for="code">部署コード</label>
            <input id="code" name="code" value="{{ old('code', $department->code) }}" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
            <x-field-error field="code" />

            <label for="parent_id">親部署</label>
            <select id="parent_id" name="parent_id" @error('parent_id') aria-invalid="true" aria-describedby="parent_id-error" @enderror>
                <option value="">なし</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected((string) old('parent_id', $department->parent_id) === (string) $parent->id)>{{ $parent->name }}</option>
                @endforeach
            </select>
            <x-field-error field="parent_id" />

            <label>
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" style="width:auto" @checked(old('is_active', $department->is_active)) @error('is_active') aria-invalid="true" aria-describedby="is_active-error" @enderror>
                有効
            </label>
            <x-field-error field="is_active" />
            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
