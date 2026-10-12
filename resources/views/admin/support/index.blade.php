<x-layouts.app>
    <section class="card">
        <h1>お問い合わせ管理</h1>
        <form method="get"><label for="status">状態で絞り込む</label><select id="status" name="status"><option value="">すべて</option><option value="open" @selected($status === 'open')>受付</option><option value="in_progress" @selected($status === 'in_progress')>対応中</option><option value="resolved" @selected($status === 'resolved')>解決済み</option></select><label for="reference_code">受付番号</label><input id="reference_code" name="reference_code" value="{{ request('reference_code') }}" maxlength="40"><label for="error_reference">エラー照会ID</label><input id="error_reference" name="error_reference" value="{{ $errorReference }}" maxlength="80"><button type="submit">絞り込む</button></form>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <table><thead><tr><th>受付番号</th><th>エラー照会ID</th><th>カテゴリ</th><th>状態</th><th>受付日時</th><th>更新</th></tr></thead><tbody>
            @forelse ($requests as $request)<tr><td>{{ $request->reference_code ?? '—' }}</td><td>{{ $request->error_reference ?? '—' }}</td><td>{{ $request->category }}</td><td>{{ $request->status }}</td><td>{{ $request->created_at->format('Y-m-d H:i') }}</td><td><form method="post" action="{{ route('admin.support.update', $request) }}">@csrf @method('put')<select name="status"><option value="open" @selected($request->status === 'open')>受付</option><option value="in_progress" @selected($request->status === 'in_progress')>対応中</option><option value="resolved" @selected($request->status === 'resolved')>解決済み</option></select><button type="submit">更新</button></form></td></tr>@empty<tr><td colspan="6">お問い合わせはありません。</td></tr>@endforelse
        </tbody></table>
        {{ $requests->links() }}
    </section>
</x-layouts.app>
