<x-layouts.app>
    <section class="card">
        <h1>費用の確認・見直し</h1>
        <p class="muted">登録された契約の月額費用と、担当者が確認する見直し候補を表示します。候補額は目安です。契約の解約やサービス停止は自動で行いません。</p>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($savingsOpportunitiesAvailable)
            <section class="card" aria-labelledby="savings-estimate-title">
                <h2 id="savings-estimate-title">削減候補の年間金額（概算）</h2>
                <p><strong>{{ number_format($savingsEstimate['annual_total_jpy']) }}円</strong></p>
                <p class="muted">登録された契約や端末の状態、設定された計算条件から算出した目安です。実際に削減できる金額は、利用状況や契約条件を担当者が確認してください。</p>
                <ul>
                    <li>契約更新時に金額を見直す候補：{{ number_format($savingsEstimate['renewal_negotiation_jpy']) }}円</li>
                    <li>利用者の割当状況を確認するクラウドサービス：{{ number_format($savingsEstimate['unused_saas_jpy']) }}円</li>
                    <li>利用状況を確認する端末：{{ number_format($savingsEstimate['unused_assets_jpy']) }}円</li>
                </ul>
                @if ($savingsEstimate['renewal_candidates'] !== [])
                    <h3>更新前に確認する契約（{{ $savingsEstimate['renewal_candidate_count'] }}件）</h3>
                    <p class="muted">90日以内に更新・終了予定で、自動更新が有効な日本円（JPY）の継続契約です。解約手続きを予定している契約は含みません。年間費用に会社別または共通の見直し率を適用した目安です。契約変更・解約は行わず、条件と更新日を担当者が確認してください。</p>
                    <table>
                        <thead><tr><th scope="col">契約</th><th scope="col">更新・終了予定日</th><th scope="col">年間費用（円）</th><th scope="col">見直し候補額</th></tr></thead>
                        <tbody>
                            @foreach ($savingsEstimate['renewal_candidates'] as $candidate)
                                <tr>
                                    <td><a href="{{ route('contracts.show', $candidate['contract_id']) }}">{{ $candidate['vendor_name'] }} — {{ $candidate['contract_name'] }}</a></td>
                                    <td>{{ $candidate['renewal_date'] }}</td>
                                    <td>{{ number_format($candidate['annual_cost_jpy'], 2) }}円</td>
                                    <td>{{ number_format($candidate['estimated_savings_jpy']) }}円</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($savingsEstimate['renewal_candidate_count'] > count($savingsEstimate['renewal_candidates']))
                        <p><a href="{{ route('contracts.index') }}">契約一覧ですべて確認</a></p>
                    @endif
                @endif
                <p class="muted">計算の前提：契約の見直し率{{ $savingsEstimate['assumptions']['renewal_rate'] * 100 }}%（{{ $savingsEstimate['assumptions']['renewal_rate_source'] === 'organization' ? '会社別設定' : '共通の初期設定値' }}）、対象端末（{{ implode('・', $savingsEstimate['assumptions']['unused_asset_statuses']) }}）{{ number_format($savingsEstimate['assumptions']['unused_asset_monthly_jpy']) }}円/月（{{ $savingsEstimate['assumptions']['unused_asset_monthly_jpy_source'] === 'organization' ? '会社別設定' : '共通の初期設定値' }}）です。<a href="{{ route('settings.organization') }}">会社別の計算条件を設定</a></p>
                @foreach ($savingsEstimate['assumptions']['warnings'] as $warning)
                    <p class="errors" role="alert">{{ $warning }} 設定を確認してください。</p>
                @endforeach
                @if ($savingsEstimate['assumptions']['unconverted_foreign_contracts_by_currency'] !== [])
                    <p class="muted">為替レート未設定のため概算に含めていない外貨継続契約：@foreach ($savingsEstimate['assumptions']['unconverted_foreign_contracts_by_currency'] as $currency => $count){{ $currency }}：{{ $count }}件@if (! $loop->last)、@endif @endforeach</p>
                @endif
                @if ($savingsEstimate['unused_saas_candidates'] !== [])
                    <h3>利用者の割当状況を確認するクラウドサービス</h3>
                    <p class="muted">「在籍中」と登録された社員または全社共通アカウントへの利用者登録がないサービスです。退職済みなどの社員への登録だけの場合も含みます。解約予定ではない日本円の月額・年額契約を候補額に含めています。利用状況や契約条件を担当者が確認してください。サービス停止や契約解除は自動で行いません。</p>
                    <table>
                        <thead><tr><th scope="col">サービス</th><th scope="col">対象契約</th><th scope="col">年間費用の概算</th></tr></thead>
                        <tbody>
                            @foreach ($savingsEstimate['unused_saas_candidates'] as $candidate)
                                <tr>
                                    <td><a href="{{ route('saas-services.show', $candidate['service_id']) }}">{{ $candidate['service_name'] }}</a></td>
                                    <td>{{ implode('、', $candidate['contract_names']) }}</td>
                                    <td>{{ number_format($candidate['annual_cost_jpy'], 2) }}円</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if ($savingsEstimate['unused_asset_candidates'] !== [])
                    <h3>利用状況を確認する端末（{{ $savingsEstimate['unused_asset_candidate_count'] }}台）</h3>
                    <p class="muted">「{{ implode('」「', $savingsEstimate['assumptions']['unused_asset_statuses']) }}」の状態で、未返却の割当がない端末です。月額の計算条件に基づく目安で、廃棄や割当解除は行いません。端末の所在・稼働状況を担当者が確認してください。</p>
                    <table>
                        <thead><tr><th scope="col">端末番号</th><th scope="col">型番</th><th scope="col">状態</th><th scope="col">年間費用の概算</th></tr></thead>
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
            <section class="card" aria-labelledby="cost-features-title">
                <h2 id="cost-features-title">削減候補と更新アラート</h2>
                <p class="muted">現在、この機能は利用できません。現在利用できる機能はすべて無料です。</p>
            </section>
        @endif
        <h2>契約の月額費用</h2>
        <p class="muted">継続中の契約を月額に換算し、通貨ごとに表示しています。JPYは日本円、USDは米ドルです。異なる通貨は合算していません。</p>
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
            <p class="muted">担当者が「削減できた」と記録した金額です。入力内容や資料が正しいかをOpsDeckが自動で確認するものではありません。通貨と期間ごとに集計し、異なる通貨・期間は合算しません。</p>
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
                    <input type="hidden" name="costs_form" value="realized_saving">
                    <label for="saving_description">何を見直しましたか？</label>
                    <input id="saving_description" name="description" value="{{ old('description') }}" maxlength="{{ config('opsdeck.cost_savings.realized_description_max_length') }}" required @error('description') aria-invalid="true" aria-describedby="description-error" @enderror>
                    <x-field-error field="description" />

                    <label for="saving_contract_id">関連契約（任意）</label>
                    <select id="saving_contract_id" name="contract_id" @error('contract_id') aria-invalid="true" aria-describedby="contract_id-error" @enderror>
                        <option value="">契約を選択しない</option>
                        @foreach ($availableContracts as $contract)
                            <option value="{{ $contract->id }}" @selected((string) old('contract_id') === (string) $contract->id)>{{ $contract->vendor_name }} — {{ $contract->contract_name }}</option>
                        @endforeach
                    </select>
                    <x-field-error field="contract_id" />

                    @if ($evidenceAvailable)
                        <label for="saving_evidence_id">関連する保存済み資料（任意）</label>
                        <select id="saving_evidence_id" name="evidence_id" @error('evidence_id') aria-invalid="true" aria-describedby="evidence_id-error" @enderror>
                            <option value="">資料を選ばない</option>
                            @foreach ($availableEvidence as $evidence)
                                <option value="{{ $evidence->id }}" @selected((string) old('evidence_id') === (string) $evidence->id)>{{ $evidence->title }}</option>
                            @endforeach
                        </select>
                        <x-field-error field="evidence_id" />
                    @endif

                    <label for="saving_amount">削減できた金額</label>
                    <input id="saving_amount" name="amount" type="number" min="{{ config('opsdeck.cost_savings.realized_amount_min') }}" max="{{ config('opsdeck.cost_savings.realized_amount_max') }}" step="0.01" value="{{ old('amount') }}" required @error('amount') aria-invalid="true" aria-describedby="amount-error" @enderror>
                    <x-field-error field="amount" />
                    <p class="muted">例：年間120,000円を削減できた場合は、金額「120000」、通貨「JPY」、期間「年額」と入力します。</p>

                    <label for="saving_currency">通貨（例：JPY＝日本円）</label>
                    <input id="saving_currency" name="currency" value="{{ old('costs_form') === 'realized_saving' ? old('currency', config('opsdeck.cost_savings.default_currency')) : config('opsdeck.cost_savings.default_currency') }}" maxlength="{{ config('opsdeck.cost_savings.currency_code_length') }}" required @if ($errors->has('currency') && old('costs_form') === 'realized_saving') aria-invalid="true" aria-describedby="currency-error" @endif>
                    @if (old('costs_form') === 'realized_saving')
                        <x-field-error field="currency" />
                    @endif

                    <label for="saving_period">この金額の期間</label>
                    <select id="saving_period" name="period" required @error('period') aria-invalid="true" aria-describedby="period-error" @enderror>
                        @foreach (config('opsdeck.cost_savings.realized_periods') as $period => $label)
                            <option value="{{ $period }}" @selected(old('period', config('opsdeck.cost_savings.default_realized_period')) === $period)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-field-error field="period" />

                    <label for="saving_realized_on">削減できたことを確認した日</label>
                    <input id="saving_realized_on" name="realized_on" type="date" max="{{ now($organization->timezone)->toDateString() }}" value="{{ old('realized_on', now($organization->timezone)->toDateString()) }}" required @error('realized_on') aria-invalid="true" aria-describedby="realized_on-error" @enderror>
                    <x-field-error field="realized_on" />

                    <label for="saving_evidence_reference">確認に使った資料名・番号（請求書番号など）</label>
                    <input id="saving_evidence_reference" name="evidence_reference" value="{{ old('costs_form') === 'realized_saving' ? old('evidence_reference') : '' }}" maxlength="{{ config('opsdeck.cost_savings.evidence_reference_max_length') }}" required @if ($errors->has('evidence_reference') && old('costs_form') === 'realized_saving') aria-invalid="true" aria-describedby="evidence_reference-error" @endif>
                    @if (old('costs_form') === 'realized_saving')
                        <x-field-error field="evidence_reference" />
                    @endif
                    <button type="submit">削減実績を記録</button>
                </form>
            @endcan
            <h3>記録履歴</h3>
            <table>
                <thead><tr><th scope="col">内容</th><th scope="col">契約</th><th scope="col">金額・期間</th><th scope="col">確認日</th><th scope="col">確認に使った資料・番号</th><th scope="col">保存済み資料</th><th scope="col">記録者</th><th scope="col">状態・操作</th></tr></thead>
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
                                            <input type="hidden" name="costs_form" value="realized_saving_void">
                                            <input type="hidden" name="saving_id" value="{{ $saving->id }}">
                                            <label for="void_reason_{{ $saving->id }}">取消理由</label>
                                            <input id="void_reason_{{ $saving->id }}" name="reason" value="{{ old('costs_form') === 'realized_saving_void' && (string) old('saving_id') === (string) $saving->id ? old('reason') : '' }}" maxlength="{{ config('opsdeck.cost_savings.realized_description_max_length') }}" required @if ($errors->has('reason') && old('costs_form') === 'realized_saving_void' && (string) old('saving_id') === (string) $saving->id) aria-invalid="true" aria-describedby="saving-void-reason-{{ $saving->id }}-error" @endif>
                                            @if (old('costs_form') === 'realized_saving_void' && (string) old('saving_id') === (string) $saving->id)
                                                <x-field-error field="reason" id="saving-void-reason-{{ $saving->id }}-error" />
                                            @endif
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
        @if ($workTimeTrackingAvailable)
            <section class="card" aria-labelledby="work-time-measurements-title">
                <h2 id="work-time-measurements-title">作業時間の変化（担当者が入力した参考値）</h2>
                <p class="muted">同じ作業の改善前・改善後の時間と月あたりの回数から計算します。担当者が入力する参考値で、実際の経費削減額や給与額には加算しません。月あたりの時間がマイナスなら、作業時間が増えたという意味です。</p>
                <p class="muted" role="note">入力例：同じ請求書確認が、以前は1回30分、今は1回10分で、月4回なら「改善前30分・改善後10分・月4回」です。差の20分×4回で、月80分の短縮として表示します。できれば同じ条件で時間を測り、調べた方法を入力してください。</p>
                <p><strong>月あたりの時間変化：</strong>
                    @if ($workTimeSummary['monthly_hours'] > 0)
                            {{ number_format($workTimeSummary['monthly_hours'], config('opsdeck.work_time.decimal_places')) }}時間短縮
                    @elseif ($workTimeSummary['monthly_hours'] < 0)
                            {{ number_format(abs($workTimeSummary['monthly_hours']), config('opsdeck.work_time.decimal_places')) }}時間増加
                    @else
                        変化なし
                    @endif
                </p>
                @if ($workTimeSummary['monthly_labor_equivalent_by_currency'] !== [])
                    <p><strong>時間単価から計算した参考値：</strong>
                        @foreach ($workTimeSummary['monthly_labor_equivalent_by_currency'] as $currency => $amount)
                            {{ $currency }} {{ $amount > 0 ? '+' : ($amount < 0 ? '-' : '') }}{{ number_format(abs($amount), 2) }}（{{ $amount > 0 ? '短縮分' : ($amount < 0 ? '増加分' : '変化なし') }}）@unless ($loop->last) / @endunless
                        @endforeach
                    </p>
                    <p class="muted">参考値は入力された時間単価を使った計算です。実際の経費削減ではありません。通貨の異なる金額は合算していません。</p>
                @endif
                @can('manageOperationalData', $organization)
                    <h3>作業時間を記録または更新する</h3>
                    <p class="muted">同じ作業を更新するときは、前回と同じ作業名を入力してください。以前の記録が更新され、二重に合算されません。</p>
                    <form method="post" action="{{ route('work-time-measurements.store') }}">
                        @csrf
                        <input type="hidden" name="costs_form" value="work_time">
                        <label for="work_time_process_name">作業名</label>
                        <input id="work_time_process_name" name="process_name" value="{{ old('process_name') }}" maxlength="{{ config('opsdeck.work_time.process_name_max_length') }}" required placeholder="例：月末の請求書確認" @error('process_name') aria-invalid="true" aria-describedby="process_name-error" @enderror>
                        <x-field-error field="process_name" />

                        <label for="work_time_before_minutes">改善前：1回にかかる時間（分）</label>
                        <input id="work_time_before_minutes" name="before_minutes" type="number" min="{{ config('opsdeck.work_time.minutes_per_run_min') }}" max="{{ config('opsdeck.work_time.minutes_per_run_max') }}" value="{{ old('before_minutes') }}" required @error('before_minutes') aria-invalid="true" aria-describedby="before_minutes-error" @enderror>
                        <x-field-error field="before_minutes" />

                        <label for="work_time_after_minutes">改善後：1回にかかる時間（分）</label>
                        <input id="work_time_after_minutes" name="after_minutes" type="number" min="{{ config('opsdeck.work_time.after_minutes_min') }}" max="{{ config('opsdeck.work_time.minutes_per_run_max') }}" value="{{ old('after_minutes') }}" required @error('after_minutes') aria-invalid="true" aria-describedby="after_minutes-error" @enderror>
                        <x-field-error field="after_minutes" />

                        <label for="work_time_monthly_occurrences">この作業は月に何回ありますか？</label>
                        <input id="work_time_monthly_occurrences" name="monthly_occurrences" type="number" min="{{ config('opsdeck.work_time.monthly_occurrences_min') }}" max="{{ config('opsdeck.work_time.monthly_occurrences_max') }}" value="{{ old('monthly_occurrences') }}" required @error('monthly_occurrences') aria-invalid="true" aria-describedby="monthly_occurrences-error" @enderror>
                        <x-field-error field="monthly_occurrences" />

                        <label for="work_time_measured_on">計測した日</label>
                        <input id="work_time_measured_on" name="measured_on" type="date" max="{{ now($organization->timezone)->toDateString() }}" value="{{ old('measured_on', now($organization->timezone)->toDateString()) }}" required @error('measured_on') aria-invalid="true" aria-describedby="measured_on-error" @enderror>
                        <x-field-error field="measured_on" />

                        <label for="work_time_evidence_reference">どうやって時間を調べましたか？</label>
                        <p class="muted">例：直近2か月、作業にかかった時間を記録して平均しました。</p>
                        <input id="work_time_evidence_reference" name="evidence_reference" value="{{ old('costs_form') === 'work_time' ? old('evidence_reference') : '' }}" maxlength="{{ config('opsdeck.work_time.evidence_reference_max_length') }}" required @if ($errors->has('evidence_reference') && old('costs_form') === 'work_time') aria-invalid="true" aria-describedby="work-time-evidence-reference-error" @endif>
                        @if (old('costs_form') === 'work_time')
                            <x-field-error field="evidence_reference" id="work-time-evidence-reference-error" />
                        @endif

                        <details @if ($errors->hasAny(['hourly_rate', 'currency'])) open @endif>
                            <summary>時間短縮を金額に置き換えたい場合（任意）</summary>
                            <p class="muted">担当者の時間単価を入力した場合のみ、参考金額を表示します。空欄なら時間だけを集計します。</p>
                            <label for="work_time_hourly_rate">担当者の1時間あたりの人件費（任意）</label>
                            <p class="muted">例：1時間あたり3,000円なら「3000」と入力します。</p>
                            <input id="work_time_hourly_rate" name="hourly_rate" type="number" min="{{ config('opsdeck.work_time.hourly_rate_min') }}" max="{{ config('opsdeck.work_time.hourly_rate_max') }}" step="0.01" value="{{ old('hourly_rate') }}" @error('hourly_rate') aria-invalid="true" aria-describedby="hourly_rate-error" @enderror>
                            <x-field-error field="hourly_rate" />

                            <label for="work_time_currency">通貨（例：JPY＝日本円）</label>
                            <input id="work_time_currency" name="currency" value="{{ old('costs_form') === 'work_time' ? old('currency', config('opsdeck.cost_savings.default_currency')) : config('opsdeck.cost_savings.default_currency') }}" maxlength="{{ config('opsdeck.work_time.currency_code_length') }}" placeholder="JPY" @if ($errors->has('currency') && old('costs_form') === 'work_time') aria-invalid="true" aria-describedby="work-time-currency-error" @endif>
                            @if (old('costs_form') === 'work_time')
                                <x-field-error field="currency" id="work-time-currency-error" />
                            @endif
                        </details>
                        <button type="submit">参考値として記録する</button>
                    </form>
                @endcan
                <h3>記録履歴</h3>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th scope="col">作業名</th><th scope="col">1回の時間（前→後）</th><th scope="col">月の回数</th><th scope="col">月あたりの変化</th><th scope="col">計測日・方法</th><th scope="col">状態・操作</th></tr></thead>
                        <tbody>
                            @forelse ($workTimeMeasurements as $measurement)
                                @php($monthlyMinutes = ($measurement->before_minutes - $measurement->after_minutes) * $measurement->monthly_occurrences)
                                <tr>
                                    <td>{{ $measurement->process_name }}</td>
                                    <td>{{ $measurement->before_minutes }}分 → {{ $measurement->after_minutes }}分</td>
                                    <td>{{ $measurement->monthly_occurrences }}回</td>
                                    <td>{{ $monthlyMinutes > 0 ? $monthlyMinutes.'分短縮' : ($monthlyMinutes < 0 ? abs($monthlyMinutes).'分増加' : '変化なし') }}</td>
                                    <td>{{ $measurement->measured_on->format('Y-m-d') }}<br>{{ $measurement->evidence_reference }}</td>
                                    <td>
                                        @if ($measurement->voided_at !== null)
                                            取消済み（{{ $measurement->voided_at->timezone($organization->timezone)->format('Y-m-d H:i') }}）<br>理由：{{ $measurement->void_reason }}
                                        @else
                                            有効
                                            @can('manageOperationalData', $organization)
                                                <form method="post" action="{{ route('work-time-measurements.void', $measurement) }}">
                                                    @csrf
                                                    <input type="hidden" name="costs_form" value="work_time_void">
                                                    <input type="hidden" name="work_time_measurement_id" value="{{ $measurement->id }}">
                                                    <label for="work_time_void_reason_{{ $measurement->id }}">取消理由</label>
                                                    <input id="work_time_void_reason_{{ $measurement->id }}" name="reason" value="{{ old('costs_form') === 'work_time_void' && (string) old('work_time_measurement_id') === (string) $measurement->id ? old('reason') : '' }}" maxlength="{{ config('opsdeck.work_time.void_reason_max_length') }}" required @if ($errors->has('reason') && old('costs_form') === 'work_time_void' && (string) old('work_time_measurement_id') === (string) $measurement->id) aria-invalid="true" aria-describedby="work-time-void-reason-{{ $measurement->id }}-error" @endif>
                                                    @if (old('costs_form') === 'work_time_void' && (string) old('work_time_measurement_id') === (string) $measurement->id)
                                                        <x-field-error field="reason" id="work-time-void-reason-{{ $measurement->id }}-error" />
                                                    @endif
                                                    <button type="submit">この記録を取り消す</button>
                                                </form>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6">まだ記録はありません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $workTimeMeasurements->links() }}
            </section>
        @endif
        @if ($renewalAlertsAvailable)
            <h2>{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内に確認が必要な契約期限</h2>
            <table><thead><tr><th>契約</th><th>ベンダー</th><th>期限の種類</th><th>期限日</th></tr></thead><tbody>
            @forelse ($renewals as $renewal)
                <tr><td><a href="{{ route('contracts.show', $renewal['contract']) }}">{{ $renewal['contract']->contract_name }}</a></td><td>{{ $renewal['contract']->vendor_name }}</td><td>{{ $renewal['date_label'] }}</td><td>{{ $renewal['date']->format('Y-m-d') }}</td></tr>
            @empty
                <tr><td colspan="4">{{ \App\Services\ContractRenewalWindow::ALERT_WINDOW_DAYS }}日以内の予定はありません。</td></tr>
            @endforelse
            </tbody></table>
        @endif
    </section>
</x-layouts.app>
