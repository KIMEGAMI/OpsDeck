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

        $response->assertOk()->assertSee('Importに失敗したとき')->assertSee('元ファイルを変更せず')->assertSee(route('imports.index'))->assertSee('Import履歴で失敗内容を確認し、再試行する')->assertSee(route('support.create'))->assertDontSee('問い合わせ用エラーID：REC-')->assertSee(config('opsdeck.support_email'))->assertDontSee('stack trace');
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
            ->assertSee('メールを受信できないとき')
            ->assertSee(route('weekly-summary.show'))
            ->assertSee('週次サマリー・お知らせメールの受信設定を確認')
            ->assertSee('迷惑メールフォルダー')
            ->assertSee(route('support.create'))
            ->assertSee('メールの種類と発生日時')
            ->assertDontSee('Queueエラー');
    }

    public function test_member_can_view_ai_recovery_steps_without_exposing_provider_credentials(): void
    {
        $user = User::factory()->create(['email' => 'recovery-ai-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Recovery AI Org', 'slug' => 'recovery-ai-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('help.recovery', 'ai'))
            ->assertOk()
            ->assertSee('AI候補・下書きを利用できないとき')
            ->assertSee('手動で作成できます')
            ->assertSee('Owner/Admin')
            ->assertSee(route('support.create'))
            ->assertDontSee('api_key')
            ->assertDontSee('stack trace');
    }
}
