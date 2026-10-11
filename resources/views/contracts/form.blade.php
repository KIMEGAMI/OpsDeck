<x-layouts.app>
    <section class="card"><h1>{{ $contract->exists ? '契約を編集' : '契約を登録' }}</h1><form method="post" action="{{ $formAction }}">@csrf @if ($formMethod !== 'POST') @method($formMethod) @endif
        <label for="vendor_name">ベンダー名</label><input id="vendor_name" name="vendor_name" value="{{ old('vendor_name', $contract->vendor_name) }}" required>
        <label for="contract_name">契約名</label><input id="contract_name" name="contract_name" value="{{ old('contract_name', $contract->contract_name) }}" required>
        <label for="contract_type">契約種別</label><input id="contract_type" name="contract_type" value="{{ old('contract_type', $contract->contract_type) }}" required>
        <label for="saas_service_id">SaaS</label><select id="saas_service_id" name="saas_service_id"><option value="">紐付けない</option>@foreach ($services as $service)<option value="{{ $service->id }}" @selected((string) old('saas_service_id', $contract->saas_service_id) === (string) $service->id)>{{ $service->name }}</option>@endforeach</select>
        <label for="billing_cycle">請求周期</label><select id="billing_cycle" name="billing_cycle" required>@foreach (config('opsdeck.contract_billing_cycles') as $cycle)<option value="{{ $cycle }}" @selected(old('billing_cycle', $contract->billing_cycle) === $cycle)>{{ $cycle }}</option>@endforeach</select>
        <label for="amount">金額</label><input id="amount" name="amount" type="number" min="0" step="0.01" value="{{ old('amount', $contract->amount) }}" required>
        <label for="currency">通貨</label><input id="currency" name="currency" value="{{ old('currency', $contract->currency) }}" required>
        <label for="started_at">開始日</label><input id="started_at" name="started_at" type="date" value="{{ old('started_at', $contract->started_at?->format('Y-m-d')) }}">
        <label for="ends_at">終了日</label><input id="ends_at" name="ends_at" type="date" value="{{ old('ends_at', $contract->ends_at?->format('Y-m-d')) }}">
        <label for="auto_renew">自動更新</label><select id="auto_renew" name="auto_renew" required><option value="1" @selected((bool) old('auto_renew', $contract->auto_renew))>あり</option><option value="0" @selected(! (bool) old('auto_renew', $contract->auto_renew))>なし</option></select>
        <label for="status">状態</label><select id="status" name="status" required>@foreach (config('opsdeck.contract_statuses') as $status)<option value="{{ $status }}" @selected(old('status', $contract->status) === $status)>{{ $status }}</option>@endforeach</select>
        <label for="memo">メモ</label><textarea id="memo" name="memo">{{ old('memo', $contract->memo) }}</textarea><button type="submit">保存</button></form></section>
</x-layouts.app>
