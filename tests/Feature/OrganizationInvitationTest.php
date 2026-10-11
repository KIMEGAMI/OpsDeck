<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\SendOrganizationInvitationEmail;
use App\Mail\OrganizationInvitationMail;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_owner_can_create_hashed_invitation_and_queue_email(): void
    {
        Queue::fake();
        Mail::fake();
        [$owner, $organization] = $this->owner();
        $email = 'invite-'.Str::uuid().'@example.test';
        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])->post(route('settings.members.invitations.store'), ['email' => $email, 'role' => 'operator'])->assertRedirect(route('settings.members'));
        $invitation = OrganizationInvitation::query()->where('email', $email)->firstOrFail();
        $this->assertNotSame($email, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertDatabaseHas('product_events', ['event_key' => 'member_invited', 'organization_id' => $organization->id]);
        Queue::assertPushed(SendOrganizationInvitationEmail::class, fn (SendOrganizationInvitationEmail $job): bool => $job->invitation->id === $invitation->id && strlen($job->token) === 64);
        Mail::assertNothingSent();

        $job = Queue::pushed(SendOrganizationInvitationEmail::class)->sole();
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $job->handle();
        Mail::assertSent(OrganizationInvitationMail::class, fn (OrganizationInvitationMail $mail): bool => $mail->hasTo($email));
    }

    public function test_invitation_history_pages_use_a_stable_order_when_creation_times_match(): void
    {
        config(['opsdeck.pagination.per_page' => 1]);
        [$owner, $organization] = $this->owner();
        $this->travelTo('2030-10-01 12:00:00');
        $firstInvitation = $this->invitation($owner, $organization, 'page-one-'.Str::uuid().'@example.test');
        $secondInvitation = $this->invitation($owner, $organization, 'page-two-'.Str::uuid().'@example.test');
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)
            ->get(route('settings.members', ['invitation_page' => 1]))
            ->assertOk()
            ->assertSee($secondInvitation->email)
            ->assertDontSee($firstInvitation->email);

        $this->actingAs($owner)->withSession($session)
            ->get(route('settings.members', ['invitation_page' => 2]))
            ->assertOk()
            ->assertSee($firstInvitation->email)
            ->assertDontSee($secondInvitation->email);
    }

    public function test_final_onboarding_invitation_records_completion_without_checklist_revisit(): void
    {
        Queue::fake();
        [$owner, $organization] = $this->owner();
        OnboardingStep::query()->create([
            'organization_id' => $organization->id,
            'step_key' => OnboardingStep::ORGANIZATION_SETTINGS_STEP,
            'completed_at' => now(),
            'completed_by' => $owner->id,
        ]);
        SaasService::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Onboarding SaaS',
            'status' => 'active',
        ]);
        foreach (['employees', 'assets'] as $targetType) {
            $batch = ImportBatch::query()->create([
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => $targetType.'.csv',
                'status' => 'ready',
            ]);
            $mapping = $batch->mappings()->create([
                'target_type' => $targetType,
                'column_map' => [],
                'normalization_rules' => [],
                'status' => 'ready',
            ]);
            $batch->update(['mapping_id' => (string) $mapping->id]);
        }

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('settings.members.invitations.store'), [
                'email' => 'onboarding-member-'.Str::uuid().'@example.test',
                'role' => 'operator',
            ])
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'onboarding_completed',
        ]);
    }

    public function test_queued_invitation_email_is_skipped_after_the_invitation_is_revoked(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->owner();
        $token = Str::random(64);
        $invitation = $this->invitation($owner, $organization, 'revoked-before-send-'.Str::uuid().'@example.test', [], $token);
        $job = new SendOrganizationInvitationEmail($invitation, $token);
        $invitation->update(['revoked_at' => now()]);

        $job->handle();

        Mail::assertNothingSent();
    }

    public function test_invitation_delivery_rethrows_only_sanitized_transport_errors(): void
    {
        [$owner, $organization] = $this->owner();
        $email = 'private-invitation-'.Str::uuid().'@example.test';
        $token = Str::random(64);
        $invitation = $this->invitation($owner, $organization, $email, [], $token);
        Mail::shouldReceive('to')->once()->with($email)->andThrow(new \RuntimeException('Transport rejected '.$email.' token '.$token));

        $caughtException = null;
        try {
            (new SendOrganizationInvitationEmail($invitation, $token))->handle();
        } catch (\RuntimeException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(\RuntimeException::class, $caughtException);
        $this->assertSame('Organization invitation email delivery failed: RuntimeException', $caughtException->getMessage());
        $this->assertNull($caughtException->getPrevious());
        $this->assertStringNotContainsString($email, $caughtException->getMessage());
        $this->assertStringNotContainsString($token, $caughtException->getMessage());
    }

    public function test_owner_can_see_distinct_invitation_lifecycle_statuses(): void
    {
        [$owner, $organization] = $this->owner();
        $pendingToken = Str::random(64);
        $acceptedToken = Str::random(64);
        $expiredToken = Str::random(64);
        $revokedToken = Str::random(64);
        $pending = $this->invitation($owner, $organization, 'pending-'.Str::uuid().'@example.test', [], $pendingToken);
        $accepted = $this->invitation($owner, $organization, 'accepted-'.Str::uuid().'@example.test', ['accepted_at' => now()], $acceptedToken);
        $expired = $this->invitation($owner, $organization, 'expired-'.Str::uuid().'@example.test', ['expires_at' => now()->subMinute()], $expiredToken);
        $revoked = $this->invitation($owner, $organization, 'revoked-'.Str::uuid().'@example.test', ['revoked_at' => now()], $revokedToken);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('settings.members'))
            ->assertOk()
            ->assertSee($pending->email)
            ->assertSee('招待中')
            ->assertSee($accepted->email)
            ->assertSee('使用済み')
            ->assertSee($expired->email)
            ->assertSee('期限切れ')
            ->assertSee($revoked->email)
            ->assertSee('取消済み');

        $this->get(route('invitations.show', $acceptedToken))->assertGone()->assertSee('既に使用済み');
        $this->get(route('invitations.show', $expiredToken))->assertGone()->assertSee('有効期限を過ぎています');
        $this->get(route('invitations.show', $revokedToken))->assertGone()->assertSee('取り消されています');
    }

    public function test_owner_can_revoke_a_pending_invitation_and_the_action_is_audited(): void
    {
        [$owner, $organization] = $this->owner();
        $token = Str::random(64);
        $invitation = $this->invitation($owner, $organization, 'cancel-'.Str::uuid().'@example.test', [], $token);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->delete(route('settings.members.invitations.revoke', $invitation))
            ->assertRedirect(route('settings.members'))
            ->assertSessionHas('status', '招待を取り消しました。');

        $this->assertSame('revoked', $invitation->fresh()->lifecycleStatus());
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $owner->id,
            'entity_type' => OrganizationInvitation::class,
            'entity_id' => $invitation->id,
            'action' => AuditLog::OrganizationInvitationRevoked,
        ]);
        $this->get(route('invitations.show', $token))->assertGone()->assertSee('取り消されています');
        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->delete(route('settings.members.invitations.revoke', $invitation))
            ->assertConflict();
    }

    public function test_non_owner_cannot_revoke_an_invitation_and_cross_organization_ids_are_not_found(): void
    {
        [$owner, $organization] = $this->owner();
        $invitation = $this->invitation($owner, $organization, 'protected-'.Str::uuid().'@example.test');
        $otherOwner = User::factory()->create(['email' => 'other-owner-'.Str::uuid().'@example.test']);
        $otherOrganization = Organization::query()->create(['name' => 'Other Invite Organization', 'slug' => 'other-invite-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $otherOrganization->id, 'user_id' => $otherOwner->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $otherInvitation = $this->invitation($otherOwner, $otherOrganization, 'private-'.Str::uuid().'@example.test');

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->delete(route('settings.members.invitations.revoke', $otherInvitation))
            ->assertNotFound();

        $viewer = User::factory()->create(['email' => 'invite-viewer-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $viewer->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('settings.members'))
            ->assertOk()
            ->assertSee($invitation->email)
            ->assertDontSee('招待を取り消す');
        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->delete(route('settings.members.invitations.revoke', $invitation))
            ->assertForbidden();

        $this->assertSame('pending', $invitation->fresh()->lifecycleStatus());
        $this->assertSame('pending', $otherInvitation->fresh()->lifecycleStatus());
    }

    public function test_duplicate_pending_invitation_is_blocked_case_insensitively(): void
    {
        Queue::fake();
        [$owner, $organization] = $this->owner();
        $email = 'duplicate-'.Str::uuid().'@example.test';
        $invitation = $this->invitation($owner, $organization, strtoupper($email));

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->post(route('settings.members.invitations.store'), ['email' => strtolower($email), 'role' => 'viewer'])
            ->assertUnprocessable();

        $this->assertSame(1, OrganizationInvitation::query()->where('organization_id', $organization->id)->count());
        $this->assertSame('pending', $invitation->fresh()->lifecycleStatus());
        Queue::assertNotPushed(SendOrganizationInvitationEmail::class);
    }

    public function test_free_plan_cannot_invite_an_additional_administrator(): void
    {
        Queue::fake();
        [$owner, $organization] = $this->owner();
        $email = 'limited-admin-'.Str::uuid().'@example.test';

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->from(route('settings.members'))
            ->post(route('settings.members.invitations.store'), ['email' => $email, 'role' => 'admin'])
            ->assertRedirect(route('settings.members'))
            ->assertSessionHasErrors('plan_limit');

        $this->assertDatabaseMissing('organization_invitations', ['organization_id' => $organization->id, 'email' => $email]);
        Queue::assertNotPushed(SendOrganizationInvitationEmail::class);
    }

    public function test_premium_plan_allows_an_administrator_invitation_up_to_its_configured_limit(): void
    {
        Queue::fake();
        [$owner, $organization] = $this->owner();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        foreach (range(1, 3) as $number) {
            $administrator = User::factory()->create(['email' => 'premium-admin-'.$number.'-'.Str::uuid().'@example.test']);
            OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $administrator->id, 'role' => MembershipRole::Admin, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        }
        $email = 'premium-admin-pending-'.Str::uuid().'@example.test';

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->post(route('settings.members.invitations.store'), ['email' => $email, 'role' => 'admin'])
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseHas('organization_invitations', ['organization_id' => $organization->id, 'email' => $email, 'role' => 'admin']);
        Queue::assertPushed(SendOrganizationInvitationEmail::class);
    }

    public function test_free_plan_rechecks_administrator_limit_when_an_invitation_is_accepted(): void
    {
        [$owner, $organization] = $this->owner();
        $email = 'late-admin-'.Str::uuid().'@example.test';
        $token = Str::random(64);
        $invitation = OrganizationInvitation::query()->create(['organization_id' => $organization->id, 'invited_by' => $owner->id, 'email' => $email, 'role' => MembershipRole::Admin, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);

        $this->post(route('invitations.accept', $token), ['name' => '管理者', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'])
            ->assertRedirect()
            ->assertSessionHasErrors('plan_limit');

        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseMissing('product_events', ['organization_id' => $organization->id, 'event_key' => 'member_joined']);
    }

    public function test_pending_administrator_invitation_uses_its_reserved_seat_when_accepted(): void
    {
        [$owner, $organization] = $this->owner();
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $email = 'accepted-admin-'.Str::uuid().'@example.test';
        $token = Str::random(64);
        $invitation = OrganizationInvitation::query()->create(['organization_id' => $organization->id, 'invited_by' => $owner->id, 'email' => $email, 'role' => MembershipRole::Admin, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);

        $this->post(route('invitations.accept', $token), ['name' => 'Premium管理者', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $organization->id, 'user_id' => User::query()->where('email', $email)->value('id'), 'role' => MembershipRole::Admin->value, 'status' => MembershipStatus::Active->value]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_invitation_can_create_account_and_membership_once(): void
    {
        [$owner, $organization] = $this->owner();
        $token = Str::random(64);
        $invitation = OrganizationInvitation::query()->create(['organization_id' => $organization->id, 'invited_by' => $owner->id, 'email' => 'new-member-'.Str::uuid().'@example.test', 'role' => MembershipRole::Viewer, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->get(route('invitations.show', $token))->assertOk()->assertSee($invitation->email);
        $this->post(route('invitations.accept', $token), ['name' => '招待メンバー', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'])->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $organization->id, 'role' => 'viewer', 'user_id' => User::query()->where('email', $invitation->email)->value('id')]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseHas('product_events', ['event_key' => 'member_joined', 'organization_id' => $organization->id]);
        $this->post(route('invitations.accept', $token), ['name' => '再利用', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'])->assertStatus(410);
    }

    public function test_existing_user_can_log_in_and_accept_an_invitation_without_duplicate_account_creation(): void
    {
        [$owner, $organization] = $this->owner();
        $email = 'existing-invitee-'.Str::uuid().'@example.test';
        $existingUser = User::factory()->create(['email' => $email]);
        $token = Str::random(64);
        $invitation = $this->invitation($owner, $organization, $email, [], $token);
        $invitationUrl = route('invitations.show', $token);

        $this->get($invitationUrl)
            ->assertOk()
            ->assertSee('既存アカウントがあります')
            ->assertSee(route('login'))
            ->assertDontSee('name="password"', false)
            ->assertSessionHas('url.intended', route('invitations.show', ['token' => $token], false));

        $this->post(route('login.store'), ['email' => $email, 'password' => 'password'])
            ->assertRedirect($invitationUrl);
        $this->get($invitationUrl)
            ->assertOk()
            ->assertSee('招待を受け入れる')
            ->assertDontSee('name="password"', false);
        $this->post(route('invitations.accept', $token), ['name' => $existingUser->name])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::query()->where('email', $email)->count());
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $existingUser->id,
            'role' => MembershipRole::Viewer->value,
            'status' => MembershipStatus::Active->value,
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    /** @return array{0: User, 1: Organization} */
    private function owner(): array
    {
        $suffix = Str::lower(Str::random(8));
        $owner = User::factory()->create(['email' => 'owner-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Invite '.$suffix, 'slug' => 'invite-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$owner, $organization];
    }

    /** @param array{expires_at?: mixed, accepted_at?: mixed, revoked_at?: mixed} $overrides */
    private function invitation(User $owner, Organization $organization, string $email, array $overrides = [], ?string $token = null): OrganizationInvitation
    {
        return OrganizationInvitation::query()->create(array_merge([
            'organization_id' => $organization->id,
            'invited_by' => $owner->id,
            'email' => $email,
            'role' => MembershipRole::Viewer,
            'token_hash' => hash('sha256', $token ?? Str::random(64)),
            'expires_at' => now()->addDay(),
        ], $overrides));
    }
}
