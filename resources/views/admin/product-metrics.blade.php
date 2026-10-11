<x-layouts.app>
    <section class="card">
        <h1>製品利用状況</h1>
        <p class="muted">個人情報を表示せず、イベント名と件数だけを集計しています。</p>
        <p><a href="{{ route('admin.dashboard') }}">運営管理へ戻る</a></p>
        <p><a href="{{ route('admin.product-metrics.export') }}">集計CSVをダウンロード</a></p>
        <h2>Activation</h2>
        <p>月次アクティブ組織数（直近30日）：{{ $monthlyActiveOrganizations }}</p>
        <p class="muted">組織メンバーが認証後に組織画面を正常に利用した日を集計します。計測開始前の履歴は含まれません。</p>
        <p>プラン上限に近い組織数：{{ $planLimitMetrics['near_limit'] }}組織（上限到達 {{ $planLimitMetrics['at_limit'] }}組織）</p>
        <p class="muted">「上限に近い」は利用率{{ config('opsdeck.limits.warning_percentage') }}%以上を示します。</p>
        <p>30日継続率：{{ $retention['rate'] }}%（登録から30日以上経過した組織のうち、直近30日に利用した組織：{{ $retention['retained'] }} / {{ $retention['eligible'] }}）</p>
        <dl>
            <dt>登録組織数（全期間）</dt><dd>{{ $activation['registered'] }}</dd>
            <dt>登録後7日経過</dt><dd>{{ $activation['eligible'] }}</dd>
            <dt>7日以内に初回Preview到達した組織数</dt><dd>{{ $activation['previewed'] }}</dd>
            <dt>7日以内に初回Import完了した組織数</dt><dd>{{ $activation['activated'] }}</dd>
            <dt>Activation率</dt><dd>{{ $activation['activation_rate'] }}%</dd>
            <dt>Previewから完了への転換率</dt><dd>{{ $activation['preview_to_completion_rate'] }}%</dd>
            <dt>平均Time to Value</dt><dd>{{ $activation['average_time_to_value_hours'] === null ? '比較データなし' : $activation['average_time_to_value_hours'].'時間' }}</dd>
        </dl>
        <h2>Onboarding</h2>
        <dl><dt>導入完了組織数</dt><dd>{{ $onboarding['completed'] }}</dd><dt>導入完了率</dt><dd>{{ $onboarding['completion_rate'] }}%</dd></dl>
        <h2>Import</h2>
        <p>Import処理失敗率（完了・失敗した処理）：{{ $importFailureRate['rate'] }}%（対象{{ $importFailureRate['total'] }}件 / 失敗{{ $importFailureRate['failed'] }}件）</p>
        <p>AI候補の人手承認率：{{ $aiApproval['rate'] }}%（確認済み{{ $aiApproval['reviewed'] }}件 / 承認{{ $aiApproval['approved'] }}件）</p>
        <p>更新期限確認数：{{ $renewalChecks }}件</p>
        <p>累計解約率：{{ $cancellation['rate'] }}%（課金開始履歴{{ $cancellation['total'] }}組織 / 解約履歴{{ $cancellation['cancelled'] }}組織）</p>
        <p>現在の解約状態比率：{{ $cancellation['current_rate'] }}%（対象{{ $cancellation['current_total'] }}組織 / 解約状態{{ $cancellation['current_cancelled'] }}組織）</p>
        <p>紹介転換率：{{ $referrals['rate'] }}%（クリック{{ $referrals['clicks'] }}件 / 登録{{ $referrals['registrations'] }}件）</p>
        <p>お知らせ既読率：{{ $announcementReadRate['rate'] }}%（送信対象{{ $announcementReadRate['recipients'] }}件 / 既読{{ $announcementReadRate['reads'] }}件）</p>
        <p>招待参加転換率：{{ $invitationMetrics['rate'] }}%（招待{{ $invitationMetrics['sent'] }}件 / 参加{{ $invitationMetrics['joined'] }}件）</p>
        <table>
            <thead><tr><th>イベント</th><th>発生件数</th><th>組織数</th></tr></thead>
            <tbody>
                @forelse ($events as $event)
                    <tr><td>{{ $event->event_key }}</td><td>{{ $event->event_count }}</td><td>{{ $organizationEvents[$event->event_key]->organization_count ?? 0 }}</td></tr>
                @empty
                    <tr><td colspan="3">計測イベントはありません。</td></tr>
                @endforelse
            </tbody>
        </table>
        <h2>解約理由</h2>
        <table><thead><tr><th>理由</th><th>件数</th></tr></thead><tbody>
            @forelse ($cancellationReasons as $reason)<tr><td>{{ $reason->reason }}</td><td>{{ $reason->reason_count }}</td></tr>@empty<tr><td colspan="2">解約理由の記録はありません。</td></tr>@endforelse
        </tbody></table>
        <h3>問い合わせ対応状況</h3>
        <table><thead><tr><th>状態</th><th>件数</th></tr></thead><tbody>
            @forelse ($supportStatuses as $status)<tr><td>{{ $status->status }}</td><td>{{ $status->request_count }}</td></tr>@empty<tr><td colspan="2">問い合わせはありません。</td></tr>@endforelse
        </tbody></table>
        <h2>メール配信</h2>
        <dl><dt>お知らせ成功率</dt><dd>{{ $deliveryRates['announcement'] }}%</dd><dt>週次サマリー成功率</dt><dd>{{ $deliveryRates['weekly_summary'] }}%</dd><dt>お知らせ失敗</dt><dd>{{ $deliveryFailures['announcement'] }}</dd><dt>週次サマリー失敗</dt><dd>{{ $deliveryFailures['weekly_summary'] }}</dd></dl>
        <h2>問い合わせ</h2>
        <table><thead><tr><th>カテゴリ</th><th>件数</th></tr></thead><tbody>
            @forelse ($supportCategories as $category)<tr><td>{{ $category->category }}</td><td>{{ $category->request_count }}</td></tr>@empty<tr><td colspan="2">問い合わせはありません。</td></tr>@endforelse
        </tbody></table>
    </section>
</x-layouts.app>
