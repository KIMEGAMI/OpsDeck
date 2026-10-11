<x-layouts.app>
    <section class="card" aria-labelledby="contracts-title">
        <h1 id="contracts-title">契約</h1>
        @can('manageOperationalData', $organization)<p><a href="{{ route('contracts.create') }}">契約を登録する</a></p>@endcan
        <table><thead><tr><th>契約名</th><th>ベンダー</th><th>終了日</th><th>状態</th></tr></thead><tbody>@forelse ($contracts as $contract)<tr><td><a href="{{ route('contracts.show', $contract) }}">{{ $contract->contract_name }}</a></td><td>{{ $contract->vendor_name }}</td><td>{{ $contract->ends_at?->format('Y-m-d') ?? '未設定' }}</td><td>{{ $contract->status }}</td></tr>@empty<tr><td colspan="4">契約は未登録です。</td></tr>@endforelse</tbody></table>
        {{ $contracts->links() }}
    </section>
</x-layouts.app>
