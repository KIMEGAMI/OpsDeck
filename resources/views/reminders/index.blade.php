<x-layouts.app>
    <section class="card">
        <h1>契約更新リマインダー</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @foreach (['overdue' => '期限超過', 'within_30_days' => \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS.'日以内', 'within_90_days' => (\App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS + 1).'〜'.\App\Services\ContractRenewalWindow::SAVINGS_WINDOW_DAYS.'日以内'] as $key => $label)
            <h2>{{ $label }}</h2>
            <table><thead><tr><th>契約</th><th>ベンダー</th><th>期限の種類</th><th>期限日</th></tr></thead><tbody>
            @forelse ($reminders[$key] as $contract)
                <tr><td><a href="{{ route('contracts.show', $contract) }}">{{ $contract->contract_name }}</a></td><td>{{ $contract->vendor_name }}</td><td>{{ $contract->reminder_date_label }}</td><td>{{ $contract->reminder_date->format('Y-m-d') }}</td></tr>
            @empty
                <tr><td colspan="4">対象契約はありません。</td></tr>
            @endforelse
            </tbody></table>
        @endforeach
        @if ($canConfirmOnboarding)
            @if ($renewalChecked)
                <p role="status">更新期限は確認済みです。</p>
            @else
                <form method="post" action="{{ route('onboarding.steps.renewal-check') }}">
                    @csrf
                    <button type="submit">更新期限を確認済みにする</button>
                </form>
            @endif
        @endif
    </section>
</x-layouts.app>
