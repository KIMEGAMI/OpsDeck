<x-layouts.app>
    <section class="card">
        <h1>コストダッシュボード</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($savingsOpportunitiesAvailable)
            <section class="card" aria-labelledby="savings-estimate-title">
                <h2 id="savings-estimate-title">削減可能額の概算（年間）</h2>
                <p><strong>{{ number_format($savingsEstimate['annual_total_jpy']) }}円</strong></p>
                <ul>
                    <li>更新時の見直し余地：{{ number_format($savingsEstimate['renewal_negotiation_jpy']) }}円</li>
                    <li>未使用SaaSの見直し余地：{{ number_format($savingsEstimate['unused_saas_jpy']) }}円</li>
                    <li>未使用端末の見直し余地：{{ number_format($savingsEstimate['unused_assets_jpy']) }}円</li>
                </ul>
                @if ($savingsEstimate['renewal_candidates'] !== [])
                    <h3>更新交渉の候補契約（{{ $savingsEstimate['renewal_candidate_count'] }}件）</h3>
                    <p class="muted">90日以内に更新・終了予定の、自動更新が有効で解約予定ではないJPY継続契約を表示しています。年間コストに会社別または共通の見直し率を適用した概算です。契約変更・解約は行わず、条件と更新日を担当者が確認してください。</p>
                    <table>
                        <thead><tr><th scope="col">契約</th><th scope="col">更新・終了予定日</th><th scope="col">年間コスト</th><th scope="col">見直し余地の概算</th></tr></thead>
                        <tbody>
                            @foreach ($savingsEstimate['renewal_candidates'] as $candidate)
                                <tr>
                                    <td><a href="{{ route('contracts.show', $candidate['contract_id']) }}">{{ $candidate['vendor_name'] }} — {{ $candidate['contract_name'] }}</a></td>
                                    <td>{{ $candidate['renewal_date'] }}</td>
                                    <td>JPY {{ number_format($candidate['annual_cost_jpy'], 2) }}</td>
                                    <td>{{ number_format($candidate['estimated_savings_jpy']) }}円</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($savingsEstimate['renewal_candidate_count'] > count($savingsEstimate['renewal_candidates']))
                        <p><a href="{{ route('contracts.index') }}">契約一覧ですべて確認</a></p>
                    @endif
                @endif
                <p class="muted">これは登録データと仮定から計算した概算です。実際の削減額ではありません。更新見直し率{{ $savingsEstimate['assumptions']['renewal_rate'] * 100 }}%（{{ $savingsEstimate['assumptions']['renewal_rate_source'] === 'organization' ? '会社別設定' : '共通初期値' }}）、対象端末（{{ implode('・', $savingsEstimate['assumptions']['unused_asset_statuses']) }}）{{ number_format($savingsEstimate['assumptions']['unused_asset_monthly_jpy']) }}円/月（{{ $savingsEstimate['assumptions']['unused_asset_monthly_jpy_source'] === 'organization' ? '会社別設定' : '共通初期値' }}）として算出しています。<a href="{{ route('settings.organization') }}">会社別前提を設定</a></p>
                @foreach ($savingsEstimate['assumptions']['warnings'] as $warning)
                    <p class="errors" role="alert">{{ $warning }} 設定を確認してください。</p>
                @endforeach
                @if ($savingsEstimate['assumptions']['unconverted_foreign_contracts_by_currency'] !== [])
                    <p class="muted">為替レート未設定のため概算に含めていない外貨継続契約：@foreach ($savingsEstimate['assumptions']['unconverted_foreign_contracts_by_currency'] as $currency => $count){{ $currency }}：{{ $count }}件@if (! $loop->last)、@endif @endforeach</p>
                @endif
                @if ($savingsEstimate['unused_saas_candidates'] !== [])
                    <h3>未使用SaaS候補の内訳</h3>
                    <p class="muted">割当のないサービスに紐づく、解約予定ではないJPYの月次・年次契約を表示しています。利用停止や解約は行わず、契約内容を担当者が確認してください。</p>
                    <table>
                        <thead><tr><th scope="col">サービス</th><th scope="col">対象契約</th><th scope="col">年間コストの概算</th></tr></thead>
                        <tbody>
                            @foreach ($savingsEstimate['unused_saas_candidates'] as $candidate)
                                <tr>
                                    <td><a href="{{ route('saas-services.show', $candidate['service_id']) }}">{{ $candidate['service_name'] }}</a></td>
                                    <td>{{ implode('、', $candidate['contract_names']) }}</td>
                                    <td>JPY {{ number_format($candidate['annual_cost_jpy'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if ($savingsEstimate['unused_asset_candidates'] !== [])
                    <h3>未使用端末候補の内訳（{{ $savingsEstimate['unused_asset_candidate_count'] }}台）</h3>
                    <p class="muted">設定対象の状態で、未返却の割当がない端末です。月額前提に基づく概算で、廃棄や割当解除は行いません。担当者が端末の所在・稼働状況を確認してください。</p>
                    <table>
                        <thead><tr><th scope="col">資産番号</th><th scope="col">型番</th><th scope="col">状態</th><th scope="col">年間コストの概算</th></tr></thead>
                        <tbody>
                            @foreach ($savingsEstimate['unused_asset_candidates'] as $candidate)
                                <tr>
                                    <td><a href="{{ route('assets.show', $candidate['asset_id']) }}">{{ $candidate['asset_number'] }}</a></td>
                                    <td>{{ $candidate['model'] ?: '未設定' }}</td>
                                    <td>{{ config('opsdeck.cost_savings.unused_asset_statuses.'.$candidate['status'], $candidate['status']) }}</td>
                                    <td>{{ number_format($candidate['annual_cost_jpy']) }}円</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($savingsEstimate['unused_asset_candidate_count'] > count($savingsEstimate['unused_asset_candidates']))
                        <p><a href="{{ route('assets.index', ['statuses' => array_keys(config('opsdeck.cost_savings.unused_asset_statuses', [])), 'unassigned' => 1]) }}">対象端末をすべて表示</a></p>
                    @endif
                @endif
            </section>
        @else
            <section class="card" aria-labelledby="premium-cost-features-title">
                <h2 id="premium-cost-features-title">削減候補と更新アラート</h2>
                <p class="muted">Freeプランでは月次コストを確認できます。削減可能額の概算、削減実績の記録、更新アラートはPremiumプランで利用できます。</p>
                @can('manageBilling', $organization)
                    <p><a href="{{ route('billing.index') }}">Free / Premiumの機能を確認</a></p>
                @else
                    <p class="muted">Premium契約の相談は組織Ownerにご依頼ください。</p>
                @endcan
            </section>
        @endif
        <h2>月次ランニングコスト</h2>
        <dl>
            @forelse ($monthlyCosts as $currency => $cents)
                <dt>{{ $currency }}</dt><dd>{{ number_format($cents / 100, 2) }}</dd>
            @empty
                <dt>対象契約</dt><dd>ありません</dd>
            @endforelse
        </dl>
        @if ($realizedSavingsAvailable)
        <section class="card" aria-labelledby="realized-savings-title">
            <h2 id="realized-savings-title">記録された削減実績</h2>
            <p class="muted">担当者が実現済みとして記録した金額です。根拠の参照情報を保存しますが、OpsDeckが請求書や社内資料を自動検証するものではありません。通貨と期間ごとに集計し、異なる通貨・期間を合算しません。</p>
            @if ($realizedSavingsTotals->isNotEmpty())
                <ul>
                    @foreach ($realizedSavingsTotals as $total)
                        <li>{{ $total->currency }}（{{ config('opsdeck.cost_savings.realized_periods.'.$total->period, $total->period) }}）：{{ number_format((float) $total->amount_total, 2) }}</li>
                    @endforeach
                </ul>
            @else
                <p>記録された削減実績はありません。</p>
            @endif
            @can('manageOperationalData', $organization)
                <h3>削減実績を記録</h3>
                <form method="post" action="{{ route('realized-savings.store') }}">
                    @csrf
                    <label for="saving_description">削減内容</label>
                    <input id="saving_description" name="description" value="{{ old('description') }}" maxlength="{{ config('opsdeck.cost_savings.realized_description_max_length') }}" required>
                    <label for="saving_contract_id">関連契約（任意）</label>
                    <select id="saving_contract_id" name="contract_id">
                        <option value="">契約を選択しない</option>
                        @foreach ($availableContracts as $contract)
                            <option value="{{ $contract->id }}" @selected((string) old('contract_id') === (string) $contract->id)>{{ $contract->vendor_name }} — {{ $contract->contract_name }}</option>
                        @endforeach
                    </select>
                    @if ($evidenceAvailable)
                        <label for="saving_evidence_id">保存済み証憑（任意）</label>
                        <select id="saving_evidence_id" name="evidence_id">
                            <option value="">証憑を紐づけない</option>
                            @foreach ($availableEvidence as $evidence)
                                <option value="{{ $evidence->id }}" @selected((string) old('evidence_id') === (string) $evidence->id)>{{ $evidence->title }}</option>
                            @endforeach
                        </select>
                    @endif
                    <label for="saving_amount">削減額</label>
                    <input id="saving_amount" name="amount" type="number" min="{{ config('opsdeck.cost_savings.realized_amount_min') }}" max="{{ config('opsdeck.cost_savings.realized_amount_max') }}" step="0.01" value="{{ old('amount') }}" required>
                    <label for="saving_currency">通貨コード（大文字3文字）</label>
                    <input id="saving_currency" name="currency" value="{{ old('currency', config('opsdeck.cost_savings.default_currency')) }}" maxlength="{{ config('opsdeck.cost_savings.currency_code_length') }}" required>
                    <label for="saving_period">金額の期間</label>
                    <select id="saving_period" name="period" required>
                        @foreach (config('opsdeck.cost_savings.realized_periods') as $period => $label)
                            <option value="{{ $period }}" @selected(old('period', config('opsdeck.cost_savings.default_realized_period')) === $period)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label for="saving_realized_on">実現日</label>
                    <input id="saving_realized_on" name="realized_on" type="date" max="{{ now($organization->timezone)->toDateString() }}" value="{{ old('realized_on', now($organization->timezone)->toDateString()) }}" required>
                    <label for="saving_evidence_reference">根拠の参照情報（請求書・稟議番号など）</label>
                    <input id="saving_evidence_reference" name="evidence_reference" value="{{ old('evidence_reference') }}" maxlength="{{ config('opsdeck.cost_savings.evidence_reference_max_length') }}" required>
                    <button type="submit">削減実績を記録</button>
                </form>
            @endcan
            <h3>記録履歴</h3>
            <table>
                <thead><tr><th scope="col">内容</th><th scope="col">契約</th><th scope="col">金額・期間</th><th scope="col">実現日</th><th scope="col">根拠の参照情報</th><th scope="col">保存済み証憑</th><th scope="col">記録者</th><th scope="col">状態・操作</th></tr></thead>
                <tbody>
                    @forelse ($realizedSavings as $saving)
                        <tr>
                            <td>{{ $saving->description }}</td>
                            <td>{{ $saving->contract?->contract_name ?? '—' }}</td>
                            <td>{{ $saving->currency }} {{ number_format((float) $saving->amount, 2) }} / {{ config('opsdeck.cost_savings.realized_periods.'.$saving->period, $saving->period) }}</td>
                            <td>{{ $saving->realized_on->format('Y-m-d') }}</td>
                            <td>{{ $saving->evidence_reference }}</td>
                            <td>
                                @if ($saving->evidence !== null && $saving->evidence->organization_id === $organization->id)
                                    @if ($evidenceAvailable)
                                        <a href="{{ route('evidence.show', $saving->evidence) }}">{{ $saving->evidence->title }}</a>
                                    @else
                                        {{ $saving->evidence->title }}
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $saving->creator->name }}</td>
                            <td>
                                @if ($saving->voided_at !== null)
                                    取消済み（{{ $saving->voided_at->timezone($organization->timezone)->format('Y-m-d H:i') }}）<br>理由：{{ $saving->void_reason }}
                                @else
                                    有効
                                    @can('manageOperationalData', $organization)
                                        <form method="post" action="{{ route('realized-savings.void', $saving) }}">
                                            @csrf
                                            <label for="void_reason_{{ $saving->id }}">取消理由</label>
                                            <input id="void_reason_{{ $saving->id }}" name="reason" maxlength="{{ config('opsdeck.cost_savings.realized_description_max_length') }}" required>
                                            <button type="submit">この記録を取り消す</button>
                                        </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">まだ記録はありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $realizedSavings->links() }}
        </section>
        @endif
        @if ($renewalAlertsAvailable)
            <h2>{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の更新・終了予定</h2>
            <table><thead><tr><th>契約</th><th>ベンダー</th><th>予定日</th></tr></thead><tbody>
            @forelse ($renewals as $renewal)
                <tr><td>{{ $renewal['contract']->contract_name }}</td><td>{{ $renewal['contract']->vendor_name }}</td><td>{{ $renewal['date']->format('Y-m-d') }}</td></tr>
            @empty
                <tr><td colspan="3">{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の予定はありません。</td></tr>
            @endforelse
            </tbody></table>
        @endif
    </section>
</x-layouts.app>
