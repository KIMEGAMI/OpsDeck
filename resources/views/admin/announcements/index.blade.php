<x-layouts.app>
    <section class="card" aria-labelledby="announcements-title">
        <h1 id="announcements-title">お知らせ管理</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p><a href="{{ route('admin.announcements.create') }}">新しいお知らせを作成</a></p>
        <p><a href="{{ route('admin.mail-delivery-reviews.index') }}">メール配信の手動確認</a></p>
        <table>
            <thead><tr><th>タイトル</th><th>公開日時</th><th>送信対象数</th><th>送信済み</th><th>失敗・手動確認・停止</th></tr></thead>
            <tbody>
                @forelse ($announcements as $announcement)
                    <tr><td>{{ $announcement->title }}</td><td>{{ $announcement->published_at?->format('Y-m-d H:i') ?? '未公開' }}</td><td>{{ $announcement->recipients_count }}</td><td>{{ $announcement->sent_count }}</td><td>失敗 {{ $announcement->failed_count }} / 手動確認 {{ $announcement->manual_review_count }} / 停止 {{ $announcement->suppressed_count }} @if ($announcement->failed_count > 0)<form method="post" action="{{ route('admin.announcements.retry', $announcement) }}" style="display:inline" onsubmit="if (!confirm('失敗したメールを再送しますか？')) { return false; } this.querySelector('button[type=submit]').disabled = true; return true;">@csrf<button type="submit">再送</button></form>@endif</td></tr>
                @empty
                    <tr><td colspan="5">お知らせはありません。</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $announcements->links() }}
    </section>
</x-layouts.app>
