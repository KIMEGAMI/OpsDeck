<?php

namespace Tests\Feature;

use App\Notifications\ServerErrorAlert;
use App\Services\ServerErrorAlertDispatcher;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ServerErrorAlertTest extends TestCase
{
    public function test_production_server_errors_send_safe_alerts_to_each_configured_admin(): void
    {
        $this->configureFailureAlerts();
        Notification::fake();
        Route::get('/__test/admin-error-alert', fn () => throw new \RuntimeException('PRIVATE_INPUT_SENTINEL'));

        $response = $this->get('/__test/admin-error-alert');

        $response->assertInternalServerError();
        Notification::assertSentOnDemandTimes(ServerErrorAlert::class, 2);
        Notification::assertSentOnDemand(
            ServerErrorAlert::class,
            fn (ServerErrorAlert $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->errorReference === $response->headers->get('X-OpsDeck-Error-Reference')
                && $notification->statusCode === 500
                && $channels === ['mail']
                && in_array($notifiable->routes['mail'] ?? null, ['admin-one@example.test', 'admin-two@example.test'], true),
        );
        $this->assertFalse($this->notificationContainsPrivateInput());
    }

    public function test_duplicate_administrator_email_addresses_receive_only_one_alert_each(): void
    {
        $this->configureFailureAlerts();
        config(['opsdeck.platform_admin_emails' => [
            'admin-one@example.test',
            'ADMIN-ONE@example.test',
            'admin-two@example.test',
        ]]);
        Notification::fake();
        Route::get('/__test/deduplicated-error-alert', fn () => throw new \RuntimeException('PRIVATE_INPUT_SENTINEL'));

        $this->get('/__test/deduplicated-error-alert')->assertInternalServerError();

        Notification::assertSentOnDemandTimes(ServerErrorAlert::class, 2);
        $recipients = [];
        Notification::assertSentOnDemand(
            ServerErrorAlert::class,
            function (ServerErrorAlert $notification, array $channels, AnonymousNotifiable $notifiable) use (&$recipients): bool {
                $recipients[] = strtolower($notifiable->routes['mail']);

                return true;
            },
        );
        sort($recipients);
        $this->assertSame(['admin-one@example.test', 'admin-two@example.test'], $recipients);
    }

    public function test_repeated_server_errors_are_throttled_during_configured_cooldown(): void
    {
        $this->configureFailureAlerts();
        Notification::fake();
        Route::get('/__test/throttled-error-alert', fn () => throw new \RuntimeException('INTERNAL_SENTINEL'));

        $this->get('/__test/throttled-error-alert')->assertInternalServerError();
        $this->get('/__test/throttled-error-alert')->assertInternalServerError();

        Notification::assertSentOnDemandTimes(ServerErrorAlert::class, 2);
    }

    public function test_different_server_error_status_codes_have_separate_cooldowns(): void
    {
        $this->configureFailureAlerts();
        Notification::fake();
        Route::get('/__test/server-error-500', fn () => throw new \RuntimeException('PRIVATE_INPUT_SENTINEL'));
        Route::get('/__test/server-error-503', fn () => abort(503));

        $this->get('/__test/server-error-500')->assertInternalServerError();
        $this->get('/__test/server-error-503')->assertServiceUnavailable();

        Notification::assertSentOnDemandTimes(ServerErrorAlert::class, 4);
        $statuses = [];
        Notification::assertSentOnDemand(
            ServerErrorAlert::class,
            function (ServerErrorAlert $notification) use (&$statuses): bool {
                $statuses[] = $notification->statusCode;

                return true;
            },
        );
        sort($statuses);
        $this->assertSame([500, 500, 503, 503], $statuses);
    }

    public function test_alert_is_not_sent_without_configured_administrator_emails(): void
    {
        $this->configureFailureAlerts();
        config(['opsdeck.platform_admin_emails' => []]);
        Notification::fake();
        Route::get('/__test/no-recipient-error-alert', fn () => throw new \RuntimeException('INTERNAL_SENTINEL'));

        $this->get('/__test/no-recipient-error-alert')->assertInternalServerError();

        Notification::assertNothingSent();
    }

    public function test_failure_for_one_administrator_does_not_prevent_alerts_to_other_administrators(): void
    {
        $this->configureFailureAlerts();
        Cache::shouldReceive('add')->once()->andReturnTrue();
        $attemptedRecipients = [];
        $deliveredRecipients = [];
        $delivery = static function (string $recipient) use (&$attemptedRecipients, &$deliveredRecipients): void {
            $attemptedRecipients[] = $recipient;
            if ($recipient === 'admin-one@example.test') {
                throw new \RuntimeException('MAIL_TRANSPORT_SECRET');
            }

            $deliveredRecipients[] = $recipient;
        };
        $this->app->instance(Dispatcher::class, new class($delivery) implements Dispatcher
        {
            public function __construct(private \Closure $delivery) {}

            public function send(mixed $notifiables, mixed $notification): void
            {
                throw new \RuntimeException('Error alerts must bypass the queue.');
            }

            public function sendNow(mixed $notifiables, mixed $notification, ?array $channels = null): void
            {
                ($this->delivery)($notifiables->routes['mail']);
            }
        });
        Log::spy();

        app(ServerErrorAlertDispatcher::class)->dispatch('safe-error-reference', 500);

        Cache::shouldHaveReceived('add')->once();
        $this->assertSame(['admin-one@example.test', 'admin-two@example.test'], $attemptedRecipients);
        $this->assertSame(['admin-two@example.test'], $deliveredRecipients);
        Log::shouldHaveReceived('warning')->once()->with('Server error alert could not be sent.', [
            'error_reference' => 'safe-error-reference',
        ]);
    }

    public function test_cache_failure_does_not_replace_the_safe_server_error_response(): void
    {
        $this->configureFailureAlerts();
        Cache::shouldReceive('add')->once()->andThrow(new \RuntimeException('CACHE_SECRET_SENTINEL'));
        Cache::shouldReceive('forget')->once()->andThrow(new \RuntimeException('CACHE_SECRET_SENTINEL'));
        Log::spy();
        Route::get('/__test/cache-failure-error-alert', fn () => throw new \RuntimeException('PRIVATE_INPUT_SENTINEL'));

        $response = $this->get('/__test/cache-failure-error-alert');

        $response->assertInternalServerError()
            ->assertSee('一時的なエラーが発生しました')
            ->assertDontSee('CACHE_SECRET_SENTINEL', false)
            ->assertDontSee('PRIVATE_INPUT_SENTINEL', false);
        Log::shouldHaveReceived('warning')->once()->with('Server error alert could not be sent.', [
            'error_reference' => $response->headers->get('X-OpsDeck-Error-Reference'),
        ]);
    }

    public function test_email_body_contains_the_error_reference_without_request_details(): void
    {
        $notification = new ServerErrorAlert('safe-error-reference', 503);
        $message = $notification->toMail(new AnonymousNotifiable);

        $this->assertSame('OpsDeckでサーバーエラーが発生しました', $message->subject);
        $this->assertContains('エラーID: safe-error-reference', $message->introLines);
        $this->assertStringNotContainsString('HTTP状態コード', implode('\n', $message->introLines));
        $this->assertInstanceOf(ShouldQueue::class, $notification);
    }

    private function configureFailureAlerts(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'opsdeck.platform_admin_emails' => ['admin-one@example.test', 'admin-two@example.test'],
            'opsdeck.error_alerts.cooldown_seconds' => 300,
            'cache.default' => 'array',
        ]);
    }

    private function notificationContainsPrivateInput(): bool
    {
        $foundPrivateInput = false;

        Notification::assertSentOnDemand(
            ServerErrorAlert::class,
            function (ServerErrorAlert $notification) use (&$foundPrivateInput): bool {
                $mail = $notification->toMail(new AnonymousNotifiable);
                $message = implode('\n', $mail->introLines);
                $foundPrivateInput = str_contains($message, 'PRIVATE_INPUT_SENTINEL')
                    || str_contains($message, 'USER_EMAIL_SENTINEL');

                return true;
            },
        );

        return $foundPrivateInput;
    }
}
