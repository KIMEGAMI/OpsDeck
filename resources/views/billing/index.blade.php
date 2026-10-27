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
            default => '状態を確認してください',
        };
    @endphp
    <section class="card">
        <h1>プラン・お支払い</h1>
        @if (! $checkoutAvailable)
            <p class="errors" role="alert">有料プランの申込みは現在準備中です。販売者情報の設定が完了するまでお申し込みいただけません。</p>
        @endif
        @if ($checkoutReturn === 'success')
            <p role="status">Stripe Checkoutから戻りました。プラン状態はStripeからの契約通知後に反映されます。現在「未契約」と表示されている場合は、少し待ってからこの画面を再読み込みしてください。</p>
        @elseif ($checkoutReturn === 'cancelled')
            <p role="status">Stripe Checkoutを中断しました。今回の申し込みは完了していません。申し込みを続ける場合は、下の「Stripe Checkoutを開始」を選んでください。</p>
        @endif
        @if (is_array(session('status')) && session('status.storage_addon_scheduled'))
            <p role="status">追加容量の変更を{{ session('status.effective_at') }}に予約しました。次回更新までは現在の容量を維持します。予定数量：{{ number_format((int) session('status.quantity')) }}GiB</p>
        @endif
        <dl>
            <dt>現在のプラン</dt><dd>{{ $currentPlan }}</dd>
            <dt>契約状態</dt><dd>{{ $billingStatus }}</dd>
            <dt>Premium料金</dt><dd>会社単位 月額{{ number_format($premiumPlan['monthly_price_jpy']) }}円（税別、適用税額を加算）</dd>
        </dl>
        @if ($organization->billing_cancel_at_period_end || $organization->billing_cancel_at !== null)
            <p role="status">Premiumの解約が予約されています。終了予定：{{ $organization->billing_cancel_at?->copy()->timezone($organization->timezone)->format('Y/m/d') ?? '契約期間終了時' }}。@if ($effectivePlanCode === config('opsdeck.premium_plan_code'))その日までは現在のプランと保存容量をご利用いただけます。@else支払い猶予が終了しているため、現在はFreeプランの上限・機能が適用されています。@endif 予約を取り消す場合はCustomer Portalで契約を確認してください。</p>
        @endif
        <h2>保存容量</h2>
        <dl>
            <dt>現在の使用量</dt><dd>{{ $storageQuota['used_display'] }}</dd>
            <dt>現在の上限</dt><dd>{{ $storageQuota['limit_display'] }}</dd>
            <dt>現在有効な追加容量</dt><dd>{{ number_format($storageQuota['addon_gib']) }}GiB</dd>
            @if ($storageQuota['pending_addon_gib'] !== null)
                @if ($organization->storage_addon_effective_at?->isPast())
                    <dt>反映確認中の追加容量</dt><dd>{{ number_format($storageQuota['pending_addon_gib']) }}GiB（{{ $storageQuota['addon_effective_at_display'] }}反映予定）</dd>
                    <p class="errors" role="alert">予定日を過ぎていますが、現在有効な追加容量は{{ number_format($storageQuota['addon_gib']) }}GiBです。請求と契約内容を確認しています。解消しない場合は<a href="mailto:{{ config('opsdeck.support_email') }}">サポートへお問い合わせください</a>。</p>
                @else
                    <dt>次回更新後の予定</dt><dd>{{ number_format($storageQuota['pending_addon_gib']) }}GiB（{{ $storageQuota['addon_effective_at_display'] ?? '次回更新日' }}に反映）</dd>
                @endif
            @endif
        </dl>
        @if (config('opsdeck.release_features.storage_addon') && $storageAddonAvailable && $storageAddonMaximumQuantity !== null)
            @php
                $selectedStorageAddonQuantity = (int) old('quantity', $organization->storage_addon_pending_gib ?? $organization->storage_addon_gib ?? 0);
                $selectedStorageAddonQuantity = min($storageAddonMaximumQuantity, max(0, $selectedStorageAddonQuantity));
                $storageAddonUnitPrice = (int) config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy');
                $projectedStorageLimitMib = intdiv($storageQuota['base_limit_bytes'], (int) config('opsdeck.storage_quotas.bytes_per_megabyte')) + ($selectedStorageAddonQuantity * (int) config('opsdeck.storage_quotas.mebibytes_per_gibibyte'));
            @endphp
            <form method="post" action="{{ route('billing.storage-addon.update') }}">
                @csrf
                <label for="storage-addon-quantity">次回更新後に使う追加容量（GiB）</label>
                <input id="storage-addon-quantity" name="quantity" type="number" min="0" max="{{ $storageAddonMaximumQuantity }}" step="1" value="{{ $selectedStorageAddonQuantity }}" required aria-describedby="storage-addon-price-help storage-addon-timing-help" data-storage-addon-quantity data-addon-price-jpy="{{ $storageAddonUnitPrice }}" data-base-limit-mib="{{ intdiv($storageQuota['base_limit_bytes'], (int) config('opsdeck.storage_quotas.bytes_per_megabyte')) }}" data-mib-per-gib="{{ config('opsdeck.storage_quotas.mebibytes_per_gibibyte') }}" data-used-bytes="{{ $storageQuota['used_bytes'] }}" data-bytes-per-mib="{{ config('opsdeck.storage_quotas.bytes_per_megabyte') }}">
                @error('quantity')<p class="errors" role="alert">{{ $message }}</p>@enderror
                <p id="storage-addon-price-help">追加容量は1GiBあたり月額{{ number_format(config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy')) }}円（税別）です。選択数量分を次回更新日から請求します。今月分の日割り請求はありません。</p>
                <dl>
                    <dt>変更後の追加料金</dt><dd><output data-storage-addon-monthly-total aria-live="polite">月額{{ number_format($selectedStorageAddonQuantity * $storageAddonUnitPrice) }}円（税別）</output></dd>
                    <dt>次回更新後の容量上限</dt><dd><output data-storage-addon-limit-after aria-live="polite">{{ number_format($projectedStorageLimitMib / (int) config('opsdeck.storage_quotas.mebibytes_per_gibibyte'), 1) }}GiB</output></dd>
                </dl>
                <p data-storage-addon-capacity-warning role="status" aria-live="polite" @if ($storageQuota['used_bytes'] <= ($projectedStorageLimitMib * (int) config('opsdeck.storage_quotas.bytes_per_megabyte'))) hidden @endif>この容量に変更すると現在の使用量が上限を超えます。次回更新日以降、新しいファイルを保存できません。既存ファイルは削除されません。</p>
                <p id="storage-addon-timing-help">増量・減量・0GiBへの変更は次回更新日に反映します。それまでは現在の容量を維持します。保存済みファイルは削除されません。新しい上限を超える場合は、反映後の新規アップロードが一時停止します。</p>
                <label><input type="checkbox" name="confirm_change" value="1" required @checked(old('confirm_change'))> 請求と容量の反映日を確認しました</label>
                @error('confirm_change')<p class="errors" role="alert">{{ $message }}</p>@enderror
                <button type="submit">容量の変更を次回更新日に予約する</button>
            </form>
        @elseif (config('opsdeck.release_features.storage_addon') && $effectivePlanCode === config('opsdeck.premium_plan_code'))
            @if ($organization->billing_cancel_at_period_end || $organization->billing_cancel_at !== null)
                <p class="muted">解約予約中は追加容量の変更予約を受け付けていません。既に予約済みの変更がある場合は、終了予定日までにCustomer Portalで契約内容をご確認ください。</p>
            @else
                <p class="muted">追加容量の申込みは現在準備中です。共有保存枠とStripe設定を確認できるまで変更できません。</p>
            @endif
        @elseif (config('opsdeck.release_features.storage_addon'))
            <p class="muted">追加容量はPremium契約中にご利用いただけます。</p>
        @endif
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
                        <td>{{ config('opsdeck.release_features.'.$capability, true) && $freePlan['capabilities'][$capability] ? '利用可' : '—' }}</td>
                        <td>{{ config('opsdeck.release_features.'.$capability, true) && $premiumPlan['capabilities'][$capability] ? '利用可' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($organization->stripe_customer_id)
            <form method="post" action="{{ route('billing.portal') }}">@csrf<button type="submit">{{ $organization->billing_status === 'past_due' ? '支払い方法・請求状況を確認する' : 'Customer Portalを開く' }}</button></form>
        @endif
        @if ($checkoutAvailable && $effectivePlanCode !== config('opsdeck.premium_plan_code') && ! in_array($organization->billing_status, ['active', 'trialing', 'past_due'], true))
            <form method="post" action="{{ route('billing.checkout') }}">
                @csrf
                @if (config('opsdeck.release_features.storage_addon') && $checkoutStorageAddonMaximumQuantity !== null && $checkoutStorageAddonMaximumQuantity > 0)
                    <label for="checkout-storage-addon-quantity">Premiumと一緒に申し込む追加容量（GiB）</label>
                    <select id="checkout-storage-addon-quantity" name="storage_addon_gib" aria-describedby="checkout-storage-addon-help">
                        <option value="0" @selected((int) old('storage_addon_gib', 0) === 0)>追加なし（追加料金なし）</option>
                        @for ($quantity = 1; $quantity <= $checkoutStorageAddonMaximumQuantity; $quantity++)
                            <option value="{{ $quantity }}" @selected((int) old('storage_addon_gib', 0) === $quantity)>
                                {{ number_format($quantity) }}GiB（追加 月額{{ number_format($quantity * (int) config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy')) }}円・税別）
                            </option>
                        @endfor
                    </select>
                    <p id="checkout-storage-addon-help">追加容量は1GiBあたり月額{{ number_format(config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy')) }}円（税別）です。選択した追加料金はPremiumの初回請求から加算され、以後も同じ月額で請求されます。税額を含む合計はStripe Checkoutで支払い前に確認できます。Premium契約後の容量変更は、従来どおり次回更新日から反映されます。</p>
                @elseif (config('opsdeck.release_features.storage_addon') && $checkoutStorageAddonMaximumQuantity === 0)
                    <p class="muted">今回の申し込みで選べる追加容量はありません。Premiumの申し込みは続けられます。</p>
                @endif
                <button type="submit">Stripe Checkoutを開始</button>
            </form>
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
                <select id="reason" name="reason" required>
                    @foreach (config('opsdeck.cancellation_feedback.reasons', []) as $reasonCode => $reasonLabel)
                        <option value="{{ $reasonCode }}" @selected(old('reason') === $reasonCode)>{{ $reasonLabel }}</option>
                    @endforeach
                </select>
                <label for="details">補足（任意）</label>
                <textarea id="details" name="details" maxlength="2000">{{ old('details') }}</textarea>
                <button type="submit">送信</button>
            </form>
        </section>
    @endif
</x-layouts.app>
