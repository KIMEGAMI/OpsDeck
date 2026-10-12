<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_guest_sees_service_scope_and_plan_information_on_the_homepage(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('社員・端末・SaaS・契約を、ひとつの場所で管理。')
            ->assertSee('CSVのサンプル')
            ->assertSee('月額9,800円（税別）')
            ->assertSee('Stripe Checkoutでお支払い総額を表示します')
            ->assertSee('社員20名・端末50台・SaaS10件まで')
            ->assertSee('社員200名・端末500台・SaaS100件まで')
            ->assertSee('削減を保証するものではありません')
            ->assertSee('MDM／EDR')
            ->assertSee(route('register'))
            ->assertSee(route('login'))
            ->assertSee(route('demo.show'))
            ->assertSee(route('help.index'))
            ->assertSee(route('legal.commercial-transactions'));
    }

    public function test_authenticated_user_is_redirected_to_the_dashboard_from_the_homepage(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $user = User::factory()->create(['email' => 'homepage-'.Str::uuid().'@example.test']);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }
}
