<?php

namespace Tests\Feature\Feature\Auth;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mail\ResendIdempotentTransport;
use App\Models\Organization;
use App\Models\User;
use App\Services\ResendIdempotencyKeyFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_owner_membership_and_authenticated_session(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'organization_name' => 'Example Operations',
            'name' => 'Example Owner',
            'email' => 'owner@example.test',
            'password' => 'A-secure-password-2026',
            'password_confirmation' => 'A-secure-password-2026',
        ]);

        $response->assertRedirectToRoute('onboarding.index');
        $this->assertAuthenticated();

        $organization = Organization::query()->where('name', 'Example Operations')->firstOrFail();
        $this->assertSame('free', $organization->plan_code);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'role' => MembershipRole::Owner->value,
            'status' => MembershipStatus::Active->value,
        ]);
        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verification_required_at);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get(route('dashboard'))->assertRedirectToRoute('verification.notice');
        $this->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('メールアドレスを確認してください')
            ->assertSee('確認メールを再送する');

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->get($verificationUrl)->assertRedirectToRoute('dashboard');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('導入チェックリスト')
            ->assertSee('社員表');
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'onboarding_started',
        ]);
    }

    public function test_unverified_new_user_can_resend_confirmation_with_a_rate_limit(): void
    {
        Notification::fake();
        config()->set('opsdeck.auth.email_verification_resend_attempts_per_minute', 1);
        $user = User::factory()->create(['email' => 'verify-'.Str::uuid().'@example.test']);
        $user->forceFill(['email_verification_required_at' => now(), 'email_verified_at' => null])->save();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status', '確認メールを再送しました。受信トレイをご確認ください。');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);

        $this->actingAs($user)->post(route('verification.send'))->assertTooManyRequests();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_existing_accounts_without_a_verification_requirement_remain_usable(): void
    {
        $existingUser = User::factory()->create();

        $this->assertNull($existingUser->email_verification_required_at);
        $this->assertTrue($existingUser->hasVerifiedEmail());
    }

    public function test_verification_mail_uses_a_stable_resend_idempotency_key_for_its_link(): void
    {
        $user = User::factory()->create();
        $mailMessage = (new VerifyEmail)->toMail($user);
        $email = new Email;

        foreach ($mailMessage->callbacks as $callback) {
            $callback($email);
        }

        $this->assertSame(
            app(ResendIdempotencyKeyFactory::class)->forEmailVerification($user->getKey(), $mailMessage->actionUrl),
            $email->getHeaders()->getHeaderBody(ResendIdempotentTransport::IDEMPOTENCY_HEADER),
        );
    }
}
