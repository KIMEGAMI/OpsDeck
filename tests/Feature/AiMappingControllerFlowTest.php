<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\PrepareImportMappingAnalysis;
use App\Models\AiUsageLog;
use App\Models\AiUsageReservation;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiMappingControllerFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapping_page_shows_only_the_current_organizations_estimated_monthly_ai_usage(): void
    {
        Http::preventStrayRequests();
        config([
            'opsdeck.ai.monthly_budget_jpy.premium' => 500,
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.api_key' => bin2hex(random_bytes(32)),
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'ai_enabled' => true,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $otherOrganization = Organization::factory()->create();
        $batch = ImportBatch::factory()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員台帳',
            'headers_json' => ['社員番号'],
            'status' => 'preview',
            'executed_by' => $user->id,
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'estimated_cost_usd' => 1,
            'estimated_cost_jpy' => 150,
            'success' => true,
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $otherOrganization->id,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'estimated_cost_usd' => 9,
            'estimated_cost_jpy' => 1350,
            'success' => true,
        ]);
        AiUsageReservation::query()->create([
            'organization_id' => $organization->id,
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'reserved_cost_usd' => 0.5,
            'expires_at' => now()->addMinutes(5),
        ]);
        AiUsageReservation::query()->create([
            'organization_id' => $organization->id,
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'reserved_cost_usd' => 2,
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', $batch));

        $response->assertViewHas('aiMappingMonthlyUsage', [
            'budget_jpy' => 500,
            'spent_jpy' => 150,
            'reserved_jpy' => 75,
            'remaining_jpy' => 275,
        ])->assertViewHas('aiMappingServiceConfigured', true);

        $response->assertSee('今月のAI利用状況（概算）')
            ->assertSee('利用済み 150円')
            ->assertSee('処理中 75円')
            ->assertSee('残り目安 275円')
            ->assertSee('上限 500円');
    }

    public function test_user_can_correct_ai_column_suggestion_before_mapping_is_saved(): void
    {
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.endpoint' => 'https://api.openai.com/v1/responses',
            'opsdeck.ai.escalation_enabled' => false,
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
            ],
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode(['suggestions' => [
                            ['source_column' => '社員番号', 'field' => 'name', 'confidence' => 0.96, 'reason' => '氏名の列かもしれません', 'requires_review' => false, 'question' => null],
                            ['source_column' => 'メールアドレス', 'field' => 'email', 'confidence' => 0.98, 'reason' => 'メールアドレスの列です', 'requires_review' => false, 'question' => null],
                        ]]),
                    ]],
                ]],
                'usage' => ['input_tokens' => 24, 'output_tokens' => 18],
            ], 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'ai_enabled' => true,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $batch = ImportBatch::factory()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員台帳',
            'headers_json' => ['社員番号', 'メールアドレス'],
            'status' => 'preview',
            'executed_by' => $user->id,
        ]);
        Queue::fake([PrepareImportMappingAnalysis::class]);

        $session = ['current_organization_id' => $organization->id];
        $suggestionResponse = $this->actingAs($user)->withSession($session)
            ->post(route('imports.mapping.ai', $batch), [
                'ai' => '1',
                'ai_target_type' => 'employees',
            ]);

        $suggestionResponse->assertOk()
            ->assertSee('AIが提案した列の対応')
            ->assertSee('信頼度は正しさの保証ではありません')
            ->assertSee('社員番号')
            ->assertSee('氏名');

        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            $input = json_decode((string) ($request->data()['input'] ?? ''), true);

            return is_array($input)
                && ($input['headers'] ?? null) === ['社員番号', 'メールアドレス']
                && ($input['target_type'] ?? null) === 'employees'
                && ! array_key_exists('rows', $input)
                && ! array_key_exists('data', $input);
        });

        $matches = [];
        $this->assertSame(
            1,
            preg_match('/name="ai_mapping_feedback_token" value="([a-f0-9]{64})"/', $suggestionResponse->getContent(), $matches),
        );

        $this->actingAs($user)->withSession($session)
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => ['社員番号' => 'employee_number', 'メールアドレス' => 'email'],
                'ai_mapping_feedback_token' => $matches[1],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->sole();
        $this->assertSame(['社員番号' => 'employee_number', 'メールアドレス' => 'email'], $mapping->column_map);
        $this->assertSame(2, $mapping->ai_suggestion_count);
        $this->assertSame(1, $mapping->ai_suggestion_accepted_count);
        $this->assertDatabaseCount('employees', 0);
        Queue::assertPushed(PrepareImportMappingAnalysis::class);
    }

    public function test_ai_provider_quota_failure_keeps_manual_mapping_flow_available(): void
    {
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.endpoint' => 'https://api.openai.com/v1/responses',
            'opsdeck.ai.escalation_enabled' => false,
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'error' => [
                'type' => 'insufficient_quota',
                'code' => 'insufficient_quota',
                'message' => 'provider_internal_detail_must_not_be_shown',
            ],
        ], 429)]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'ai_enabled' => true,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $batch = ImportBatch::factory()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員台帳',
            'headers_json' => ['社員番号', 'メールアドレス'],
            'status' => 'preview',
            'executed_by' => $user->id,
        ]);
        Queue::fake([PrepareImportMappingAnalysis::class]);

        $session = ['current_organization_id' => $organization->id];
        $suggestionResponse = $this->actingAs($user)->withSession($session)
            ->post(route('imports.mapping.ai', $batch), [
                'ai' => '1',
                'ai_target_type' => 'employees',
            ]);

        $suggestionResponse->assertOk()
            ->assertSee('AIが提案した列の対応')
            ->assertSee('候補なし')
            ->assertSee('AIサービスの利用枠またはAPI残高が上限に達しています。')
            ->assertSee('管理者がAI提供元の利用状況を確認するまで')
            ->assertSee('社員番号')
            ->assertSee('この列は使いません')
            ->assertDontSee('provider_internal_detail_must_not_be_shown');
        Http::assertSentCount(1);

        $this->actingAs($user)->withSession($session)
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => ['社員番号' => 'employee_number', 'メールアドレス' => 'email'],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->sole();
        $this->assertSame(['社員番号' => 'employee_number', 'メールアドレス' => 'email'], $mapping->column_map);
        $this->assertDatabaseCount('employees', 0);
        Queue::assertPushed(PrepareImportMappingAnalysis::class);
    }
}
