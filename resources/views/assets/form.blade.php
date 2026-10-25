<x-layouts.app>
    <section class="card" aria-labelledby="asset-form-title">
        <h1 id="asset-form-title">{{ $asset->exists ? '端末を編集' : '端末を登録' }}</h1>
        @php
            $assetTypes = config('opsdeck.asset_types', []);
            $assetStatuses = config('opsdeck.asset_statuses', []);
            $assetTypeDefault = in_array($asset->asset_type, $assetTypes, true) ? $asset->asset_type : ($assetTypes[0] ?? null);
            $assetStatusDefault = in_array($asset->status, $assetStatuses, true) ? $asset->status : ($assetStatuses[0] ?? null);
            $selectedAssetType = in_array(old('asset_type'), $assetTypes, true) ? old('asset_type') : $assetTypeDefault;
            $selectedAssetStatus = in_array(old('status'), $assetStatuses, true) ? old('status') : $assetStatusDefault;
        @endphp
        <form method="post" action="{{ $formAction }}">
            @csrf
            @if ($formMethod !== 'POST')
                @method($formMethod)
            @endif

            <label for="asset_number">資産番号</label>
            <input id="asset_number" name="asset_number" value="{{ old('asset_number', $asset->asset_number) }}" required @error('asset_number') aria-invalid="true" aria-describedby="asset_number-error" @enderror>
            <x-field-error field="asset_number" />

            <label for="asset_type">種別</label>
            <select id="asset_type" name="asset_type" @error('asset_type') aria-invalid="true" aria-describedby="asset_type-error" @enderror>
                @foreach ($assetTypes as $type)
                    <option value="{{ $type }}" @selected($selectedAssetType === $type)>{{ config('opsdeck.asset_type_labels.'.$type, $type) }}</option>
                @endforeach
            </select>
            <x-field-error field="asset_type" />

            <label for="manufacturer">メーカー</label>
            <input id="manufacturer" name="manufacturer" value="{{ old('manufacturer', $asset->manufacturer) }}" @error('manufacturer') aria-invalid="true" aria-describedby="manufacturer-error" @enderror>
            <x-field-error field="manufacturer" />

            <label for="model">型番</label>
            <input id="model" name="model" value="{{ old('model', $asset->model) }}" @error('model') aria-invalid="true" aria-describedby="model-error" @enderror>
            <x-field-error field="model" />

            <label for="serial_number">シリアル番号</label>
            <input id="serial_number" name="serial_number" value="{{ old('serial_number', $asset->serial_number) }}" @error('serial_number') aria-invalid="true" aria-describedby="serial_number-error" @enderror>
            <x-field-error field="serial_number" />

            <label for="os">OS</label>
            <input id="os" name="os" value="{{ old('os', $asset->os) }}" @error('os') aria-invalid="true" aria-describedby="os-error" @enderror>
            <x-field-error field="os" />

            <label for="purchased_at">購入日</label>
            <input id="purchased_at" name="purchased_at" type="date" value="{{ old('purchased_at', $asset->purchased_at?->format('Y-m-d')) }}" @error('purchased_at') aria-invalid="true" aria-describedby="purchased_at-error" @enderror>
            <x-field-error field="purchased_at" />

            <label for="purchase_cost">購入金額</label>
            <input id="purchase_cost" name="purchase_cost" type="number" min="0" step="0.01" value="{{ old('purchase_cost', $asset->purchase_cost) }}" @error('purchase_cost') aria-invalid="true" aria-describedby="purchase_cost-error" @enderror>
            <x-field-error field="purchase_cost" />

            <label for="warranty_until">保証期限</label>
            <input id="warranty_until" name="warranty_until" type="date" value="{{ old('warranty_until', $asset->warranty_until?->format('Y-m-d')) }}" @error('warranty_until') aria-invalid="true" aria-describedby="warranty_until-error" @enderror>
            <x-field-error field="warranty_until" />

            <label for="status">状態</label>
            <select id="status" name="status" @error('status') aria-invalid="true" aria-describedby="status-error" @enderror>
                @foreach ($assetStatuses as $status)
                    <option value="{{ $status }}" @selected($selectedAssetStatus === $status)>{{ config('opsdeck.asset_status_labels.'.$status, $status) }}</option>
                @endforeach
            </select>
            <x-field-error field="status" />

            <label for="location">保管場所</label>
            <input id="location" name="location" value="{{ old('location', $asset->location) }}" @error('location') aria-invalid="true" aria-describedby="location-error" @enderror>
            <x-field-error field="location" />

            <label for="memo">メモ</label>
            <textarea id="memo" name="memo" @error('memo') aria-invalid="true" aria-describedby="memo-error" @enderror>{{ old('memo', $asset->memo) }}</textarea>
            <x-field-error field="memo" />

            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
