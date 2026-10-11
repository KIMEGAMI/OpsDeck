<x-layouts.app>
    <section class="card" aria-labelledby="onboarding-title">
        <h1 id="onboarding-title">導入チェックリスト</h1>
        <p class="muted">{{ $organization->name }}で最初に確認する項目です。完了状況はデータ登録と実際の画面操作に応じて組織ごとに保存されます。</p>
        @if ($premiumFeaturesUnavailable)
            <p class="muted">Freeプランで利用できるCSVプレビューを進めましょう。業務データへのImport実行、更新期限アラート、Security ControlはPremiumプランの機能です。</p>
            <p><a href="{{ route('billing.index') }}">Free / Premiumの機能を確認</a></p>
        @endif
        <p><strong>{{ $completed }}/{{ $total }} 完了（{{ $percent }}%）</strong></p>
        @if ($percent === 100)
            @if ($premiumFeaturesUnavailable)
                <p role="status"><strong>Freeプランの初期設定が完了</strong>しました。引き続きCSVプレビューと基本ダッシュボードを利用できます。</p>
            @else
                <p role="status"><strong>導入完了</strong>です。日々の更新期限・コスト・セキュリティ状態を確認できます。</p>
            @endif
        @endif
        <progress value="{{ $percent }}" max="100" aria-label="導入チェックリストの進捗">{{ $percent }}%</progress>
        <ol>
            @foreach ($steps as $step)
                <li style="margin: .75rem 0">
                    @if ($step['completed'])
                        <span aria-label="完了">✅</span> <strong>{{ $step['label'] }}</strong>
                    @else
                        <span aria-label="未完了">⬜</span> <a href="{{ route($step['route']) }}">{{ $step['label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
</x-layouts.app>
