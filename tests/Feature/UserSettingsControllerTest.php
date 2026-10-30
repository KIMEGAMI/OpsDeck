<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_when_opening_profile_settings(): void
    {
        $this->get(route('settings.profile'))
            ->assertRedirectToRoute('login');
    }

    public function test_profile_update_persists_name_and_renders_it_escaped(): void
    {
        $user = User::factory()->create();
        $unsafeName = '<script>alert("xss")</script>';

        $this->actingAs($user)->put(route('settings.profile.update'), [
            'name' => $unsafeName,
        ])->assertRedirectToRoute('settings.profile');

        $this->assertSame($unsafeName, $user->refresh()->name);
        $this->get(route('settings.profile'))
            ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee($unsafeName, false);
    }

    public function test_password_update_requires_the_current_password(): void
    {
        $user = User::factory()->create();
        $originalPasswordHash = $user->password;

        $this->actingAs($user)->put(route('settings.password.update'), [
            'current_password' => 'incorrect-password',
            'password' => 'A-new-secure-password-2026',
            'password_confirmation' => 'A-new-secure-password-2026',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($originalPasswordHash, $user->refresh()->password);
    }

    public function test_valid_password_update_changes_password_and_rotates_remember_token(): void
    {
        $user = User::factory()->create();
        $originalRememberToken = $user->remember_token;
        $newPassword = 'A-new-secure-password-2026';

        $this->actingAs($user)->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirectToRoute('settings.profile')
            ->assertSessionHas('status', 'パスワードを変更しました。');

        $user->refresh();
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertNotSame($originalRememberToken, $user->remember_token);
    }

    public function test_google_login_can_be_disconnected_after_password_confirmation(): void
    {
        $user = User::factory()->create(['google_id' => 'google-subject-to-remove']);

        $this->actingAs($user)->post(route('settings.google-login.disconnect'), [
            'current_password' => 'password',
        ])->assertRedirectToRoute('settings.profile')
            ->assertSessionHas('status', 'Googleアカウントの連携を解除しました。');

        $this->assertNull($user->refresh()->google_id);
    }

    public function test_google_linking_is_not_offered_when_callback_origin_does_not_match_application(): void
    {
        $user = User::factory()->create();
        config([
            'app.url' => 'http://localhost:8007',
            'services.google.login_enabled' => true,
            'services.google.login_redirect_uri' => 'http://127.0.0.1:8006/auth/google/callback',
        ]);

        $this->actingAs($user)->get(route('settings.profile'))
            ->assertOk()
            ->assertDontSee('Googleアカウントを連携</button>', false)
            ->assertSee('Googleログインは現在利用できません。')
            ->assertSee('メールアドレスとパスワードでログインしてください。');
    }
}
