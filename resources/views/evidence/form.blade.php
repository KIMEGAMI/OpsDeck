<x-layouts.app>
    <section class="card">
        <h1>{{ $evidence->exists ? '証跡を編集' : '証跡を登録' }}</h1>
        @if (! $evidence->exists && isset($storageQuota))
            <x-storage-quota :quota="$storageQuota" />
        @endif
        <form method="post" action="{{ $formAction }}" enctype="multipart/form-data">
            @csrf
            @if ($formMethod !== 'POST')
                @method($formMethod)
            @endif
            <label for="title">タイトル</label>
            <input id="title" name="title" value="{{ old('title', $evidence->title) }}" required @error('title') aria-invalid="true" aria-describedby="title-error" @enderror>
            <x-field-error field="title" />

            <label for="evidence_type">種別</label>
            <input id="evidence_type" name="evidence_type" value="{{ old('evidence_type', $evidence->evidence_type) }}" required @error('evidence_type') aria-invalid="true" aria-describedby="evidence_type-error" @enderror>
            <x-field-error field="evidence_type" />

            @if (! $evidence->exists)
                <label for="file">ファイル</label>
                <input id="file" name="file" type="file" required @error('file') aria-invalid="true" aria-describedby="file-help file-error" @else aria-describedby="file-help" @enderror>
                <small id="file-help">エラーが出た場合は、安全のためファイルを再選択してください。</small>
                <x-field-error field="file" />
            @endif

            <label for="description">説明（最大{{ number_format((int) config('opsdeck.evidence.description_max_length')) }}文字）</label>
            <textarea id="description" name="description" maxlength="{{ (int) config('opsdeck.evidence.description_max_length') }}" @error('description') aria-invalid="true" aria-describedby="description-error" @enderror>{{ old('description', $evidence->description) }}</textarea>
            <x-field-error field="description" />

            <label for="valid_until">有効期限</label>
            <input id="valid_until" name="valid_until" type="date" value="{{ old('valid_until', $evidence->valid_until?->format('Y-m-d')) }}" @error('valid_until') aria-invalid="true" aria-describedby="valid_until-error" @enderror>
            <x-field-error field="valid_until" />
            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
