<section class="card" aria-labelledby="storage-quota-title" role="status">
    <h2 id="storage-quota-title">会社の保存容量</h2>
    <p>現在 {{ $quota['used_display'] }} ／ 上限 {{ $quota['limit_display'] }}（{{ $quota['used_percentage'] }}%）</p>
    @if ($quota['remaining_bytes'] === 0)
        <p>新しく保存できる残り容量はありません。</p>
    @else
        <p>あと約 {{ $quota['remaining_display'] }} 保存できます。</p>
    @endif
    <progress value="{{ min(100, $quota['used_percentage']) }}" max="100" aria-label="会社の保存容量の使用率"></progress>
    <p class="muted">取込元ファイルと証跡ファイルの合計です。登録した社員や資産などの件数は、ここには含まれません。</p>
    <p class="muted">組織ごとの上限は{{ $quota['limit_display'] }}固定です。追加容量は提供していません。</p>
    @if ($quota['remaining_bytes'] === 0)
        <p class="errors">保存容量の上限に達しました。新しいファイルをアップロードできません。すでに登録したデータとファイルは削除されません。不要な取込ファイルや証跡ファイルを整理してください。</p>
    @elseif ($quota['warning'])
        <p class="errors">保存容量が警告目安の{{ $quota['warning_percentage'] }}%に達しています。上限に達すると新しいファイルをアップロードできなくなります。すでに登録したデータとファイルは削除されません。会社の管理者にご相談ください。</p>
    @else
        <p class="muted">上限に近づくと、この画面でお知らせします。</p>
    @endif
</section>
