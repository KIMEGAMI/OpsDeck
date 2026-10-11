<x-layouts.app>
    <section class="card" aria-labelledby="members-title">
        <h1 id="members-title">組織メンバー</h1>
        <p class="muted">{{ $organization->name }} の有効なメンバー一覧です。</p>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @can('manageMembers', $organization)
            <h2>メンバーを招待</h2>
            <form method="post" action="{{ route('settings.members.invitations.store') }}">
                @csrf
                <label for="invitation_email">メールアドレス</label>
                <input id="invitation_email" type="email" name="email" required maxlength="255">
                <label for="invitation_role">ロール</label>
                <select id="invitation_role" name="role"><option value="admin">admin</option><option value="operator">operator</option><option value="viewer">viewer</option></select>
                <button type="submit">招待メールを送信</button>
            </form>
        @endcan
        <table>
            <thead><tr><th>氏名</th><th>メールアドレス</th><th>ロール</th><th>ステータス</th><th>参加日</th><th>更新</th></tr></thead>
            <tbody>
                @forelse ($memberships as $membership)
                    <tr><td>{{ $membership->user->name }}</td><td>{{ $membership->user->email }}</td><td>{{ $membership->role->value }}</td><td>{{ $membership->status->value }}</td><td>{{ $membership->joined_at?->toDateString() ?? '—' }}</td><td>@can('manageMembers', $organization)<form method="post" action="{{ route('settings.members.update', $membership) }}">@csrf @method('put')<select name="role"><option value="owner" @selected($membership->role->value === 'owner')>owner</option><option value="admin" @selected($membership->role->value === 'admin')>admin</option><option value="operator" @selected($membership->role->value === 'operator')>operator</option><option value="viewer" @selected($membership->role->value === 'viewer')>viewer</option></select><select name="status"><option value="active" @selected($membership->status->value === 'active')>active</option><option value="suspended" @selected($membership->status->value === 'suspended')>suspended</option></select><button type="submit">更新</button></form>@endcan</td></tr>
                @empty
                    <tr><td colspan="6">メンバーはいません。</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $memberships->links() }}

        <h2>招待履歴</h2>
        <table>
            <thead><tr><th>メールアドレス</th><th>ロール</th><th>状態</th><th>有効期限</th><th>送信日時</th><th>操作</th></tr></thead>
            <tbody>
                @forelse ($invitations as $invitation)
                    <tr>
                        <td>{{ $invitation->email }}</td>
                        <td>{{ $invitation->role->value }}</td>
                        <td>{{ match ($invitation->lifecycleStatus()) { 'accepted' => '使用済み', 'expired' => '期限切れ', 'revoked' => '取消済み', default => '招待中' } }}</td>
                        <td>{{ $invitation->expires_at->timezone($organization->timezone)->format('Y-m-d H:i') }}</td>
                        <td>{{ $invitation->created_at->timezone($organization->timezone)->format('Y-m-d H:i') }}</td>
                        <td>
                            @if ($invitation->isUsable() && auth()->user()->can('manageMembers', $organization))
                                <form method="post" action="{{ route('settings.members.invitations.revoke', $invitation) }}">
                                    @csrf
                                    @method('delete')
                                    <button type="submit">招待を取り消す</button>
                                </form>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">招待履歴はありません。</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $invitations->links() }}
    </section>
</x-layouts.app>
