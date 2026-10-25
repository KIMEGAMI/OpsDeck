<x-layouts.app>
    <section class="card">
        <h1>Googleスプレッドシートから取り込む</h1>
        <p class="muted">取り込む表を選びます。この操作では、会社のデータはまだ登録・変更されません。</p>
        <p class="muted">次の画面でシートを選び、内容を確認してから取り込みます。1回に取り込めるのは最大{{ number_format(config('opsdeck.import.max_rows')) }}行です。</p>
        @if ($spreadsheets !== [])
            <form method="post" action="{{ route('imports.google-sheets.spreadsheet') }}">
                @csrf
                <label for="spreadsheet_id">取り込む表</label>
                <select id="spreadsheet_id" name="spreadsheet_id" required>
                    <option value="" selected disabled>表を選んでください</option>
                    @foreach ($spreadsheets as $spreadsheet)
                        <option value="{{ $spreadsheet['id'] }}">{{ $spreadsheet['name'] }}</option>
                    @endforeach
                </select>
                <label for="source_name">履歴で見つけるための名前</label>
                <input id="source_name" name="source_name" required maxlength="255" value="{{ old('source_name') }}">
                <p class="muted">例：今使っている社員名簿。後で履歴を見たとき、何の表か分かる名前にしてください。</p>
                <button type="submit">選んだ表のシートを確認する</button>
            </form>
        @else
            <div role="status">
                <h2>取り込める表が見つかりません</h2>
                <p>次の2点を確認してください。</p>
                <ol>
                    <li>接続したGoogleアカウントが、取り込みたい表を開けること。</li>
                    <li>この組織のGoogle連携が、使いたいアカウントで接続されていること。</li>
                </ol>
                <p><a href="{{ route('google-sheets.connection') }}">Google連携の状態を確認する</a></p>
                <p class="muted">確認後、この画面を開き直してください。</p>
            </div>
        @endif
    </section>
</x-layouts.app>
