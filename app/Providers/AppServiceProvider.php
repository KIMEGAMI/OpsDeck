<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Enums\MembershipStatus;
use App\Mail\PrivacySafeLogMailTransport;
use App\Mail\ProductionLogMailTransport;
use App\Mail\ResendIdempotentTransport;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Observers\BusinessChangeObserver;
use App\Services\Ai\NullAiProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\QueueWorkerHeartbeatRecorder;
use App\Services\ResendIdempotencyKeyFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProvider::class, fn (): AiProvider => config('opsdeck.ai.enabled') && config('opsdeck.ai.provider') === 'openai' && config('opsdeck.ai.api_key') ? new OpenAiProvider : new NullAiProvider);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AuditLog::observe(BusinessChangeObserver::class);

        Mail::extend('resend_idempotent', static fn (array $config = []): ResendIdempotentTransport => new ResendIdempotentTransport(
            (string) ($config['key'] ?? ''),
            max(1, (int) ($config['timeout'] ?? 10)),
        ));
        $logTransportFactory = function (array $config = []): TransportInterface {
            if ($this->app->environment('production')) {
                return new ProductionLogMailTransport;
            }

            return new PrivacySafeLogMailTransport;
        };
        Mail::extend('log', $logTransportFactory);
        Mail::extend('Log', $logTransportFactory);

        RateLimiter::for('login', fn ($request) => Limit::perMinute(config('opsdeck.auth.max_login_attempts'))->by($request->ip()));
        RateLimiter::for('register', fn ($request) => Limit::perMinute(config('opsdeck.auth.max_login_attempts'))->by($request->ip()));
        RateLimiter::for('password-reset', fn ($request) => Limit::perMinute(config('opsdeck.auth.max_login_attempts'))->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('email-verification', fn ($request) => Limit::perMinute(config('opsdeck.auth.email_verification_resend_attempts_per_minute'))->by(hash('sha256', (string) $request->user()?->getAuthIdentifier())));
        RateLimiter::for('billing-checkout', fn ($request) => Limit::perMinute(config('opsdeck.billing.checkout_attempts_per_minute'))->by(hash(
            'sha256',
            (string) ($request->attributes->get('currentOrganization')?->getKey() ?? $request->user()?->getAuthIdentifier()),
        )));
        RateLimiter::for('billing-portal', fn ($request) => Limit::perMinute(config('opsdeck.billing.portal_attempts_per_minute'))->by(hash(
            'sha256',
            (string) ($request->attributes->get('currentOrganization')?->getKey() ?? $request->user()?->getAuthIdentifier()),
        )));
        RateLimiter::for('referrals', fn ($request) => Limit::perMinute(config('opsdeck.referrals.redirect_attempts_per_minute'))->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('support', fn ($request) => Limit::perMinutes(
            config('opsdeck.support.submission_decay_minutes'),
            config('opsdeck.support.submission_limit'),
        )->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('invitation-accept', fn ($request) => Limit::perMinute(
            config('opsdeck.invitations.accept_attempts_per_minute'),
        )->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('invitation-create', fn ($request) => Limit::perMinute(
            config('opsdeck.invitations.create_attempts_per_minute'),
        )->by(hash('sha256', (string) $request->attributes->get('currentOrganization')?->getKey())));
        RateLimiter::for('file-uploads', static function (Request $request): array {
            $userId = $request->user()?->getAuthIdentifier();
            $organizationId = $request->attributes->get('currentOrganization')?->getKey();
            if ($organizationId === null && $userId !== null) {
                $organizationMembership = OrganizationMembership::query()
                    ->where('user_id', $userId)
                    ->where('status', MembershipStatus::Active->value);
                $selectedOrganizationId = $request->session()->get('current_organization_id');
                if ($selectedOrganizationId !== null) {
                    $organizationMembership->where('organization_id', $selectedOrganizationId);
                }
                $organizationId = $organizationMembership->value('organization_id');
            }
            $organizationId = (string) ($organizationId ?? 'unknown');
            $memberId = (string) ($userId ?? 'unknown');
            $limitedResponse = static function (Request $request, array $headers) {
                $retryAfterSeconds = max(1, (int) ($headers['Retry-After'] ?? 60));

                return response()->view('errors.file-upload-rate-limit', [
                    'retryAfterSeconds' => $retryAfterSeconds,
                ], 429, $headers + ['Cache-Control' => 'no-store']);
            };

            return [
                Limit::perMinute(config('opsdeck.file_uploads.per_member_attempts_per_minute'))
                    ->by('member:'.hash('sha256', $organizationId.':'.$memberId))
                    ->response($limitedResponse),
                Limit::perMinute(config('opsdeck.file_uploads.per_organization_attempts_per_minute'))
                    ->by('organization:'.hash('sha256', $organizationId))
                    ->response($limitedResponse),
            ];
        });

        ResetPassword::toMailUsing(static function (User $user, string $token): MailMessage {
            $resetPath = route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false);
            $resetUrl = rtrim((string) config('app.url'), '/').$resetPath;
            $idempotencyKey = app(ResendIdempotencyKeyFactory::class)
                ->forPasswordReset($user->getAuthIdentifier(), $token);

            return (new MailMessage)
                ->subject('OpsDeck パスワード再設定')
                ->line('パスワード再設定のリクエストを受け付けました。')
                ->action('パスワードを再設定', $resetUrl)
                ->line('このリンクは'.config('auth.passwords.users.expire').'分間有効です。')
                ->line('この操作に覚えがない場合は、このメールを無視してください。')
                ->withSymfonyMessage(static function (Email $email) use ($idempotencyKey): void {
                    $email->getHeaders()->addTextHeader(ResendIdempotentTransport::IDEMPOTENCY_HEADER, $idempotencyKey);
                });
        });

        VerifyEmail::toMailUsing(static function (User $user, string $verificationUrl): MailMessage {
            $idempotencyKey = app(ResendIdempotencyKeyFactory::class)
                ->forEmailVerification($user->getAuthIdentifier(), $verificationUrl);

            return (new MailMessage)
                ->subject('OpsDeck メールアドレス確認')
                ->line('OpsDeckの登録を完了するため、メールアドレスを確認してください。')
                ->action('メールアドレスを確認する', $verificationUrl)
                ->line('この操作に覚えがない場合は、このメールを無視してください。')
                ->withSymfonyMessage(static function (Email $email) use ($idempotencyKey): void {
                    $email->getHeaders()->addTextHeader(ResendIdempotentTransport::IDEMPOTENCY_HEADER, $idempotencyKey);
                });
        });

        $heartbeatRecorder = $this->app->make(QueueWorkerHeartbeatRecorder::class);
        Queue::looping(static function (Looping $event) use ($heartbeatRecorder): void {
            $heartbeatRecorder->record($event);
        });
    }
}
