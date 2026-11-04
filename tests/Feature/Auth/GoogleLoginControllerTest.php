<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_APPLICATION_ORIGIN = 'https://opsdeck.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::TEST_APPLICATION_ORIGIN,
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.login_enabled' => true,
            'services.google.login_redirect_uri' => self::TEST_APPLICATION_ORIGIN.'/auth/google/callback',
            'services.google.login_scopes' => ['openid'],
            'services.google.authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'services.google.token_endpoint' => 'https://oauth2.googleapis.com/token',
            'services.google.userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'services.google.timeout_seconds' => 10,
        ]);
    }

    public function test_oauth_redirect_returns_503_when_callback_origin_does_not_match_application(): void
    {
        config([
            'app.url' => 'http://localhost:8007',
            'services.google.login_redirect_uri' => 'http://127.0.0.1:8006/auth/google/callback',
        ]);
        Http::preventStrayRequests();

        $this->get($this->routeAtOrigin('http://localhost:8007', 'login.google.redirect'))
            ->assertServiceUnavailable();

        Http::assertNothingSent();
    }

    public function test_oauth_callback_returns_503_when_callback_origin_does_not_match_application(): void
    {
        config([
            'app.url' => 'http://localhost:8007',
            'services.google.login_redirect_uri' => 'http://127.0.0.1:8006/auth/google/callback',
        ]);
        Http::preventStrayRequests();

        $this->withSession([
            'google_login_oauth_state' => ['value' => 'valid-state', 'mode' => 'login'],
        ])->get($this->routeAtOrigin('http://localhost:8007', 'login.google.callback', [
            'state' => 'valid-state',
            'code' => 'authorization-code',
        ]))->assertServiceUnavailable();

        Http::assertNothingSent();
    }

    public function test_guest_redirect_includes_oauth_state_and_login_scope(): void
    {
        $response = $this->get($this->routeAtOrigin(self::TEST_APPLICATION_ORIGIN, 'login.google.redirect'));
        $location = (string) $response->headers->get('Location');
        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $response->assertRedirect();
        $this->assertSame('https', parse_url($location, PHP_URL_SCHEME));
        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));
        $this->assertSame('google-client-id', $query['client_id'] ?? null);
        $this->assertSame('openid', $query['scope'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('select_account', $query['prompt'] ?? null);
        $this->assertNotSame('', $query['state'] ?? '');
        $response->assertSessionHas('google_login_oauth_state', function (array $state) use ($query): bool {
            return ($state['value'] ?? null) === ($query['state'] ?? null)
                && ($state['mode'] ?? null) === 'login';
        });
    }

    public function test_callback_returns_419_and_consumes_state_when_state_does_not_match(): void
    {
        $this->withSession([
            'google_login_oauth_state' => ['value' => 'expected-state', 'mode' => 'login'],
        ])->get(route('login.google.callback', ['state' => 'tampered-state']))
            ->assertStatus(419)
            ->assertSessionMissing('google_login_oauth_state');
    }

    public function test_google_subject_for_a_verified_user_authenticates_and_redirects_to_dashboard(): void
    {
        $user = User::factory()->create(['google_id' => 'google-subject-verified']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-subject-verified']),
        ]);

        $response = $this->withSession([
            'google_login_oauth_state' => ['value' => 'valid-state', 'mode' => 'login'],
        ])->get($this->routeAtOrigin(self::TEST_APPLICATION_ORIGIN, 'login.google.callback', [
            'state' => 'valid-state',
            'code' => 'authorization-code',
        ]));

        $response->assertRedirectToRoute('dashboard');
        $this->assertAuthenticatedAs($user);
        Http::assertSentCount(2);
    }

    public function test_matching_email_does_not_automatically_link_an_unlinked_account(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'new-google-subject',
                'email' => $user->email,
                'email_verified' => true,
            ]),
        ]);

        $response = $this->withSession([
            'google_login_oauth_state' => ['value' => 'valid-state', 'mode' => 'login'],
        ])->get($this->routeAtOrigin(self::TEST_APPLICATION_ORIGIN, 'login.google.callback', [
            'state' => 'valid-state',
            'code' => 'authorization-code',
        ]));

        $response->assertRedirectToRoute('login')
            ->assertSessionHas('status', '初回利用時はメールアドレスとパスワードでログインし、個人設定からGoogleアカウントを連携してください。');
        $this->assertGuest();
        $this->assertNull($user->refresh()->google_id);
        Http::assertSentCount(2);
    }

    public function test_google_login_refuses_a_local_account_without_verified_email(): void
    {
        User::factory()->unverified()->create([
            'email_verification_required_at' => now(),
            'google_id' => 'google-subject-unverified',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-subject-unverified']),
        ]);

        $response = $this->withSession([
            'google_login_oauth_state' => ['value' => 'valid-state', 'mode' => 'login'],
        ])->get($this->routeAtOrigin(self::TEST_APPLICATION_ORIGIN, 'login.google.callback', [
            'state' => 'valid-state',
            'code' => 'authorization-code',
        ]));

        $response->assertRedirectToRoute('login');
        $this->assertGuest();
        Http::assertSentCount(2);
    }

    public function test_verified_user_can_link_a_google_subject_after_password_confirmation(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'new-google-subject']),
        ]);

        $startResponse = $this->actingAs($user)->post($this->routeAtOrigin(self::TEST_APPLICATION_ORIGIN, 'settings.google-login.connect'), [
            'current_password' => 'password',
        ]);
        $location = (string) $startResponse->headers->get('Location');
        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $startResponse->assertRedirect();
        $startResponse->assertSessionHas('google_login_oauth_state', function (array $state) use ($user, $query): bool {
            return ($state['value'] ?? null) === ($query['state'] ?? null)
                && ($state['mode'] ?? null) === 'link'
                && (int) ($state['user_id'] ?? 0) === (int) $user->getKey();
        });

        $callbackResponse = $this->get($this->routeAtOrigin(self::TEST_APPLICATION_ORIGIN, 'login.google.callback', [
            'state' => $query['state'],
            'code' => 'authorization-code',
        ]));

        $callbackResponse->assertRedirectToRoute('settings.profile')
            ->assertSessionHas('status', 'Googleアカウントを連携しました。次回からGoogleでログインできます。');
        $this->assertSame('new-google-subject', $user->refresh()->google_id);
        $this->assertAuthenticatedAs($user);
        Http::assertSentCount(2);
    }

    public function test_google_subject_linked_to_another_user_is_not_moved(): void
    {
        $user = User::factory()->create();
        $existingUser = User::factory()->create(['google_id' => 'google-subject-in-use']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-subject-in-use']),
        ]);

        $startResponse = $this->actingAs($user)->post($this->routeAtOrigin(self::TEST_APPLICATION_ORIGIN, 'settings.google-login.connect'), [
            'current_password' => 'password',
        ]);
        $query = [];
        parse_str((string) parse_url((string) $startResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $startResponse->assertRedirect();
        $this->get($this->routeAtOrigin(self::TEST_APPLICATION_ORIGIN, 'login.google.callback', [
            'state' => $query['state'],
            'code' => 'authorization-code',
        ]))->assertRedirectToRoute('settings.profile');

        $this->assertNull($user->refresh()->google_id);
        $this->assertSame('google-subject-in-use', $existingUser->refresh()->google_id);
        Http::assertSentCount(2);
    }

    public function test_account_linking_rejects_an_incorrect_current_password_before_oauth(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.google-login.connect'), [
            'current_password' => 'incorrect-password',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertNull($user->refresh()->google_id);
    }

    /**
     * Build a named test URL with an explicit request origin.
     *
     * @param  array<string, int|string>  $parameters
     */
    private function routeAtOrigin(string $origin, string $routeName, array $parameters = []): string
    {
        return rtrim($origin, '/').route($routeName, $parameters, false);
    }
}
