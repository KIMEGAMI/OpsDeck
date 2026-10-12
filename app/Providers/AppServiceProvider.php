<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Mail\ResendIdempotentTransport;
use App\Models\User;
use App\Services\Ai\NullAiProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\QueueWorkerHeartbeatRecorder;
use App\Services\ResendIdempotencyKeyFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
        Mail::extend('resend_idempotent', static fn (array $config = []): ResendIdempotentTransport => new ResendIdempotentTransport(
            (string) ($config['key'] ?? ''),
            max(1, (int) ($config['timeout'] ?? 10)),
        ));

        RateLimiter::for('login', fn ($request) => Limit::perMinute(config('opsdeck.auth.max_login_attempts'))->by($request->ip()));
        RateLimiter::for('register', fn ($request) => Limit::perMinute(config('opsdeck.auth.max_login_attempts'))->by($request->ip()));
        RateLimiter::for('password-reset', fn ($request) => Limit::perMinute(config('opsdeck.auth.max_login_attempts'))->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('billing-checkout', fn ($request) => Limit::perMinute(config('opsdeck.billing.checkout_attempts_per_minute'))->by(hash(
            'sha256',
            (string) ($request->attributes->get('currentOrganization')?->getKey() ?? $request->user()?->getAuthIdentifier()),
        )));
        RateLimiter::for('referrals', fn ($request) => Limit::perMinute(config('opsdeck.referrals.redirect_attempts_per_minute'))->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('support', fn ($request) => Limit::perMinutes(
            config('opsdeck.support.submission_decay_minutes'),
            config('opsdeck.support.submission_limit'),
        )->by(hash('sha256', (string) $request->ip())));

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

        $heartbeatRecorder = $this->app->make(QueueWorkerHeartbeatRecorder::class);
        Queue::looping(static function (Looping $event) use ($heartbeatRecorder): void {
            $heartbeatRecorder->record($event);
        });
    }
}
