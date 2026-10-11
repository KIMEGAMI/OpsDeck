<x-layouts.app>
    <section class="card" aria-labelledby="settings-title">
        <h1 id="settings-title">組織設定</h1>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <form method="post" action="{{ route('settings.organization.update') }}">
            @csrf
            @method('put')
            <label for="timezone">タイムゾーン</label>
            <input id="timezone" name="timezone" value="{{ old('timezone', $organization->timezone) }}" required>
            <label for="company_size">会社規模</label>
            <input id="company_size" name="company_size" type="number" min="1" value="{{ old('company_size', $organization->company_size) }}">
            <label><input name="ai_enabled" type="hidden" value="0"><input name="ai_enabled" type="checkbox" value="1" style="width:auto" @checked(old('ai_enabled', $organization->ai_enabled))> AI補助を有効にする</label>
            <h2>削減見積の会社別前提</h2>
            <p class="muted">未設定の場合は共通初期値（更新見直し率{{ config('opsdeck.cost_savings.renewal_negotiation_rate') * 100 }}%、未使用端末{{ number_format(config('opsdeck.cost_savings.unused_asset_monthly_jpy')) }}円/月）を使います。実績値ではなく見積用の仮定です。</p>
            <label for="cost_savings_renewal_rate_percent">更新時の見直し率（%）</label>
            <input id="cost_savings_renewal_rate_percent" name="cost_savings_renewal_rate_percent" type="number" min="0" max="{{ config('opsdeck.cost_savings.organization_renewal_rate_percent_max') }}" step="1" value="{{ old('cost_savings_renewal_rate_percent', $organization->cost_savings_renewal_rate_percent) }}">
            <p class="muted">契約更新時に交渉・見直しできると見込む割合。設定しない場合は共通初期値を使用します。</p>
            <label for="cost_savings_unused_asset_monthly_jpy">未使用端末1台あたりの解約可能な月額費用（円）</label>
            <input id="cost_savings_unused_asset_monthly_jpy" name="cost_savings_unused_asset_monthly_jpy" type="number" min="0" max="{{ config('opsdeck.cost_savings.organization_unused_asset_monthly_jpy_max') }}" step="1" value="{{ old('cost_savings_unused_asset_monthly_jpy', $organization->cost_savings_unused_asset_monthly_jpy) }}">
            <p class="muted">入力対象はリース料・保守費など、未使用端末を整理すれば実際に止められる月額費用です。購入価格や簿価は含めません。空欄にすると共通初期値に戻ります。</p>
            <button type="submit">保存</button>
        </form>

        <h2>プラン利用状況</h2>
        <p class="muted">現在の契約プランに応じた利用上限です。上限に近づいた場合は、不要なデータの整理または契約プランの見直しをご検討ください。</p>
        <table>
            <thead>
                <tr><th scope="col">項目</th><th scope="col">利用数</th><th scope="col">上限</th><th scope="col">利用率</th><th scope="col">状態</th></tr>
            </thead>
            <tbody>
                @foreach ($limits as $limit)
                    <tr>
                        <th scope="row">{{ $limit['label'] }}</th>
                        <td>{{ number_format($limit['current']) }}</td>
                        <td>{{ number_format($limit['limit']) }}</td>
                        <td><span role="meter" aria-label="{{ $limit['label'] }}の利用率" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $limit['percentage']) }}">{{ $limit['percentage'] }}%</span></td>
                        <td>{{ $limit['status'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-layouts.app>
