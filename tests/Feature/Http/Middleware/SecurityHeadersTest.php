<?php

namespace Tests\Feature\Http\Middleware;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_hsts_is_added_only_to_secure_production_responses(): void
    {
        config([
            'app.env' => 'production',
            'opsdeck.security.hsts_max_age_seconds' => 31536000,
        ]);

        $this->get('https://opsdeck.test/up')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $this->get('http://opsdeck.test/up')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        config(['app.env' => 'testing']);

        $this->get('https://opsdeck.test/up')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_security_headers_are_added_to_successful_and_not_found_responses(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');

        $this->get('/missing-security-header-probe')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
    }
}
