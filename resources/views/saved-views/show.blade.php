<x-layouts.app>
    <section class="card">
        <h1>{{ $savedView->name }}</h1>
        <p class="muted">条件：{{ $label }} / {{ $rows->total() }}件</p>
        @forelse ($rows as $row)
            <article style="border-top:1px solid #bcccdc;padding:.75rem 0">
                @if ($savedView->view_key === 'renewals')<strong>{{ $row->contract_name }}</strong>（{{ $row->vendor_name }}）<br>更新日：{{ $row->renewal_date?->toDateString() ?? $row->ends_at?->toDateString() ?? '未設定' }}
                @elseif ($savedView->view_key === 'unassigned_assets')<strong>{{ $row->asset_number }}</strong>（{{ $row->manufacturer }} {{ $row->model }}）
                @else<strong>アカウント：{{ $row->account_identifier ?? '未設定' }}</strong>（追加のログイン確認：{{ config('opsdeck.saas_assignment_mfa_status_labels.'.$row->mfa_status, '状態を確認してください') }}）@endif
            </article>
        @empty
            <p>該当する項目はありません。</p>
        @endforelse
        {{ $rows->links() }}
    </section>
</x-layouts.app>
