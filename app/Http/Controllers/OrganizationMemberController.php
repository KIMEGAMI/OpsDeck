<?php

namespace App\Http\Controllers;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Requests\UpdateOrganizationMemberRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Services\PlanLimitService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrganizationMemberController extends Controller
{
    public function index(): View
    {
        $organization = request()->attributes->get('currentOrganization');
        $this->authorize('view', $organization);
        $memberships = OrganizationMembership::query()
            ->with('user')
            ->where('organization_id', $organization->id)
            ->orderByDesc('role')
            ->orderBy('id')
            ->paginate(config('opsdeck.pagination.per_page'));
        $invitations = OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->latest()
            ->orderByDesc('id')
            ->paginate(config('opsdeck.pagination.per_page'), ['*'], 'invitation_page');

        return view('organization-members.index', compact('organization', 'memberships', 'invitations'));
    }

    public function update(UpdateOrganizationMemberRequest $request, int $membership): RedirectResponse
    {
        $organization = $request->attributes->get('currentOrganization');
        $currentMembership = $request->attributes->get('currentMembership');
        DB::transaction(function () use ($request, $organization, $currentMembership, $membership): void {
            $lockedOrganization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $target = OrganizationMembership::query()->where('organization_id', $lockedOrganization->id)->lockForUpdate()->findOrFail($membership);
            abort_if($target->id === $currentMembership->id, 422, '自分自身のメンバー設定は変更できません。');
            $attributes = $request->validated();
            $newRole = MembershipRole::from($attributes['role']);
            $newStatus = MembershipStatus::from($attributes['status']);
            $isBecomingCountedAdministrator = in_array($newRole, [MembershipRole::Owner, MembershipRole::Admin], true)
                && in_array($newStatus, [MembershipStatus::Active, MembershipStatus::Invited], true)
                && (! in_array($target->role, [MembershipRole::Owner, MembershipRole::Admin], true)
                    || ! in_array($target->status, [MembershipStatus::Active, MembershipStatus::Invited], true));
            if ($isBecomingCountedAdministrator) {
                app(PlanLimitService::class)->assertCanAdd($lockedOrganization, 'administrators');
            }
            $before = $target->only(['role', 'status']);
            $target->update($attributes);
            AuditLog::query()->create(['organization_id' => $lockedOrganization->id, 'actor_user_id' => $request->user()->id, 'entity_type' => OrganizationMembership::class, 'entity_id' => $target->id, 'action' => 'organization.member_updated', 'before_json' => $before, 'after_json' => $target->only(['role', 'status'])]);
        });

        return to_route('settings.members')->with('status', 'メンバー設定を更新しました。');
    }
}
