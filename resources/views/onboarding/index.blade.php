<x-layouts.app>
    <section class="card" aria-labelledby="onboarding-title">
        <h1 id="onboarding-title">導入チェックリスト</h1>
        <p class="muted">{{ $organization->name }}の情報を登録する準備を、できるものから進めます。完了状況は必要な情報を登録・確認すると自動で更新されます。</p>
        <p><a href="{{ route('feature-guide.index') }}">画像付きの機能ガイドで操作手順を見る</a></p>
        @if ($premiumFeaturesUnavailable)
            <p class="muted">社員{{ number_format(config('opsdeck.plans.free.limits.employees')) }}名、資産{{ number_format(config('opsdeck.plans.free.limits.assets')) }}件、SaaSサービス{{ number_format(config('opsdeck.plans.free.limits.saas_services')) }}件まで登録できます。現在提供している機能はすべて無料です。Google Sheets連携は現在提供していません。</p>
        @endif
        <p><strong>{{ $completed }}/{{ $total }} 完了（{{ $percent }}%）</strong></p>
        @if ($percent === 100)
            @if ($premiumFeaturesUnavailable)
                <p role="status"><strong>無料で使う準備ができました</strong>。登録上限内で情報を取り込み、ダッシュボードを利用できます。</p>
            @else
                <p role="status"><strong>導入完了</strong>です。日々の更新期限・コスト・セキュリティ状態を確認できます。</p>
            @endif
        @endif
        <progress value="{{ $percent }}" max="100" aria-label="導入チェックリストの進捗">{{ $percent }}%</progress>
        <ol>
            @foreach ($steps as $step)
                <li style="margin: .75rem 0">
                    @if ($step['completed'])
                        <span class="onboarding-step-status onboarding-step-status--complete"><span aria-hidden="true">✅</span> 完了</span> <a href="{{ route($step['route'], $step['route_parameters']) }}"><strong>{{ $step['label'] }}</strong></a>
                    @else
                        <span class="onboarding-step-status onboarding-step-status--upcoming"><span aria-hidden="true">⬜</span> これから</span> <a href="{{ route($step['route'], $step['route_parameters']) }}">{{ $step['label'] }}</a>
                    @endif
                    @foreach ($step['alternatives'] as $alternative)
                        <span>または <a href="{{ route($alternative['route'], $alternative['route_parameters']) }}">{{ $alternative['label'] }}</a></span>
                    @endforeach
                </li>
            @endforeach
        </ol>
    </section>
</x-layouts.app>
