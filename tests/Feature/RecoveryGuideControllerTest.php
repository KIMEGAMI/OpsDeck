<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoveryGuideControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_member_can_view_safe_recovery_guide(): void
    {
        $user = User::factory()->create(['email' => 'recovery-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Recovery Org', 'slug' => 'recovery-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('help.recovery', 'import'));

        $response->assertOk()->assertSee('Excelなどの取込で困ったとき')->assertSee('元のファイルはそのまま残し')->assertSee(route('imports.index'))->assertSee('取込履歴で内容を確認する')->assertSee(route('support.create'))->assertDontSee('問い合わせ用エラーID：REC-')->assertSee(config('opsdeck.support_email'))->assertDontSee('stack trace');
    }

    public function test_unknown_recovery_guide_is_not_available(): void
    {
        $user = User::factory()->create(['email' => 'recovery-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Recovery Org', 'slug' => 'recovery-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('help.recovery', 'unknown'))->assertNotFound();
    }

    public function test_member_can_follow_email_recovery_steps_without_platform_admin_access(): void
    {
        $user = User::factory()->create(['email' => 'recovery-email-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Recovery Email Org', 'slug' => 'recovery-email-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('help.recovery', 'email'))
            ->assertOk()
            ->assertSee('メールが届かないとき')
            ->assertSee(route('weekly-summary.show'))
            ->assertSee('直近7日間のまとめ・メールの受信設定を見る')
            ->assertSee('迷惑メールフォルダー')
            ->assertSee(route('support.create'))
            ->assertSee('メールの種類と発生日時')
            ->assertDontSee('Queueエラー');
    }

    public function test_google_oauth_recovery_guide_links_to_connection_settings(): void
    {
        config(['opsdeck.release_features.google_sheets' => true]);

        $user = User::factory()->create(['email' => 'recovery-google-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Recovery Google Org', 'slug' => 'recovery-google-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('help.recovery', 'google_oauth'))
            ->assertOk()
            ->assertSee('Googleスプレッドシートの接続状態を見る')
            ->assertSee(route('google-sheets.connection'));
    }

    public function test_google_oauth_recovery_guide_is_unavailable_when_google_sheets_is_out_of_release_scope(): void
    {
        config(['opsdeck.release_features.google_sheets' => false]);

        $user = User::factory()->create(['email' => 'recovery-google-disabled-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Recovery Google Disabled Org', 'slug' => 'recovery-google-disabled-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('help.recovery', 'google_oauth'))
            ->assertNotFound();
    }

    public function test_stripe_recovery_guide_respects_billing_permissions(): void
    {
        $owner = User::factory()->create(['email' => 'recovery-stripe-owner-'.Str::uuid().'@example.test']);
        $ownerOrganization = Organization::query()->create(['name' => 'Recovery Stripe Owner Org', 'slug' => 'recovery-stripe-owner-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $ownerOrganization->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $ownerOrganization->id])
            ->get(route('help.recovery', 'stripe'))
            ->assertOk()
            ->assertSee('プラン・お支払い画面で契約状態を見る')
            ->assertSee(route('billing.index'));

        $viewer = User::factory()->create(['email' => 'recovery-stripe-viewer-'.Str::uuid().'@example.test']);
        $viewerOrganization = Organization::query()->create(['name' => 'Recovery Stripe Viewer Org', 'slug' => 'recovery-stripe-viewer-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $viewerOrganization->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $viewerOrganization->id])
            ->get(route('help.recovery', 'stripe'))
            ->assertOk()
            ->assertSee('契約状態は、組織の契約担当者に確認してください。')
            ->assertDontSee(route('billing.index'));
    }

    public function test_member_can_view_ai_recovery_steps_without_exposing_provider_credentials(): void
    {
        $user = User::factory()->create(['email' => 'recovery-ai-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Recovery AI Org', 'slug' => 'recovery-ai-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('help.recovery', 'ai'))
            ->assertOk()
            ->assertSee('AIの候補や回答案が使えないとき')
            ->assertSee('手入力で作成できます')
            ->assertSee('会社の設定を管理する担当者（Owner）に確認してください。')
            ->assertSee(route('support.create'))
            ->assertDontSee(route('settings.organization'))
            ->assertDontSee(route('billing.index'))
            ->assertDontSee('api_key')
            ->assertDontSee('stack trace');
    }

    public function test_owner_can_open_ai_and_billing_settings_from_ai_recovery_guide(): void
    {
        $owner = User::factory()->create(['email' => 'recovery-ai-owner-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Recovery AI Owner Org', 'slug' => 'recovery-ai-owner-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
            ->get(route('help.recovery', 'ai'))
            ->assertOk()
            ->assertSee('組織設定でAI補助の状態を見る')
            ->assertSee(route('settings.organization'))
            ->assertSee('プラン・お支払い画面で契約状態を見る')
            ->assertSee(route('billing.index'));
    }
}
