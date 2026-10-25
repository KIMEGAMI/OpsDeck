<?php

namespace Tests\Feature\Feature\Auth;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_keeps_the_form_in_a_readable_centered_width(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('class="card auth-card"', false)
            ->assertSee('h1 { font-size: clamp(1.5rem, 2vw, 1.875rem); }', false)
            ->assertSee('h2 { font-size: 1.25rem; }', false)
            ->assertSee('OpsDeckへログイン')
            ->assertSee('社員・パソコン・使っているサービス・契約をまとめて管理できます。')
            ->assertSee('共有のパソコンでは、チェックを外してください。')
            ->assertSee('aria-describedby="remember-help"', false)
            ->assertSee('使い方に困ったらヘルプを見る')
            ->assertSee(route('help.index'))
            ->assertSee('登録前に読み取り専用デモを見る')
            ->assertSee(route('demo.show'))
            ->assertSee('data-password-toggle', false)
            ->assertSee('aria-controls="password"', false)
            ->assertSee('aria-label="パスワードを表示"', false)
            ->assertDontSee('aria-pressed', false);
    }

    public function test_authenticated_user_can_log_in_and_reach_dashboard(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Example Operations',
            'slug' => 'example-operations',
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirectToRoute('dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk()->assertSee('Example Operations');
    }
}
