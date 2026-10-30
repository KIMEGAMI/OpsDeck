<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_service_scope_and_plan_information_on_the_homepage(): void
    {
        config(['opsdeck.release_features.work_time_tracking' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('見つける。見直す。')
            ->assertSee('IT運用の見える化から、費用の見直しへ')
            ->assertDontSee('費用と時間の改善へ')
            ->assertSee('FreeはCSV/TXT、PremiumはXLSXから取り込めます。古いExcel形式（.xls）とGoogle Sheets連携は1.0では対象外です。')
            ->assertSee('更新通知、セキュリティ対応へ広げられます。')
            ->assertDontSee('更新通知、レポートへ広げられます。')
            ->assertSee('CSV/TXTの実データ取込（登録上限まで）')
            ->assertSee('プレビューし、列の対応と新規・更新・確認待ち・エラーを確認できます。')
            ->assertSee('月額9,800円（税別）')
            ->assertSee('Stripe Checkoutでお支払い総額を表示します')
            ->assertSee('社員20名・端末50台・SaaS10件まで')
            ->assertSee('社員200名・端末500台・SaaS100件まで')
            ->assertSee('削減を保証するものではありません')
            ->assertDontSee('導入効果レポート')
            ->assertSee('MDM／EDR')
            ->assertDontSee('作業時間')
            ->assertDontSee('作業時間がどれだけ変わった？')
            ->assertDontSee('月80分短縮')
            ->assertSee('class="landing-results landing-results-single"', false)
            ->assertSee('aria-label="費用の見直し効果"', false)
            ->assertSee('aria-label="トップページメニュー"', false)
            ->assertSee('ページメニューを開く', false)
            ->assertSee('ページメニューを閉じる', false)
            ->assertSee('aria-label="ページメニュー"', false)
            ->assertSee('href="#landing-workflow-title"', false)
            ->assertSee('href="#landing-savings-title"', false)
            ->assertSee('href="#landing-pricing-title"', false)
            ->assertSee(route('register'))
            ->assertSee(route('login'))
            ->assertSee(route('demo.show'))
            ->assertSee(route('help.index'))
            ->assertSee(route('legal.commercial-transactions'));
    }

    public function test_guest_sees_work_time_feature_only_when_it_is_released(): void
    {
        config(['opsdeck.release_features.work_time_tracking' => true]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('作業時間がどれだけ変わった？')
            ->assertSee('月80分短縮')
            ->assertSee('class="landing-results"', false)
            ->assertDontSee('class="landing-results landing-results-single"', false)
            ->assertSee('aria-label="費用と作業時間の効果"', false);
    }

    public function test_guest_authentication_page_does_not_render_homepage_anchor_menu(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('aria-label="トップページメニュー"', false);
    }

    public function test_shared_layout_references_favicon_files_present_in_the_public_directory(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('type="image/svg+xml" href="'.asset('favicon.svg').'"', false)
            ->assertSee('type="image/x-icon" href="'.asset('favicon.ico').'"', false);

        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertFileExists(public_path('favicon.ico'));
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
