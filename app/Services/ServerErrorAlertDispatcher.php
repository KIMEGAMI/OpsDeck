<?php

namespace App\Services;

use App\Notifications\ServerErrorAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ServerErrorAlertDispatcher
{
    private const COOLDOWN_CACHE_KEY_PREFIX = 'opsdeck:server-error-alerts:cooldown:';

    public function dispatch(string $errorReference, int $statusCode): void
    {
        $configuredAdminEmails = config('opsdeck.platform_admin_emails', []);
        $adminEmails = [];
        foreach (is_array($configuredAdminEmails) ? $configuredAdminEmails : [] as $adminEmail) {
            if (! is_string($adminEmail)) {
                continue;
            }

            $adminEmail = trim($adminEmail);
            if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $adminEmails[strtolower($adminEmail)] ??= $adminEmail;
        }
        $adminEmails = array_values($adminEmails);
        $cooldownSeconds = config('opsdeck.error_alerts.cooldown_seconds');

        if ($adminEmails === [] || ! is_int($cooldownSeconds) || $cooldownSeconds < 1) {
            return;
        }

        try {
            if (! Cache::add(self::COOLDOWN_CACHE_KEY_PREFIX.$statusCode, true, $cooldownSeconds)) {
                return;
            }
        } catch (Throwable) {
            try {
                Cache::forget(self::COOLDOWN_CACHE_KEY_PREFIX.$statusCode);
            } catch (Throwable) {
                // Preserve the safe error response when the cache is unavailable.
            }

            $this->logDispatchFailure($errorReference);

            return;
        }

        $dispatchFailed = false;
        foreach ($adminEmails as $adminEmail) {
            try {
                $idempotencyKey = app(ResendIdempotencyKeyFactory::class)
                    ->forServerErrorAlert($errorReference, $statusCode, $adminEmail);
                Notification::route('mail', $adminEmail)
                    ->notifyNow(new ServerErrorAlert($errorReference, $statusCode, $idempotencyKey));
            } catch (Throwable) {
                $dispatchFailed = true;
            }
        }

        if ($dispatchFailed) {
            $this->logDispatchFailure($errorReference);
        }
    }

    private function logDispatchFailure(string $errorReference): void
    {
        try {
            Log::warning('Server error alert could not be sent.', [
                'error_reference' => $errorReference,
            ]);
        } catch (Throwable) {
            // Alert failures must not replace the original server error response.
        }
    }
}
