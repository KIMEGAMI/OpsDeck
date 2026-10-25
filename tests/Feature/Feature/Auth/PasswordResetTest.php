<?php

namespace Tests\Feature\Feature\Auth;

use App\Mail\PrivacySafeLogMailTransport;
use App\Mail\ResendIdempotentTransport;
use App\Models\User;
use App\Services\ResendIdempotencyKeyFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_log_mailer_rejects_messages_without_logging_the_contents(): void
    {
        Log::spy();
        app()['env'] = 'production';

        foreach (['log', 'Log'] as $transport) {
            config(['mail.mailers.log.transport' => $transport]);
            Mail::purge('log');

            try {
                Mail::mailer('log')->raw('sensitive-reset-token-marker', static function (Message $message): void {
                    $message->to('private-recipient@example.test');
                });

                $this->fail('The production log mailer must reject messages.');
            } catch (TransportException $exception) {
                $this->assertSame(
                    'Mail delivery is disabled for the production log transport.',
                    $exception->getMessage(),
                );
            } finally {
                Mail::purge('log');
            }
        }

        Log::shouldNotHaveReceived('debug');
    }

    public function test_non_production_log_mailer_suppresses_messages_without_logging_personal_content(): void
    {
        Log::spy();
        app()['env'] = 'testing';
        config(['mail.mailers.log.transport' => 'Log']);
        Mail::purge('log');

        Mail::mailer('log')->raw('sensitive-reset-token-marker', static function (Message $message): void {
            $message->to('private-recipient@example.test');
        });

        $this->assertInstanceOf(PrivacySafeLogMailTransport::class, Mail::mailer('log')->getSymfonyTransport());
        Log::shouldNotHaveReceived('debug');
    }

    public function test_guest_can_open_the_password_reset_request_page(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('class="card auth-card"', false)
            ->assertSee('パスワードをお忘れの場合')
            ->assertSee(route('login'));
    }

    public function test_registered_and_unknown_emails_receive_the_same_confirmation(): void
    {
        config(['app.url' => 'https://opsdeck.example.test']);
        Notification::fake();
        $user = User::factory()->create(['email' => 'password-reset-'.Str::uuid().'@example.test']);
        $statusMessage = '該当する場合は、パスワード再設定用のメールを送信しました。メールが届かない場合は、入力したメールアドレスをご確認ください。';

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', $statusMessage);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mailMessage = $notification->toMail($user);
            $email = new Email;
            foreach ($mailMessage->callbacks as $callback) {
                $callback($email);
            }
            $idempotencyKey = $email->getHeaders()->getHeaderBody(ResendIdempotentTransport::IDEMPOTENCY_HEADER);

            return $mailMessage->subject === 'OpsDeck パスワード再設定'
                && $idempotencyKey === app(ResendIdempotencyKeyFactory::class)
                    ->forPasswordReset($user->getAuthIdentifier(), $notification->token)
                && ! str_contains((string) $idempotencyKey, $notification->token)
                && $mailMessage->actionUrl === config('app.url').route('password.reset', [
                    'token' => $notification->token,
                    'email' => $user->email,
                ], false)
                && in_array('この操作に覚えがない場合は、このメールを無視してください。', $mailMessage->outroLines, true);
        });

        $this->post(route('password.email'), ['email' => 'unknown-'.Str::uuid().'@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', $statusMessage)
            ->assertSessionHasNoErrors();

        Notification::assertCount(1);
    }

    public function test_password_reset_email_uses_the_configured_host_when_request_host_is_untrusted(): void
    {
        config(['app.url' => 'https://opsdeck.example.test/portal']);
        Notification::fake();
        $user = User::factory()->create(['email' => 'untrusted-host-reset-'.Str::uuid().'@example.test']);

        $this->withServerVariables([
            'HTTP_HOST' => 'attacker.example.test',
            'HTTPS' => 'off',
        ])->post(route('password.email', [], false), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $actionUrl = (string) $notification->toMail($user)->actionUrl;
            $urlParts = parse_url($actionUrl);
            parse_str($urlParts['query'] ?? '', $query);

            $this->assertSame('https', $urlParts['scheme'] ?? null);
            $this->assertSame('opsdeck.example.test', $urlParts['host'] ?? null);
            $this->assertSame('/portal/reset-password/'.$notification->token, $urlParts['path'] ?? null);
            $this->assertSame($user->email, $query['email'] ?? null);

            return true;
        });
    }

    public function test_reset_page_prefills_the_email_and_contains_the_secret_token(): void
    {
        $user = User::factory()->create(['email' => 'password-reset-form-'.Str::uuid().'@example.test']);
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('class="card auth-card"', false)
            ->assertSee('新しいパスワードを設定')
            ->assertSee('name="token" value="'.$token.'"', false)
            ->assertSee('value="'.$user->email.'"', false)
            ->assertSee('aria-controls="password"', false)
            ->assertSee('aria-controls="password_confirmation"', false)
            ->assertSee('aria-label="新しいパスワードを表示"', false)
            ->assertSee('aria-label="新しい確認用パスワードを表示"', false);
    }

    public function test_valid_token_resets_the_password_and_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'password-reset-success-'.Str::uuid().'@example.test']);
        $originalPassword = $user->password;
        $originalRememberToken = $user->remember_token;
        $token = Password::broker()->createToken($user);
        $newPassword = 'A-new-secure-password-2026';

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirectToRoute('login')
            ->assertSessionHas('status', 'パスワードを再設定しました。新しいパスワードでログインしてください。');

        $user->refresh();
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertNotSame($originalPassword, $user->password);
        $this->assertNotSame($originalRememberToken, $user->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Another-secure-password-2026',
            'password_confirmation' => 'Another-secure-password-2026',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check($newPassword, $user->refresh()->password));
    }

    public function test_expired_reset_token_does_not_change_the_password(): void
    {
        $user = User::factory()->create(['email' => 'password-reset-expired-'.Str::uuid().'@example.test']);
        $originalPassword = $user->password;
        $token = Password::broker()->createToken($user);
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'A-new-secure-password-2026',
            'password_confirmation' => 'A-new-secure-password-2026',
        ])->assertSessionHasErrors('email');

        $this->assertSame($originalPassword, $user->refresh()->password);
    }

    public function test_password_reset_requests_are_rate_limited_by_ip(): void
    {
        config(['opsdeck.auth.max_login_attempts' => 2]);
        $email = 'rate-limit-'.Str::uuid().'@example.test';

        $this->post(route('password.email'), ['email' => $email])->assertRedirect();
        $this->post(route('password.email'), ['email' => $email])->assertRedirect();
        $this->post(route('password.email'), ['email' => $email])->assertTooManyRequests();
    }

    public function test_expired_password_reset_tokens_are_scheduled_for_cleanup(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0);
        $passwordResetCleanup = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'auth:clear-resets'));

        $this->assertNotNull($passwordResetCleanup);
        $this->assertSame('*/15 * * * *', $passwordResetCleanup->expression);
    }
}
