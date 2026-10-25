<?php

namespace App\Http\Controllers;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Requests\AcceptOrganizationInvitationRequest;
use App\Http\Requests\StoreOrganizationInvitationRequest;
use App\Jobs\SendOrganizationInvitationEmail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OnboardingProgressService;
use App\Services\PlanLimitService;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class OrganizationInvitationController extends Controller
{
    public function store(StoreOrganizationInvitationRequest $request): RedirectResponse
    {
        $organization = $request->attributes->get('currentOrganization');
        $email = strtolower($request->string('email')->toString());
        $role = MembershipRole::from($request->string('role')->toString());
        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($request, $organization, $email, $role, $token): OrganizationInvitation {
            $lockedOrganization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            if ($role === MembershipRole::Admin) {
                app(PlanLimitService::class)->assertCanAdd($lockedOrganization, 'administrators');
            }
            abort_if(OrganizationMembership::query()->where('organization_id', $lockedOrganization->id)->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))->exists(), 422, '既にメンバー登録されています。');
            abort_if(OrganizationInvitation::query()->where('organization_id', $lockedOrganization->id)->whereRaw('LOWER(email) = ?', [$email])->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 422, '有効な招待が既に存在します。');

            $invitation = OrganizationInvitation::query()->create(['organization_id' => $lockedOrganization->id, 'invited_by' => $request->user()->id, 'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(config('opsdeck.invitation_expiry_days'))]);
            AuditLog::query()->create([
                'organization_id' => $lockedOrganization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => OrganizationInvitation::class,
                'entity_id' => $invitation->id,
                'action' => AuditLog::OrganizationInvitationCreated,
                'after_json' => ['role' => $role->value, 'status' => 'pending'],
            ]);

            return $invitation;
        });
        try {
            SendOrganizationInvitationEmail::dispatch($invitation, $token);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($request, $organization, $invitation): void {
                $lockedInvitation = OrganizationInvitation::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey($invitation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedInvitation->isUsable()) {
                    return;
                }

                $lockedInvitation->update([
                    'revoked_at' => now(),
                    'invitation_email_failed_at' => now(),
                ]);
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => OrganizationInvitation::class,
                    'entity_id' => $lockedInvitation->id,
                    'action' => AuditLog::OrganizationInvitationDispatchFailed,
                    'before_json' => ['status' => 'pending'],
                    'after_json' => ['status' => 'revoked', 'reason' => 'queue_dispatch_failed'],
                ]);
            });
            Log::warning('Organization invitation queue dispatch failed.', [
                'organization_id' => $organization->id,
                'invitation_id' => $invitation->id,
                'exception_class' => $exception::class,
            ]);

            return to_route('settings.members')->withErrors([
                'invitation' => '送信キューに登録できなかったため、この招待を無効にしました。同じメールアドレスへ再度招待してください。',
            ]);
        }
        app(ProductEventRecorder::class)->record('member_invited', $organization);
        app(OnboardingProgressService::class)->recordCompletionIfReady($organization);

        return to_route('settings.members')->with('status', '招待メールを送信キューに追加しました。');
    }

    public function revoke(Request $request, int $invitation): RedirectResponse
    {
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageMembers', $organization);

        DB::transaction(function () use ($request, $organization, $invitation): void {
            $lockedOrganization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $lockedInvitation = OrganizationInvitation::query()
                ->where('organization_id', $lockedOrganization->id)
                ->whereKey($invitation)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedInvitation->isUsable(), 409, '有効な招待だけ取り消せます。');

            $lockedInvitation->update(['revoked_at' => now()]);
            AuditLog::query()->create([
                'organization_id' => $lockedOrganization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => OrganizationInvitation::class,
                'entity_id' => $lockedInvitation->id,
                'action' => AuditLog::OrganizationInvitationRevoked,
                'before_json' => ['status' => 'pending'],
                'after_json' => ['status' => 'revoked'],
            ]);
        });

        return to_route('settings.members')->with('status', '招待を取り消しました。');
    }

    public function show(string $token): View
    {
        $invitation = $this->invitation($token);
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists();
        if (auth()->guest() && $existingUser) {
            request()->session()->put('url.intended', route('invitations.show', ['token' => $token], false));
        }

        return view('organization-invitations.accept', compact('invitation', 'token', 'existingUser'));
    }

    public function accept(AcceptOrganizationInvitationRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->invitation($token);
        abort_if($request->user() !== null && strtolower($request->user()->email) !== strtolower($invitation->email), 403, '招待先メールアドレスとログイン中のアカウントが一致しません。');
        DB::transaction(function () use ($request, $invitation): void {
            $organization = Organization::query()->whereKey($invitation->organization_id)->lockForUpdate()->firstOrFail();
            $lockedInvitation = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $this->abortUnlessUsable($lockedInvitation);
            if ($lockedInvitation->role === MembershipRole::Admin) {
                app(PlanLimitService::class)->assertCanAdd($organization, 'administrators', $lockedInvitation);
            }
            $user = $request->user();
            if ($user === null) {
                $user = User::query()->create(['name' => $request->string('name')->toString(), 'email' => $lockedInvitation->email, 'password' => $request->string('password')->toString()]);
                $user->markEmailAsVerified();
                Auth::login($user);
            } else {
                $user->update(['name' => $request->string('name')->toString()]);
                if ($user->email_verification_required_at !== null && $user->email_verified_at === null) {
                    $user->markEmailAsVerified();
                }
            }
            $membership = OrganizationMembership::query()->updateOrCreate(['organization_id' => $organization->id, 'user_id' => $user->id], ['role' => $lockedInvitation->role, 'status' => MembershipStatus::Active, 'invited_at' => $lockedInvitation->created_at, 'joined_at' => now()]);
            $lockedInvitation->update(['accepted_at' => now()]);
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $user->id,
                'entity_type' => OrganizationMembership::class,
                'entity_id' => $membership->id,
                'action' => AuditLog::OrganizationMemberJoined,
                'after_json' => ['role' => $membership->role->value, 'status' => $membership->status->value],
            ]);
            app(ProductEventRecorder::class)->record('member_joined', $organization);
        });
        $request->session()->regenerate();
        $request->session()->put('current_organization_id', $invitation->organization_id);

        return to_route('dashboard')->with('status', '組織への参加が完了しました。');
    }

    private function invitation(string $token): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()->with('organization')->where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->abortUnlessUsable($invitation);

        return $invitation;
    }

    private function abortUnlessUsable(OrganizationInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            abort(410, 'この招待は既に使用済みです。');
        }

        if ($invitation->revoked_at !== null) {
            abort(410, 'この招待は取り消されています。');
        }

        abort_if(! $invitation->expires_at->isFuture(), 410, 'この招待は有効期限を過ぎています。');
    }
}
