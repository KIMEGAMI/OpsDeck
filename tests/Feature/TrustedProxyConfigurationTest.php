<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_forwarded_ip_and_https_are_accepted_only_from_a_configured_proxy(): void
    {
        Route::get('/__tests/proxy-context', static fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]));

        $headers = [
            'X-Forwarded-For' => '198.51.100.20',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'spoofed.example.test',
        ];

        config(['trustedproxy.proxies' => null]);
        $untrustedResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders($headers)
            ->get('http://localhost/__tests/proxy-context');

        $untrustedResponse
            ->assertOk()
            ->assertExactJson(['ip' => '192.0.2.10', 'secure' => false, 'host' => 'localhost']);

        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $trustedResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders($headers)
            ->get('http://localhost/__tests/proxy-context');

        $trustedResponse
            ->assertOk()
            ->assertExactJson(['ip' => '198.51.100.20', 'secure' => true, 'host' => 'localhost']);
    }
}
