<x-layouts.app>
    <section class="card" aria-labelledby="asset-form-title">
        <h1 id="asset-form-title">{{ $asset->exists ? '端末を編集' : '端末を登録' }}</h1>
        <form method="post" action="{{ $formAction }}">@csrf @if ($formMethod !== 'POST') @method($formMethod) @endif
            <label for="asset_number">資産番号</label><input id="asset_number" name="asset_number" value="{{ old('asset_number', $asset->asset_number) }}" required>
            <label for="asset_type">種別</label><select id="asset_type" name="asset_type">@foreach (config('opsdeck.asset_types') as $type)<option value="{{ $type }}" @selected(old('asset_type', $asset->asset_type) === $type)>{{ $type }}</option>@endforeach</select>
            <label for="manufacturer">メーカー</label><input id="manufacturer" name="manufacturer" value="{{ old('manufacturer', $asset->manufacturer) }}">
            <label for="model">型番</label><input id="model" name="model" value="{{ old('model', $asset->model) }}">
            <label for="serial_number">シリアル番号</label><input id="serial_number" name="serial_number" value="{{ old('serial_number', $asset->serial_number) }}">
            <label for="os">OS</label><input id="os" name="os" value="{{ old('os', $asset->os) }}">
            <label for="purchased_at">購入日</label><input id="purchased_at" name="purchased_at" type="date" value="{{ old('purchased_at', $asset->purchased_at?->format('Y-m-d')) }}">
            <label for="purchase_cost">購入金額</label><input id="purchase_cost" name="purchase_cost" type="number" min="0" step="0.01" value="{{ old('purchase_cost', $asset->purchase_cost) }}">
            <label for="warranty_until">保証期限</label><input id="warranty_until" name="warranty_until" type="date" value="{{ old('warranty_until', $asset->warranty_until?->format('Y-m-d')) }}">
            <label for="status">状態</label><select id="status" name="status">@foreach (config('opsdeck.asset_statuses') as $status)<option value="{{ $status }}" @selected(old('status', $asset->status) === $status)>{{ $status }}</option>@endforeach</select>
            <label for="location">保管場所</label><input id="location" name="location" value="{{ old('location', $asset->location) }}">
            <label for="memo">メモ</label><textarea id="memo" name="memo">{{ old('memo', $asset->memo) }}</textarea>
            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
