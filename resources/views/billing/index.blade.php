<x-layouts.app>
    @php
        $currentPlan = config("opsdeck.plans.{$effectivePlanCode}.label", $effectivePlanCode);
        $freePlan = config('opsdeck.plans.free');
        $premiumPlan = config('opsdeck.plans.premium');
        $capabilityLabels = config('opsdeck.plan_capability_labels');
        $billingStatus = match ($organization->billing_status) {
            'active', 'trialing' => '利用中',
            'past_due' => '支払い確認中',
            'cancelled' => '解約済み',
            'inactive' => '未契約',
            default => $organization->billing_status,
        };
    @endphp
    <section class="card">
        <h1>Billing</h1>
        <dl>
            <dt>現在のプラン</dt><dd>{{ $currentPlan }}</dd>
            <dt>契約状態</dt><dd>{{ $billingStatus }}</dd>
            <dt>Premium料金</dt><dd>会社単位 月額{{ number_format($premiumPlan['monthly_price_jpy']) }}円（税別、適用税額を加算）</dd>
        </dl>
        @if ($failedStripeEvent)
            <p class="errors" role="alert">Stripeからの更新通知の処理に失敗しました。契約状態は通知が正常に処理されるまで確定できません。サポートへの問い合わせ時は参照番号 STRIPE-{{ $failedStripeEvent->stripe_event_id }} をお伝えください。<a href="{{ route('support.create', ['category' => 'billing', 'error_reference' => 'STRIPE-'.$failedStripeEvent->stripe_event_id]) }}">照会番号を添えてサポートへ問い合わせる</a></p>
        @endif
        @if ($organization->billing_status === 'past_due')
            @if ($organization->billing_failure_event_id)
                <p>Stripe照会番号：STRIPE-{{ $organization->billing_failure_event_id }}</p>
            @endif
            @if ($effectivePlanCode === config('opsdeck.free_plan_code'))
                <p class="errors" role="alert">支払い遅延により{{ config('opsdeck.billing.past_due_grace_days') }}日間の猶予期間が終了し、現在はFreeプランの上限・機能が適用されています。支払いを確認するにはCustomer Portalを開いてください。</p>
            @else
                <p class="errors" role="alert">Stripeでお支払いを確認できていません。{{ config('opsdeck.billing.past_due_grace_days') }}日間の猶予期間中にCustomer Portalで支払い方法と請求状況を確認してください。表示が解消しない場合は <a href="mailto:{{ config('opsdeck.support_email') }}">サポートへ問い合わせ</a> てください。</p>
            @endif
            @if ($billingGraceDeadline !== null)
                <p>猶予期限：{{ $billingGraceDeadline->format('Y/m/d H:i') }}（{{ $organization->timezone }}）</p>
            @else
                <p>猶予期限を特定できません。支払い状況を確認するにはCustomer Portalを開いてください。</p>
            @endif
            <p><a href="{{ route('help.recovery', 'stripe') }}">支払いエラーの復旧ガイド</a></p>
        @endif
        <h2>プランの違い</h2>
        <table>
            <thead><tr><th>項目</th><th>Free</th><th>Premium</th></tr></thead>
            <tbody>
                <tr><th>料金</th><td>{{ number_format($freePlan['monthly_price_jpy']) }}円</td><td>{{ number_format($premiumPlan['monthly_price_jpy']) }}円/月（税別）</td></tr>
                <tr><th>登録上限</th><td>社員{{ number_format($freePlan['limits']['employees']) }}・資産{{ number_format($freePlan['limits']['assets']) }}・SaaS{{ number_format($freePlan['limits']['saas_services']) }}・管理者{{ number_format($freePlan['limits']['administrators']) }}</td><td>社員{{ number_format($premiumPlan['limits']['employees']) }}・資産{{ number_format($premiumPlan['limits']['assets']) }}・SaaS{{ number_format($premiumPlan['limits']['saas_services']) }}・管理者{{ number_format($premiumPlan['limits']['administrators']) }}</td></tr>
                <tr><th>CSVプレビュー</th><td>利用可</td><td>利用可</td></tr>
                <tr><th>基本ダッシュボード・コスト集計</th><td>利用可</td><td>利用可</td></tr>
                @foreach ($capabilityLabels as $capability => $label)
                    <tr>
                        <th>{{ $label }}</th>
                        <td>{{ $freePlan['capabilities'][$capability] ? '利用可' : '—' }}</td>
                        <td>{{ $premiumPlan['capabilities'][$capability] ? '利用可' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($organization->stripe_customer_id)
            <form method="post" action="{{ route('billing.portal') }}">@csrf<button type="submit">{{ $organization->billing_status === 'past_due' ? '支払い方法・請求状況を確認する' : 'Customer Portalを開く' }}</button></form>
        @endif
        @if ($effectivePlanCode !== config('opsdeck.premium_plan_code') && ! in_array($organization->billing_status, ['active', 'trialing', 'past_due'], true))
            <form method="post" action="{{ route('billing.checkout') }}">@csrf<button type="submit">Stripe Checkoutを開始</button></form>
        @endif
        <p class="muted">契約状態の確定はStripe Webhookの受信結果に基づきます。</p>
    </section>
    @if ($canSubmitCancellationFeedback)
        <section class="card">
            <h2>解約理由（任意）</h2>
            <p class="muted">サービス改善のため、解約を検討・実施した理由を教えてください。Stripeの契約操作はCustomer Portalで行います。</p>
            <form method="post" action="{{ route('billing.cancellation-feedback.store') }}">
                @csrf
                <label for="reason">理由</label>
                <select id="reason" name="reason" required><option value="price">価格</option><option value="missing_feature">必要な機能がない</option><option value="not_using">利用頻度が低い</option><option value="support">サポート</option><option value="security">セキュリティ</option><option value="other">その他</option></select>
                <label for="details">補足（任意）</label>
                <textarea id="details" name="details" maxlength="2000"></textarea>
                <button type="submit">送信</button>
            </form>
        </section>
    @endif
</x-layouts.app>
