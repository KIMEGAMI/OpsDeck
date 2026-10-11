<x-layouts.app>
    <section class="card" aria-labelledby="announcement-create-title">
        <h1 id="announcement-create-title">お知らせを作成</h1>
        <p class="muted">公開すると、対象ユーザーごとにメール送信キューへ登録されます。対象組織を未選択にすると全組織が対象です。</p>
        <p class="muted">送信元：{{ config('opsdeck.admin_mail_from_address') }}／問い合わせ先：{{ config('opsdeck.support_email') }}</p>
        <p>全組織配信時の現在の対象ユーザー数：{{ $eligibleRecipientCount }}人（配信停止ユーザーを除く）</p>
        <form method="post" action="{{ route('admin.announcements.store') }}" onsubmit="if (!confirm('表示された配信対象ユーザー数を確認しました。お知らせを公開してメール送信しますか？')) { return false; } this.querySelector('button[type=submit]').disabled = true; return true;">
            @csrf
            <label for="title">タイトル</label>
            <input id="title" name="title" value="{{ old('title') }}" maxlength="200" required>
            <label for="body">本文</label>
            <textarea id="body" name="body" maxlength="10000" required>{{ old('body') }}</textarea>
            <label for="organization_ids">対象組織（未選択：全組織）</label>
            <select id="organization_ids" name="organization_ids[]" multiple size="6" data-recipient-count-url="{{ route('admin.announcements.recipient-count') }}">
                @foreach ($organizations as $organization)
                    <option value="{{ $organization->id }}" @selected(in_array($organization->id, old('organization_ids', []), true))>{{ $organization->name }}</option>
                @endforeach
            </select>
            <p id="recipient-count" aria-live="polite" aria-busy="false">選択中の配信対象ユーザー数：{{ $eligibleRecipientCount }}人</p>
            <button id="announcement-submit" type="submit" aria-disabled="false">公開してメール送信</button>
        </form>
    </section>
    <script>
        let recipientCountRequest = null;
        document.getElementById('organization_ids').addEventListener('change', async (event) => {
            const select = event.currentTarget;
            const organizationIds = Array.from(select.selectedOptions).map((option) => option.value);
            const params = new URLSearchParams();
            document.getElementById('announcement-submit').disabled = true;
            document.getElementById('announcement-submit').setAttribute('aria-disabled', 'true');
            const recipientCountElement = document.getElementById('recipient-count');
            recipientCountElement.setAttribute('aria-busy', 'true');
            recipientCountElement.textContent = '配信対象人数を取得中です…';
            organizationIds.forEach((id) => params.append('organization_ids[]', id));
            recipientCountRequest?.abort();
            recipientCountRequest = new AbortController();
            try {
                const response = await fetch(`${select.dataset.recipientCountUrl}?${params.toString()}`, { headers: { 'Accept': 'application/json' }, signal: recipientCountRequest.signal });
                if (!response.ok) {
                    throw new Error('recipient count request failed');
                }
                const data = await response.json();
                recipientCountElement.setAttribute('aria-busy', 'false');
                recipientCountElement.textContent = `選択中の配信対象ユーザー数：${data.count}人`;
                document.getElementById('announcement-submit').disabled = false;
                document.getElementById('announcement-submit').setAttribute('aria-disabled', 'false');
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }
                recipientCountElement.setAttribute('aria-busy', 'false');
                recipientCountElement.textContent = '配信対象人数を取得できませんでした。選択内容を確認して再試行してください。';
                document.getElementById('announcement-submit').disabled = true;
                document.getElementById('announcement-submit').setAttribute('aria-disabled', 'true');
            }
        });
    </script>
</x-layouts.app>
