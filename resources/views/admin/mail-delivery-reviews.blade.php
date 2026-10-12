<x-layouts.app>
    <section class="card" aria-labelledby="delivery-reviews-title">
        <h1 id="delivery-reviews-title">メール配信の手動確認</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p>この画面からメールは再送されません。メール事業者の配信履歴で成功を確認した場合だけ、配信済みとして記録してください。</p>
        <p><a href="{{ route('admin.announcements.index') }}">お知らせ管理へ戻る</a></p>

        <section aria-labelledby="announcement-review-title">
            <h2 id="announcement-review-title">管理者お知らせ</h2>
            <table>
                <thead><tr><th>お知らせ</th><th>宛先</th><th>確認待ちになった日時</th><th>操作</th></tr></thead>
                <tbody>
                    @forelse ($announcementDeliveries as $delivery)
                        <tr>
                            <td>{{ $delivery->announcement?->title ?? '削除済みのお知らせ' }}</td>
                            <td>{{ $delivery->user?->email ?? '削除済みユーザー' }}</td>
                            <td>{{ $delivery->manual_review_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <form method="post" action="{{ route('admin.mail-delivery-reviews.announcements.resolve', $delivery) }}">
                                    @csrf
                                    <label><input type="checkbox" name="confirm_delivery" value="1" required style="width:auto"> 事業者の履歴で配信成功を確認しました</label>
                                    <button type="submit">配信済みとして記録</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">確認待ちのお知らせメールはありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $announcementDeliveries->links() }}
        </section>

        <section aria-labelledby="weekly-review-title">
            <h2 id="weekly-review-title">週次サマリー</h2>
            <table>
                <thead><tr><th>組織</th><th>週</th><th>宛先</th><th>確認待ちになった日時</th><th>操作</th></tr></thead>
                <tbody>
                    @forelse ($weeklySummaryDeliveries as $delivery)
                        <tr>
                            <td>{{ $delivery->organization?->name ?? '削除済み組織' }}</td>
                            <td>{{ $delivery->week_start?->toDateString() }}</td>
                            <td>{{ $delivery->user?->email ?? '削除済みユーザー' }}</td>
                            <td>{{ $delivery->manual_review_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <form method="post" action="{{ route('admin.mail-delivery-reviews.weekly-summaries.resolve', $delivery) }}">
                                    @csrf
                                    <label><input type="checkbox" name="confirm_delivery" value="1" required style="width:auto"> 事業者の履歴で配信成功を確認しました</label>
                                    <button type="submit">配信済みとして記録</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">確認待ちの週次サマリーメールはありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $weeklySummaryDeliveries->links() }}
        </section>
    </section>
</x-layouts.app>
