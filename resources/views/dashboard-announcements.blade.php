<section class="card" aria-labelledby="announcements-title">
    <h2 id="announcements-title">お知らせ</h2>
    @forelse ($announcements as $announcement)
        <article>
            <h3>{{ $announcement->title }}</h3>
            <p class="muted">{{ $announcement->published_at?->format('Y-m-d H:i') }}</p>
            <p style="white-space: pre-wrap">{{ $announcement->body }}</p>
            @if ($announcement->reads->isNotEmpty())
                <p role="status">既読</p>
            @else
                <form method="post" action="{{ route('announcements.read', $announcement) }}">
                    @csrf
                    <button type="submit">既読にする</button>
                </form>
            @endif
        </article>
    @empty
        <p class="muted">お知らせはありません。</p>
    @endforelse
</section>
