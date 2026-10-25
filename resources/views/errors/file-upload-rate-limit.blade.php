<x-layouts.app>
    <section class="card" aria-labelledby="upload-rate-limit-title">
        <h1 id="upload-rate-limit-title">アップロードを少しお待ちください</h1>
        <p>短い時間にアップロードが続いたため、一時的に受付を止めました。</p>
        <p>ファイルはOpsDeckに保存されていません。約{{ max(1, (int) ceil($retryAfterSeconds / 60)) }}分待ってから、もう一度ファイルを選んでください。</p>
        <p><a href="{{ route('dashboard') }}">ダッシュボードへ戻る</a></p>
    </section>
</x-layouts.app>
