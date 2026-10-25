<x-layouts.app>
    <section class="card" aria-labelledby="employee-title">
        <h1 id="employee-title">{{ $employee->name }}</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <dl>
            <dt>社員番号</dt><dd>{{ $employee->employee_number ?? '未設定' }}</dd>
            <dt>メールアドレス</dt><dd>{{ $employee->email ?? '未設定' }}</dd>
            <dt>部署</dt><dd>{{ $employee->department?->name ?? '未設定' }}</dd>
            <dt>雇用状態</dt><dd>{{ config('opsdeck.employee_status_labels.'.$employee->employment_status, $employee->employment_status) }}</dd>
        </dl>
        @can('update', $employee)<p><a href="{{ route('employees.edit', $employee) }}">編集する</a></p>@endcan
        @if ($onboardingChecklist !== [])
            <section aria-labelledby="onboarding-checklist-title">
                <h2 id="onboarding-checklist-title">入社時のIT確認</h2>
                <p>必要な端末・サービスだけ登録してください。使わないものは登録不要です。ここに表示される内容はOpsDeck上の登録状況であり、会社のルールや実際の利用状況も確認してください。</p>
                <h3>貸与端末</h3>
                @if ($onboardingChecklist['assets']->isNotEmpty())
                    <ul>
                        @foreach ($onboardingChecklist['assets'] as $assignment)
                            @if ($assignment->asset !== null)
                                <li><a href="{{ route('assets.show', $assignment->asset) }}">{{ $assignment->asset->asset_number }}</a>：割当済み</li>
                            @endif
                        @endforeach
                    </ul>
                @else
                    <p>この社員に割り当てられた端末はありません。端末が必要な場合は、在庫を確認してください。</p>
                @endif
                <p><a href="{{ $onboardingChecklist['availableAssetsUrl'] }}">貸出可能な端末を確認する</a></p>

                <h3>クラウドサービス</h3>
                @if ($onboardingChecklist['services']->isNotEmpty())
                    <ul>
                        @foreach ($onboardingChecklist['services'] as $service)
                            <li>
                                <a href="{{ route('saas-services.show', ['saas_service' => $service, 'employee_id' => $employee->id]) }}">{{ $service->name }}</a>：
                                {{ $service->assignments->isNotEmpty() ? '利用者として登録済み' : '利用者登録なし（必要な場合のみ登録）' }}
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p>利用中のクラウドサービスは登録されていません。</p>
                @endif
            </section>
        @endif
        @if ($departureChecklist !== [])
            <section aria-labelledby="departure-checklist-title">
                <h2 id="departure-checklist-title">退職時の確認</h2>
                <p>OpsDeckに登録されている情報から、確認が必要な項目を表示しています。外部サービスのアカウント停止や権限移管が完了したかは、各サービス側でも確認してください。</p>
                @php($hasOpenItems = false)
                <ul>
                    @foreach ($departureChecklist as $checklistItem)
                        @php($recordCount = $checklistItem['records']->count())
                        @php($hasOpenItems = $hasOpenItems || $recordCount > 0)
                        <li>
                            <strong>{{ $checklistItem['title'] }}</strong>：
                            @if ($recordCount > 0)
                                {{ $recordCount }}件の確認が必要です。
                                <ul>
                                    @foreach ($checklistItem['records'] as $record)
                                        <li>
                                            @if ($checklistItem['type'] === 'assets' && $record->asset !== null)
                                                <a href="{{ route('assets.show', $record->asset) }}#return-title">{{ $record->asset->asset_number }}（返却を確認）</a>
                                            @elseif ($checklistItem['type'] === 'saas' && $record->service !== null)
                                                @can('update', $record)
                                                    <a href="{{ route('saas-assignments.edit', $record) }}">{{ $record->service->name }}（利用終了を記録）</a>
                                                @else
                                                    {{ $record->service->name }}
                                                @endcan
                                            @elseif ($checklistItem['type'] === 'contracts')
                                                <a href="{{ route('contracts.show', $record) }}">{{ $record->contract_name }}</a>
                                                @can('update', $record)
                                                    （<a href="{{ route('contracts.edit', $record) }}">担当者の引き継ぎを記録</a>）
                                                @endcan
                                            @elseif ($checklistItem['type'] === 'security_controls')
                                                <a href="{{ route('security-controls.show', $record) }}">{{ $record->title }}</a>
                                                @can('update', $record)
                                                    （<a href="{{ route('security-controls.edit', $record) }}">担当者の引き継ぎを記録</a>）
                                                @endcan
                                            @elseif ($checklistItem['type'] === 'memberships')
                                                {{ $record->user->name }}（{{ $record->user->email }}・{{ $record->status->label() }}）
                                                @can('manageMembers', $organization)
                                                    @if ((int) $record->user_id === (int) auth()->id())
                                                        （本人の権限は別のOwnerが確認してください）
                                                    @else
                                                        （<a href="{{ route('settings.members', ['membership_id' => $record->id]) }}">この利用者の設定を確認</a>）
                                                    @endif
                                                @else
                                                    （組織Ownerに利用状態の確認を依頼してください）
                                                @endcan
                                            @elseif ($checklistItem['type'] === 'invitations')
                                                {{ $record->email }}（招待中）
                                                @can('manageMembers', $organization)
                                                    <form method="post" action="{{ route('settings.members.invitations.revoke', $record) }}">
                                                        @csrf
                                                        @method('delete')
                                                        <button type="submit">招待を取り消す</button>
                                                    </form>
                                                @else
                                                    （組織Ownerに招待の取り消しを依頼してください）
                                                @endcan
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                OpsDeck上に未処理の登録はありません。
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if (! $hasOpenItems)
                    <p role="status">OpsDeck上の登録では、未処理として残る項目はありません。</p>
                @endif
            </section>
        @endif
        <p><a href="{{ route('employees.index') }}">社員一覧へ戻る</a></p>
    </section>
</x-layouts.app>
