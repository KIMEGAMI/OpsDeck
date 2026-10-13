<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
        $hstsMaxAgeSeconds = max(0, (int) config('opsdeck.security.hsts_max_age_seconds', 0));
        if (config('app.env') === 'production' && $request->isSecure() && $hstsMaxAgeSeconds > 0) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.$hstsMaxAgeSeconds);
        }

        return $response;
    }
}
