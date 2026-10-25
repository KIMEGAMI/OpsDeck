<x-layouts.app>
    <section class="card" aria-labelledby="saas-service-form-title">
        <h1 id="saas-service-form-title">{{ $service->exists ? 'クラウドサービスを編集' : 'クラウドサービスを登録' }}</h1>
        @php
            $serviceStatuses = config('opsdeck.saas_service_statuses', []);
            $serviceStatusDefault = in_array($service->status, $serviceStatuses, true) ? $service->status : ($serviceStatuses[0] ?? null);
            $selectedServiceStatus = in_array(old('status'), $serviceStatuses, true) ? old('status') : $serviceStatusDefault;
        @endphp
        <form method="post" action="{{ $formAction }}">
            @csrf
            @if ($formMethod !== 'POST')
                @method($formMethod)
            @endif

            <label for="name">サービス名</label>
            <input id="name" name="name" value="{{ old('name', $service->name) }}" required @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
            <x-field-error field="name" />

            <label for="vendor">提供会社（ベンダー）</label>
            <input id="vendor" name="vendor" value="{{ old('vendor', $service->vendor) }}" @error('vendor') aria-invalid="true" aria-describedby="vendor-error" @enderror>
            <x-field-error field="vendor" />

            <label for="category">分類</label>
            <input id="category" name="category" value="{{ old('category', $service->category) }}" @error('category') aria-invalid="true" aria-describedby="category-error" @enderror>
            <x-field-error field="category" />

            <label for="website_url">Webサイト</label>
            <input id="website_url" name="website_url" type="url" value="{{ old('website_url', $service->website_url) }}" @error('website_url') aria-invalid="true" aria-describedby="website_url-error" @enderror>
            <x-field-error field="website_url" />

            <label for="status">利用状況</label>
            <select id="status" name="status" @error('status') aria-invalid="true" aria-describedby="status-error" @enderror>
                @foreach ($serviceStatuses as $status)
                    <option value="{{ $status }}" @selected($selectedServiceStatus === $status)>{{ config('opsdeck.saas_service_status_labels.'.$status, $status) }}</option>
                @endforeach
            </select>
            <x-field-error field="status" />

            <label for="memo">メモ</label>
            <textarea id="memo" name="memo" @error('memo') aria-invalid="true" aria-describedby="memo-error" @enderror>{{ old('memo', $service->memo) }}</textarea>
            <x-field-error field="memo" />

            <button type="submit">保存する</button>
        </form>
    </section>
</x-layouts.app>
