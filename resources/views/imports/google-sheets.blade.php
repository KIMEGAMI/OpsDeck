<x-layouts.app>
    <section class="card">
        <h1>Google SheetsからImport</h1>
        <p class="muted">読み取り専用でSpreadsheetを取得し、選択したシートをPreviewします。</p>
        <p class="muted">1回の取込は最大{{ number_format(config('opsdeck.import.max_rows')) }}行です。超える場合はシートを分割して取り込んでください。</p>
        <form method="post" action="{{ route('imports.google-sheets.spreadsheet') }}">
            @csrf
            <label for="spreadsheet_id">Spreadsheet</label>
            <select id="spreadsheet_id" name="spreadsheet_id" required>
                @foreach ($spreadsheets as $spreadsheet)
                    <option value="{{ $spreadsheet['id'] }}">{{ $spreadsheet['name'] }}</option>
                @endforeach
            </select>
            <label for="source_name">取込名</label>
            <input id="source_name" name="source_name" required maxlength="255" value="{{ old('source_name') }}">
            <button type="submit">シート選択へ進む</button>
        </form>
    </section>
</x-layouts.app>
