<x-layouts.app>
    <section class="card" aria-labelledby="contract-form-title">
        <h1 id="contract-form-title">{{ $contract->exists ? '契約を編集' : '契約を登録' }}</h1>
        @php
            $billingCycles = config('opsdeck.contract_billing_cycles', []);
            $contractStatuses = config('opsdeck.contract_statuses', []);
            $billingCycleDefault = in_array($contract->billing_cycle, $billingCycles, true) ? $contract->billing_cycle : ($billingCycles[0] ?? null);
            $contractStatusDefault = in_array($contract->status, $contractStatuses, true) ? $contract->status : ($contractStatuses[0] ?? null);
            $oldAutoRenew = old('auto_renew');
            $selectedAutoRenew = in_array($oldAutoRenew, [true, false, 1, 0, '1', '0'], true)
                ? (string) (int) $oldAutoRenew
                : (string) (int) ($contract->auto_renew ?? false);
            $selectedBillingCycle = in_array(old('billing_cycle'), $billingCycles, true) ? old('billing_cycle') : $billingCycleDefault;
            $selectedContractStatus = in_array(old('status'), $contractStatuses, true) ? old('status') : $contractStatusDefault;
        @endphp
        <form method="post" action="{{ $formAction }}">
            @csrf
            @if ($formMethod !== 'POST')
                @method($formMethod)
            @endif

            <label for="vendor_name">ベンダー名</label>
            <input id="vendor_name" name="vendor_name" value="{{ old('vendor_name', $contract->vendor_name) }}" required @error('vendor_name') aria-invalid="true" aria-describedby="vendor_name-error" @enderror>
            <x-field-error field="vendor_name" />

            <label for="contract_name">契約名</label>
            <input id="contract_name" name="contract_name" value="{{ old('contract_name', $contract->contract_name) }}" required @error('contract_name') aria-invalid="true" aria-describedby="contract_name-error" @enderror>
            <x-field-error field="contract_name" />

            <label for="contract_type">契約種別</label>
            <input id="contract_type" name="contract_type" value="{{ old('contract_type', $contract->contract_type) }}" required @error('contract_type') aria-invalid="true" aria-describedby="contract_type-error" @enderror>
            <x-field-error field="contract_type" />

            <label for="saas_service_id">紐付けるクラウドサービス</label>
            <select id="saas_service_id" name="saas_service_id" @error('saas_service_id') aria-invalid="true" aria-describedby="saas_service_id-error" @enderror>
                <option value="">紐付けない</option>
                @foreach ($services as $service)
                    <option value="{{ $service->id }}" @selected((string) old('saas_service_id', $contract->saas_service_id) === (string) $service->id)>{{ $service->name }}</option>
                @endforeach
            </select>
            <x-field-error field="saas_service_id" />

            <label for="owner_employee_id">社内の担当者</label>
            <select id="owner_employee_id" name="owner_employee_id" @error('owner_employee_id') aria-invalid="true" aria-describedby="owner_employee_id-error" @enderror>
                <option value="">担当者を選ばない</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}" @selected((string) old('owner_employee_id', $contract->owner_employee_id) === (string) $employee->id)>{{ $employee->name }}</option>
                @endforeach
            </select>
            <x-field-error field="owner_employee_id" />

            <label for="billing_cycle">請求周期</label>
            <select id="billing_cycle" name="billing_cycle" required @error('billing_cycle') aria-invalid="true" aria-describedby="billing_cycle-error" @enderror>
                @foreach ($billingCycles as $cycle)
                    <option value="{{ $cycle }}" @selected($selectedBillingCycle === $cycle)>{{ config('opsdeck.contract_billing_cycle_labels.'.$cycle, $cycle) }}</option>
                @endforeach
            </select>
            <x-field-error field="billing_cycle" />

            <label for="amount">金額</label>
            <input id="amount" name="amount" type="number" min="0" step="0.01" value="{{ old('amount', $contract->amount) }}" required @error('amount') aria-invalid="true" aria-describedby="amount-error" @enderror>
            <x-field-error field="amount" />

            <label for="currency">通貨</label>
            <input id="currency" name="currency" value="{{ old('currency', $contract->currency) }}" required @error('currency') aria-invalid="true" aria-describedby="currency-error" @enderror>
            <x-field-error field="currency" />

            <label for="license_quantity">利用ライセンス数</label>
            <input id="license_quantity" name="license_quantity" type="number" min="1" step="1" value="{{ old('license_quantity', $contract->license_quantity) }}" @error('license_quantity') aria-invalid="true" aria-describedby="license_quantity-error" @enderror>
            <x-field-error field="license_quantity" />

            <label for="started_at">契約開始日</label>
            <input id="started_at" name="started_at" type="date" value="{{ old('started_at', $contract->started_at?->format('Y-m-d')) }}" @error('started_at') aria-invalid="true" aria-describedby="started_at-error" @enderror>
            <x-field-error field="started_at" />

            <label for="ends_at">契約終了日</label>
            <input id="ends_at" name="ends_at" type="date" value="{{ old('ends_at', $contract->ends_at?->format('Y-m-d')) }}" @error('ends_at') aria-invalid="true" aria-describedby="ends_at-error" @enderror>
            <x-field-error field="ends_at" />

            <label for="renewal_date">次回更新日</label>
            <input id="renewal_date" name="renewal_date" type="date" value="{{ old('renewal_date', $contract->renewal_date?->format('Y-m-d')) }}" @error('renewal_date') aria-invalid="true" aria-describedby="renewal_date-error" @enderror>
            <x-field-error field="renewal_date" />
            <p class="muted">更新日が契約終了日と異なる場合に入力してください。</p>

            <label for="cancellation_deadline">解約申込期限</label>
            <input id="cancellation_deadline" name="cancellation_deadline" type="date" value="{{ old('cancellation_deadline', $contract->cancellation_deadline?->format('Y-m-d')) }}" @error('cancellation_deadline') aria-invalid="true" aria-describedby="cancellation_deadline-error" @enderror>
            <x-field-error field="cancellation_deadline" />

            <label for="auto_renew">自動更新</label>
            <select id="auto_renew" name="auto_renew" required @error('auto_renew') aria-invalid="true" aria-describedby="auto_renew-error" @enderror>
                <option value="1" @selected($selectedAutoRenew === '1')>あり</option>
                <option value="0" @selected($selectedAutoRenew === '0')>なし</option>
            </select>
            <x-field-error field="auto_renew" />

            <label for="status">状態</label>
            <select id="status" name="status" required @error('status') aria-invalid="true" aria-describedby="status-error" @enderror>
                @foreach ($contractStatuses as $status)
                    <option value="{{ $status }}" @selected($selectedContractStatus === $status)>{{ config('opsdeck.contract_status_labels.'.$status, $status) }}</option>
                @endforeach
            </select>
            <x-field-error field="status" />

            <label for="memo">メモ</label>
            <textarea id="memo" name="memo" @error('memo') aria-invalid="true" aria-describedby="memo-error" @enderror>{{ old('memo', $contract->memo) }}</textarea>
            <x-field-error field="memo" />

            <button type="submit">保存</button>
        </form>
    </section>
</x-layouts.app>
