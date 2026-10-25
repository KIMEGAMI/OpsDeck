<x-layouts.app>
    <section class="card" aria-labelledby="contract-title">
        <h1 id="contract-title">{{ $contract->contract_name }}</h1>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <dl>
            <dt>ベンダー</dt><dd>{{ $contract->vendor_name }}</dd>
            <dt>契約種別</dt><dd>{{ $contract->contract_type }}</dd>
            <dt>クラウドサービス</dt><dd>{{ $contract->service?->name ?? '紐付けなし' }}</dd>
            <dt>社内の担当者</dt><dd>{{ $contract->owner?->name ?? '未設定' }}</dd>
            <dt>請求周期</dt><dd>{{ config('opsdeck.contract_billing_cycle_labels.'.$contract->billing_cycle, $contract->billing_cycle) }}</dd>
            <dt>金額</dt><dd>{{ $contract->amount }} {{ $contract->currency }}</dd>
            <dt>利用ライセンス数</dt><dd>{{ $contract->license_quantity ?? '未設定' }}</dd>
            <dt>契約開始日</dt><dd>{{ $contract->started_at?->format('Y-m-d') ?? '未設定' }}</dd>
            <dt>契約終了日</dt><dd>{{ $contract->ends_at?->format('Y-m-d') ?? '未設定' }}</dd>
            <dt>次回更新日</dt><dd>{{ $contract->renewal_date?->format('Y-m-d') ?? '未設定' }}</dd>
            <dt>解約申込期限</dt><dd>{{ $contract->cancellation_deadline?->format('Y-m-d') ?? '未設定' }}</dd>
            <dt>自動更新</dt><dd>{{ $contract->auto_renew ? 'あり' : 'なし' }}</dd>
            <dt>状態</dt><dd>{{ config('opsdeck.contract_status_labels.'.$contract->status, $contract->status) }}</dd>
            <dt>メモ</dt><dd>{{ $contract->memo ?: '未設定' }}</dd>
        </dl>
        @can('update', $contract)
            <p><a href="{{ route('contracts.edit', $contract) }}">編集する</a></p>
        @endcan
        <p><a href="{{ route('contracts.index') }}">契約一覧へ戻る</a></p>
    </section>
</x-layouts.app>
