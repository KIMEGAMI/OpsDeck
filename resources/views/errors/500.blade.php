<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>一時的なエラー | {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; background: #f4f7fb; color: #172033; }
        body { margin: 0; padding: 2rem 1rem; }
        main { box-sizing: border-box; max-width: 42rem; margin: 8vh auto; padding: 2rem; background: #ffffff; border: 1px solid #bcccdc; border-radius: .5rem; }
        h1 { margin-top: 0; color: #102a43; }
        p { line-height: 1.7; }
        .reference { display: inline-block; padding: .5rem .75rem; color: #102a43; background: #e6f6f8; border: 1px solid #006d77; border-radius: .25rem; font-family: ui-monospace, monospace; overflow-wrap: anywhere; }
        a { color: #005f73; font-weight: 700; }
    </style>
</head>
<body>
    <main>
        <h1>一時的なエラーが発生しました</h1>
        <p>処理を完了できませんでした。時間をおいて再度お試しください。</p>
        @if (isset($errorReference) && is_string($errorReference))
            <p>サポートへお問い合わせの際は、次のエラー参照番号をお伝えください。</p>
            <p class="reference" aria-label="エラー参照番号">{{ $errorReference }}</p>
            <p><a href="{{ route('help.index') }}">ヘルプを確認する</a> ／ <a href="{{ route('support.create', ['category' => 'other', 'error_reference' => $errorReference]) }}">エラー番号を添えて問い合わせる</a></p>
        @else
            <p>ヘルプをご確認のうえ、必要な場合はサポートへお問い合わせください。</p>
            <p><a href="{{ route('help.index') }}">ヘルプを確認する</a> ／ <a href="{{ route('support.create', ['category' => 'other']) }}">サポートへ問い合わせる</a></p>
        @endif
    </main>
</body>
</html>
