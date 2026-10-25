<x-layouts.app>
    <section class="card" aria-labelledby="security-control-title">
        <h1 id="security-control-title">{{ $control->title }}</h1>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        <dl>
            <dt>コード</dt><dd>{{ $control->control_code }}</dd>
            <dt>カテゴリ</dt><dd>{{ $control->category }}</dd>
            <dt>状態</dt><dd>{{ config('opsdeck.security_control_status_labels.'.$control->status, $control->status) }}</dd>
            <dt>説明</dt><dd>{{ $control->description }}</dd>
            <dt>担当者</dt><dd>{{ $control->owner?->name ?? '未設定' }}</dd>
            <dt>確認する頻度</dt><dd>{{ $control->review_frequency ?? '未設定' }}</dd>
            <dt>最終確認日</dt><dd>{{ $control->last_reviewed_at?->format('Y-m-d') ?? '未確認' }}</dd>
            <dt>次回確認日</dt><dd>{{ $control->next_review_at?->format('Y-m-d') ?? '未設定' }}</dd>
            <dt>メモ</dt><dd>{{ $control->memo ?: 'なし' }}</dd>
        </dl>

        @can('update', $control)
            <form method="post" action="{{ route('security-controls.review', $control) }}">
                @csrf
                <p class="muted">内容を確認したら、今日の日付を最終確認日として記録できます。</p>
                <button type="submit">今日、確認しました</button>
            </form>
            <p><a href="{{ route('security-controls.edit', $control) }}">編集する</a></p>
        @endcan

        <p><a href="{{ route('security-controls.index') }}">一覧へ戻る</a></p>
    </section>
</x-layouts.app>
