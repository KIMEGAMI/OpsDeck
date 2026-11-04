<?php

namespace App\Services;

use Illuminate\Http\Request;

class GoogleLoginCallbackReadiness
{
    private const CALLBACK_PATH = '/auth/google/callback';

    public function matchesApplicationUrl(): bool
    {
        return $this->matchesUrl(
            (string) config('app.url'),
            (string) config('services.google.login_redirect_uri'),
        );
    }

    public function matchesRequestUrl(Request $request): bool
    {
        return $this->matchesUrl(
            $request->getSchemeAndHttpHost(),
            (string) config('services.google.login_redirect_uri'),
        );
    }

    private function matchesUrl(string $originUrl, string $callbackUrl): bool
    {
        $applicationUrl = parse_url($originUrl);
        $callbackUrl = parse_url($callbackUrl);
        if (! is_array($applicationUrl) || ! is_array($callbackUrl)) {
            return false;
        }

        $applicationScheme = strtolower((string) ($applicationUrl['scheme'] ?? ''));
        $callbackScheme = strtolower((string) ($callbackUrl['scheme'] ?? ''));
        if (! in_array($applicationScheme, ['http', 'https'], true)
            || $callbackScheme !== $applicationScheme
            || ! filled($applicationUrl['host'] ?? null)
            || ! filled($callbackUrl['host'] ?? null)
            || array_key_exists('user', $applicationUrl)
            || array_key_exists('pass', $applicationUrl)
            || array_key_exists('query', $applicationUrl)
            || array_key_exists('fragment', $applicationUrl)
            || array_key_exists('user', $callbackUrl)
            || array_key_exists('pass', $callbackUrl)
            || array_key_exists('query', $callbackUrl)
            || array_key_exists('fragment', $callbackUrl)
            || ($callbackUrl['path'] ?? '') !== self::CALLBACK_PATH) {
            return false;
        }

        $applicationHost = strtolower(trim((string) $applicationUrl['host'], '[]'));
        $callbackHost = strtolower(trim((string) $callbackUrl['host'], '[]'));
        $defaultPort = $applicationScheme === 'https' ? 443 : 80;
        $applicationPort = $applicationUrl['port'] ?? $defaultPort;
        $callbackPort = $callbackUrl['port'] ?? $defaultPort;

        return $applicationHost === $callbackHost
            && $applicationPort === $callbackPort;
    }
}
