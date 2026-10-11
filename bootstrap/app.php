<?php

use App\Http\Middleware\EnsureOrganizationMembership;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Services\ServerErrorAlertDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('opsdeck:scheduler:heartbeat')->everyMinute();
        $schedule->command('opsdeck:weekly-summaries')->weeklyOn(1, '09:00')->timezone(config('app.timezone'));
        $schedule->command('opsdeck:retry-weekly-summary-emails --limit=100')->hourly()->timezone(config('app.timezone'));
        $schedule->command('opsdeck:retry-announcement-emails --limit=100')->hourly()->timezone(config('app.timezone'));
        $schedule->command('opsdeck:billing:enforce-grace')->hourly()->timezone(config('app.timezone'));
        $schedule->command('auth:clear-resets')->everyFifteenMinutes();
        $schedule->command('opsdeck:impact:snapshots')->hourly()->timezone(config('app.timezone'));
        $schedule->command('opsdeck:impact:prune-snapshots')->daily()->timezone(config('app.timezone'))->withoutOverlapping();
        $schedule->command('opsdeck:backup')
            ->dailyAt(config('opsdeck.backup.schedule_time'))
            ->timezone(config('app.timezone'))
            ->onOneServer()
            ->withoutOverlapping();
        $schedule->command('opsdeck:backup:restore-test')
            ->weekly()
            ->mondays()
            ->at(config('opsdeck.backup.restore_test_schedule_time'))
            ->timezone(config('app.timezone'))
            ->environments('production')
            ->onOneServer()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization.member' => EnsureOrganizationMembership::class,
            'plan.feature' => EnsurePlanFeature::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->report(function (Throwable $exception): ?bool {
            if (! app()->environment('production')) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            if ($status < 500) {
                return null;
            }

            $errorReference = request()->attributes->get('opsdeck.error_reference');
            if (! is_string($errorReference)) {
                $errorReference = (string) Str::uuid();
            }
            request()->attributes->set('opsdeck.error_reference', $errorReference);

            try {
                Log::error('Server request failed.', [
                    'error_reference' => $errorReference,
                    'exception_class' => $exception::class,
                    'source_file' => basename($exception->getFile()),
                    'source_line' => $exception->getLine(),
                ]);
            } catch (Throwable) {
                // Keep the original error response safe if diagnostic logging is unavailable.
            }

            return false;
        });

        $exceptions->respond(function (Response $response): Response {
            if (! app()->environment('production') || $response->getStatusCode() < 500) {
                return $response;
            }

            $request = request();
            $errorReference = $request->attributes->get('opsdeck.error_reference');
            if (! is_string($errorReference)) {
                $errorReference = (string) Str::uuid();
                try {
                    Log::error('Server request failed.', ['error_reference' => $errorReference]);
                } catch (Throwable) {
                    // The response must remain generic even if logging is unavailable.
                }
            }

            $status = $response->getStatusCode();
            app(ServerErrorAlertDispatcher::class)->dispatch($errorReference, $status);
            $headers = $response->headers->all();
            unset($headers['content-type'], $headers['content-length']);

            if ($request->is('api/*') || $request->expectsJson()) {
                $safeResponse = response()->json([
                    'message' => 'サーバーでエラーが発生しました。',
                    'error_reference' => $errorReference,
                ], $status);
            } else {
                $safeResponse = response()->view('errors.500', ['errorReference' => $errorReference], $status);
            }

            $safeResponse->headers->add($headers);
            $safeResponse->headers->set('X-OpsDeck-Error-Reference', $errorReference);

            return $safeResponse;
        });
    })->create();
