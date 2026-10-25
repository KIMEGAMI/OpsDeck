<x-layouts.app>
    <section class="card" aria-labelledby="members-title">
        <h1 id="members-title">組織メンバー</h1>
        <p class="muted">{{ $organization->name }} の有効なメンバー一覧です。</p>
        @if (($filters['membership_id'] ?? null) !== null)
            <p role="status">退職チェックリストの対象メンバーを表示しています。</p>
            <p><a href="{{ route('settings.members') }}">全メンバーを表示する</a></p>
        @endif
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($errors->has('invitation'))<p class="errors" role="alert">{{ $errors->first('invitation') }}</p>@endif
        @can('manageMembers', $organization)
            <h2>メンバーを招待</h2>
            <p class="muted">管理者はメンバー管理と質問票の承認、担当者は台帳の登録・更新、閲覧のみは情報の確認ができます。</p>
            @php
                $invitationRoles = [\App\Enums\MembershipRole::Admin, \App\Enums\MembershipRole::Operator, \App\Enums\MembershipRole::Viewer];
                $oldInvitationRole = old('role');
                $selectedInvitationRole = collect($invitationRoles)->contains(fn ($role) => $role->value === $oldInvitationRole)
                    ? $oldInvitationRole
                    : \App\Enums\MembershipRole::Operator->value;
            @endphp
            <form method="post" action="{{ route('settings.members.invitations.store') }}">
                @csrf
                <label for="invitation_email">メールアドレス</label>
                <input id="invitation_email" type="email" name="email" value="{{ old('email') }}" required maxlength="255" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                <x-field-error field="email" />

                <label for="invitation_role">権限</label>
                <select id="invitation_role" name="role" @if ($errors->has('role') && ! old('membership_id')) aria-invalid="true" aria-describedby="role-error" @endif>
                    @foreach ($invitationRoles as $role)
                        <option value="{{ $role->value }}" @selected($selectedInvitationRole === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
                @if (! old('membership_id'))
                    <x-field-error field="role" />
                @endif
                <button type="submit">招待メールを送信</button>
            </form>
        @endcan
        <table>
            <thead><tr><th>氏名</th><th>メールアドレス</th><th>ロール</th><th>ステータス</th><th>参加日</th><th>更新</th></tr></thead>
            <tbody>
                @forelse ($memberships as $membership)
                    <tr>
                        <td>{{ $membership->user->name }}</td>
                        <td>{{ $membership->user->email }}</td>
                        <td>
                            {{ $membership->role->label() }}
                            <br><small class="muted">{{ $membership->role->description() }}</small>
                        </td>
                        <td>{{ $membership->status->label() }}</td>
                        <td>{{ $membership->joined_at?->toDateString() ?? '—' }}</td>
                        <td>
                            @can('manageMembers', $organization)
                                @php
                                    $isTargetedMemberInput = (string) old('membership_id') === (string) $membership->id;
                                    $oldRole = old('role');
                                    $oldStatus = old('status');
                                    $canRestoreOldRole = $isTargetedMemberInput && collect(\App\Enums\MembershipRole::cases())->contains(fn ($candidate) => $candidate->value === $oldRole);
                                    $canRestoreOldStatus = $isTargetedMemberInput && collect(\App\Enums\MembershipStatus::cases())->contains(fn ($candidate) => $candidate->value === $oldStatus);
                                @endphp
                                <form method="post" action="{{ route('settings.members.update', $membership) }}">
                                    @csrf
                                    @method('put')
                                    <input type="hidden" name="membership_id" value="{{ $membership->id }}">
                                    <label for="member-role-{{ $membership->id }}">権限</label>
                                    <select id="member-role-{{ $membership->id }}" name="role" @if ($errors->has('role') && $isTargetedMemberInput) aria-invalid="true" aria-describedby="member-role-error-{{ $membership->id }}" @endif>
                                        @foreach (\App\Enums\MembershipRole::cases() as $role)
                                            <option value="{{ $role->value }}" @selected($canRestoreOldRole ? $oldRole === $role->value : $membership->role === $role)>{{ $role->label() }}</option>
                                        @endforeach
                                    </select>
                                    @if ($isTargetedMemberInput)
                                        <x-field-error field="role" id="member-role-error-{{ $membership->id }}" />
                                    @endif
                                    <label for="member-status-{{ $membership->id }}">利用状態</label>
                                    <select id="member-status-{{ $membership->id }}" name="status" @if ($errors->has('status') && $isTargetedMemberInput) aria-invalid="true" aria-describedby="member-status-error-{{ $membership->id }}" @endif>
                                        @foreach (\App\Enums\MembershipStatus::cases() as $status)
                                            <option value="{{ $status->value }}" @selected($canRestoreOldStatus ? $oldStatus === $status->value : $membership->status === $status)>{{ $status->label() }}</option>
                                        @endforeach
                                    </select>
                                    @if ($isTargetedMemberInput)
                                        <x-field-error field="status" id="member-status-error-{{ $membership->id }}" />
                                    @endif
                                    <button type="submit">変更を保存</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">メンバーはいません。</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $memberships->links() }}

        <h2>招待履歴</h2>
        <p class="muted">送信受付済みはメール事業者が受付した状態で、受信箱への到達を保証するものではありません。送信状況未記録は送信前や過去の招待など、メール送信記録がない状態です。</p>
        <div class="table-scroll" role="region" aria-label="招待履歴" tabindex="0">
            <table>
                <thead><tr><th>メールアドレス</th><th>ロール</th><th>招待状態</th><th>メール送信状況</th><th>有効期限</th><th>招待作成日時</th><th>操作</th></tr></thead>
                <tbody>
                    @forelse ($invitations as $invitation)
                        <tr>
                            <td>{{ $invitation->email }}</td>
                            <td>{{ $invitation->role->label() }}</td>
                            <td>{{ match ($invitation->lifecycleStatus()) { 'accepted' => '使用済み', 'expired' => '期限切れ', 'revoked' => '取消済み', default => '招待中' } }}</td>
                            <td>
                                @if ($invitation->emailDeliveryStatus() === 'manual_review')
                                    <strong>配信状況の確認待ち（再送停止）</strong><br>
                                    <span class="muted">確認完了まで同じ宛先へ再招待しないでください。<a href="mailto:{{ config('opsdeck.support_email') }}">運営サポートに問い合わせる</a>。</span>
                                @elseif ($invitation->emailDeliveryStatus() === 'result_unknown')
                                    <strong>送信結果未確定（確認が必要）</strong><br>
                                    <span class="muted">重複を避けるため、新しい招待は作成しないでください。<a href="mailto:{{ config('opsdeck.support_email') }}">運営サポートへ状況を確認</a>。</span>
                                @else
                                    {{ match ($invitation->emailDeliveryStatus()) {
                                        'accepted' => '送信受付済み',
                                        'failed' => '送信エラー（自動再試行または確認が必要）',
                                        default => '送信状況未記録',
                                    } }}
                                @endif
                            </td>
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
                        <tr><td colspan="7">招待履歴はありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $invitations->links() }}
    </section>
</x-layouts.app>
