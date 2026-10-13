<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Services\Ai\ImportMappingSuggestionService;
use App\Services\PlanAccessService;
use App\Services\PlanFeatureService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiMappingSuggestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
            ],
        ]);
    }

    public function test_ai_model_pricing_is_loaded_from_environment_configuration(): void
    {
        $environmentKey = 'OPSDECK_AI_MODEL_PRICES_PER_MILLION_TOKENS';
        $previousEnvironmentValue = getenv($environmentKey);
        $previousEnvironmentValueWasSet = array_key_exists($environmentKey, $_ENV);
        $previousEnvironmentValueInEnv = $_ENV[$environmentKey] ?? null;
        $previousEnvironmentValueWasInServer = array_key_exists($environmentKey, $_SERVER);
        $previousEnvironmentValueInServer = $_SERVER[$environmentKey] ?? null;
        $pricingJson = '{"test-model":{"input":0.25,"cached_input":0.025,"cache_write_input":0.3125,"output":1.25}}';

        putenv($environmentKey.'='.$pricingJson);
        $_ENV[$environmentKey] = $pricingJson;
        $_SERVER[$environmentKey] = $pricingJson;

        try {
            $configuration = require config_path('opsdeck.php');

            $this->assertSame([
                'test-model' => ['input' => 0.25, 'cached_input' => 0.025, 'cache_write_input' => 0.3125, 'output' => 1.25],
            ], $configuration['ai']['model_prices_per_million_tokens']);
        } finally {
            if ($previousEnvironmentValue === false) {
                putenv($environmentKey);
            } else {
                putenv($environmentKey.'='.$previousEnvironmentValue);
            }

            if ($previousEnvironmentValueWasSet) {
                $_ENV[$environmentKey] = $previousEnvironmentValueInEnv;
            } else {
                unset($_ENV[$environmentKey]);
            }

            if ($previousEnvironmentValueWasInServer) {
                $_SERVER[$environmentKey] = $previousEnvironmentValueInServer;
            } else {
                unset($_SERVER[$environmentKey]);
            }
        }
    }

    public function test_premium_ai_budget_is_loaded_from_environment_configuration_and_shared_with_legacy_plan(): void
    {
        $environmentKey = 'OPSDECK_AI_PREMIUM_MONTHLY_BUDGET_JPY';
        $previousEnvironmentValue = getenv($environmentKey);
        $previousEnvironmentValueWasSet = array_key_exists($environmentKey, $_ENV);
        $previousEnvironmentValueInEnv = $_ENV[$environmentKey] ?? null;
        $previousEnvironmentValueWasInServer = array_key_exists($environmentKey, $_SERVER);
        $previousEnvironmentValueInServer = $_SERVER[$environmentKey] ?? null;

        putenv($environmentKey.'=725');
        $_ENV[$environmentKey] = '725';
        $_SERVER[$environmentKey] = '725';

        try {
            $configuration = require config_path('opsdeck.php');

            $this->assertSame(725, $configuration['ai']['monthly_budget_jpy']['premium']);
            $this->assertSame(725, $configuration['ai']['legacy_monthly_budget_jpy']);
            $this->assertContains('AI列マッピング候補（月725円相当まで）', $configuration['plans']['premium']['features']);
        } finally {
            if ($previousEnvironmentValue === false) {
                putenv($environmentKey);
            } else {
                putenv($environmentKey.'='.$previousEnvironmentValue);
            }

            if ($previousEnvironmentValueWasSet) {
                $_ENV[$environmentKey] = $previousEnvironmentValueInEnv;
            } else {
                unset($_ENV[$environmentKey]);
            }

            if ($previousEnvironmentValueWasInServer) {
                $_SERVER[$environmentKey] = $previousEnvironmentValueInServer;
            } else {
                unset($_SERVER[$environmentKey]);
            }
        }
    }

    public function test_it_returns_validated_ai_mapping_and_logs_usage(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        $organization = Organization::query()->create(['name' => 'AI '.Str::random(8), 'slug' => 'ai-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '氏名', 'field' => 'name', 'confidence' => 0.95, 'reason' => '氏名を示す列名のため', 'requires_review' => false, 'question' => null],
                ['source_column' => '秘密', 'field' => null, 'confidence' => 0.0, 'reason' => '許可された取込項目にありません', 'requires_review' => true, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名', '秘密'], 'employees', ['name', 'email']);

        $this->assertSame('name', $suggestions['氏名']['field']);
        $this->assertSame(0.95, $suggestions['氏名']['confidence']);
        $this->assertSame('氏名を示す列名のため', $suggestions['氏名']['reason']);
        $this->assertNull($suggestions['秘密']['field']);
        $this->assertTrue($suggestions['秘密']['requires_review']);
        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $organization->id, 'purpose' => 'import_column_mapping', 'success' => true]);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return str_ends_with($request->url(), '/v1/responses')
                && $payload['model'] === 'gpt-6-luna'
                && $payload['store'] === false
                && $payload['text']['format']['type'] === 'json_schema'
                && $payload['text']['format']['strict'] === true
                && $payload['input'] === json_encode(['headers' => ['氏名', '秘密'], 'target_type' => 'employees', 'target_fields' => ['name', 'email']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        });
    }

    public function test_ai_disabled_does_not_call_provider(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => false]);
        $organization = Organization::query()->create(['name' => 'No AI '.Str::random(8), 'slug' => 'no-ai-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => false]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名'], 'employees', ['name']);

        $this->assertSame('disabled', $suggestions['氏名']['source']);
        $this->assertSame(0, AiUsageLog::query()->where('organization_id', $organization->id)->count());
    }

    public function test_free_plan_never_calls_ai_even_when_organization_setting_is_enabled(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        $organization = Organization::query()->create(['name' => 'AI Free '.Str::random(8), 'slug' => 'ai-free-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::preventStrayRequests();

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名'], 'employees', ['name']);

        $this->assertSame('disabled', $suggestions['氏名']['source']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage_reservations', 0);
    }

    public function test_monthly_budget_blocks_provider_calls_once_premium_cap_is_exhausted(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        $organization = Organization::query()->create(['name' => 'AI capped '.Str::random(8), 'slug' => 'ai-capped-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        AiUsageLog::query()->create(['organization_id' => $organization->id, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'purpose' => 'import_column_mapping', 'estimated_cost_usd' => 4, 'estimated_cost_jpy' => 600, 'success' => true]);
        Http::preventStrayRequests();

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名'], 'employees', ['name']);

        $this->assertSame('fallback', $suggestions['氏名']['source']);
        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $organization->id, 'error_code' => 'monthly_budget_exceeded']);
        Http::assertNothingSent();
    }

    public function test_personal_identifiers_in_headers_are_masked_before_provider_submission(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        $organization = Organization::query()->create(['name' => 'AI privacy '.Str::random(8), 'slug' => 'ai-privacy-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        $headers = ['担当者: yamada@example.test', '連絡先: 090-1234-5678', "不正\xFF列名"];
        $safeHeaders = ['担当者: [メールアドレス] [列1]', '連絡先: [電話番号] [列2]', '[文字コード確認が必要な列] [列3]'];
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => $safeHeaders[0], 'field' => 'email', 'confidence' => 0.95, 'reason' => 'メール連絡先を示す列です', 'requires_review' => true, 'question' => null],
                ['source_column' => $safeHeaders[1], 'field' => null, 'confidence' => 0.0, 'reason' => '安全に判断できません', 'requires_review' => true, 'question' => null],
                ['source_column' => $safeHeaders[2], 'field' => null, 'confidence' => 0.0, 'reason' => '安全に判断できません', 'requires_review' => true, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, $headers, 'employees', ['name', 'email']);

        $this->assertSame('email', $suggestions[$headers[0]]['field']);
        $this->assertArrayNotHasKey($safeHeaders[0], $suggestions);
        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        foreach ($requests as $recordedRequest) {
            $payload = json_decode((string) ($recordedRequest[0]->data()['input'] ?? ''), true);
            $this->assertIsArray($payload);
            $this->assertSame($safeHeaders, $payload['headers'] ?? null);
            $serializedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('yamada@example.test', $serializedPayload);
            $this->assertStringNotContainsString('090-1234-5678', $serializedPayload);
        }
    }

    public function test_missing_provider_credentials_returns_manual_fallback_and_logs_unavailable(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => null]);
        $organization = Organization::query()->create(['name' => 'AI unconfigured '.Str::random(8), 'slug' => 'ai-unconfigured-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::preventStrayRequests();

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名'], 'employees', ['name', 'email']);

        $this->assertNull($suggestions['氏名']['field']);
        $this->assertSame('fallback', $suggestions['氏名']['source']);
        $this->assertTrue($suggestions['氏名']['requires_review']);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'success' => false,
            'error_code' => 'provider_unavailable',
            'estimated_cost_usd' => 0,
        ]);
    }

    public function test_ai_provider_does_not_send_credentials_to_an_untrusted_endpoint(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.endpoint' => 'https://api.openai.com.attacker.test/v1/responses',
        ]);
        $organization = Organization::query()->create(['name' => 'AI endpoint '.Str::random(8), 'slug' => 'ai-endpoint-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::preventStrayRequests();

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名'], 'employees', ['name', 'email']);

        $this->assertSame('fallback', $suggestions['氏名']['source']);
        $this->assertTrue($suggestions['氏名']['requires_review']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'success' => false,
            'error_code' => 'provider_unavailable',
        ]);
    }

    public function test_provider_rejects_unknown_fields_and_conflicting_suggestions(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        $organization = Organization::query()->create(['name' => 'AI validation '.Str::random(8), 'slug' => 'ai-validation-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '氏名', 'field' => 'name', 'confidence' => 0.95, 'reason' => '候補1', 'requires_review' => false, 'question' => null],
                ['source_column' => '氏名', 'field' => 'email', 'confidence' => 0.91, 'reason' => '候補2', 'requires_review' => false, 'question' => null],
                ['source_column' => '秘密', 'field' => 'password', 'confidence' => 0.99, 'reason' => '未知項目', 'requires_review' => false, 'question' => null],
                ['source_column' => '無関係な列', 'field' => 'name', 'confidence' => 0.8, 'reason' => '要求外', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ], 200)]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名', '秘密'], 'employees', ['name', 'email']);

        $this->assertNull($suggestions['氏名']['field']);
        $this->assertTrue($suggestions['氏名']['requires_review']);
        $this->assertNull($suggestions['秘密']['field']);
        $this->assertSame(['氏名', '秘密'], array_keys($suggestions));
    }

    public function test_uncertain_mapping_escalates_once_and_keeps_human_review_required(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        $organization = Organization::query()->create(['name' => 'AI escalation '.Str::random(8), 'slug' => 'ai-escalation-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        $responseFor = static fn (array $suggestions): array => [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => $suggestions])]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
        Http::fakeSequence('api.openai.com/v1/responses')
            ->push($responseFor([
                ['source_column' => '担当', 'field' => null, 'confidence' => 0.0, 'reason' => '意味を判断できません', 'requires_review' => true, 'question' => '担当の意味を確認してください'],
            ]), 200)
            ->push($responseFor([
                ['source_column' => '担当', 'field' => 'email', 'confidence' => 0.85, 'reason' => 'メール列の可能性があります', 'requires_review' => false, 'question' => null],
            ]), 200);

        $this->assertSame([150, 500, 'gpt-6.1-sol', 'premium'], [
            config('opsdeck.ai.usd_to_jpy_rate'),
            config('opsdeck.ai.monthly_budget_jpy.premium'),
            config('opsdeck.ai.models.deep'),
            $organization->plan_code,
        ]);
        $this->assertSame('premium', app(PlanAccessService::class)->effectivePlanCode($organization));
        $this->assertTrue(app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping'));
        $this->assertSame(['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00], config('opsdeck.ai.model_prices_per_million_tokens')['gpt-6.1-sol']);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['担当'], 'employees', ['name', 'email']);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $organization->id, 'model' => 'gpt-6.1-sol', 'success' => true]);
        $this->assertSame('email', $suggestions['担当']['field']);
        $this->assertTrue($suggestions['担当']['requires_review']);
        $this->assertSame('ai_escalated', $suggestions['担当']['source']);
        Http::assertSent(static fn (Request $request): bool => $request->data()['model'] === 'gpt-6-luna');
        Http::assertSent(static fn (Request $request): bool => $request->data()['model'] === 'gpt-6.1-sol' && $request->data()['reasoning']['effort'] === 'medium');
        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $organization->id, 'model' => 'gpt-6-luna', 'success' => true]);
    }

    public function test_escalation_is_skipped_when_provider_call_limit_is_reached(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.mapping_suggestions_max_attempts' => 1,
        ]);
        $organization = Organization::query()->create(['name' => 'AI escalation limit '.Str::random(8), 'slug' => 'ai-escalation-limit-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '担当', 'field' => null, 'confidence' => 0.0, 'reason' => '意味を判断できません', 'requires_review' => true, 'question' => '担当の意味を確認してください'],
            ]])]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['担当'], 'employees', ['name', 'email']);

        $this->assertNull($suggestions['担当']['field']);
        $this->assertTrue($suggestions['担当']['requires_review']);
        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $organization->id, 'model' => 'gpt-6.1-sol', 'error_code' => 'rate_limited']);
        Http::assertSentCount(1);
    }

    public function test_usage_log_calculates_model_cost_and_only_converts_yen_when_rate_is_configured(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.usd_to_jpy_rate' => 150.5,
        ]);
        $organization = Organization::query()->create(['name' => 'AI costs '.Str::random(8), 'slug' => 'ai-costs-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '社員番号', 'field' => 'employee_number', 'confidence' => 0.98, 'reason' => '社員番号を示す列名です', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => [
                'input_tokens' => 100000,
                'input_tokens_details' => ['cached_tokens' => 40000, 'cache_write_tokens' => 20000],
                'output_tokens' => 20000,
            ],
        ], 200)]);

        app(ImportMappingSuggestionService::class)->suggest($organization, ['社員番号'], 'employees', ['employee_number', 'email']);

        $usage = AiUsageLog::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(100000, $usage->input_tokens);
        $this->assertSame('0.016900', $usage->estimated_cost_usd);
        $this->assertSame('2.54', $usage->estimated_cost_jpy);
        $this->assertNull($usage->error_code);
    }

    public function test_monthly_budget_reserves_input_at_cache_write_rate_to_prevent_under_reservation(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.usd_to_jpy_rate' => 10000,
            'opsdeck.ai.monthly_budget_jpy.premium' => 1,
            'opsdeck.ai.input_token_estimate_multiplier' => 1,
            'opsdeck.ai.estimated_input_overhead_tokens' => 0,
            'opsdeck.ai.max_output_tokens' => 1,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 1.00, 'cached_input' => 0.10, 'cache_write_input' => 2.00, 'output' => 0.00],
            ],
        ]);
        $organization = Organization::query()->create(['name' => 'AI cache reserve '.Str::random(8), 'slug' => 'ai-cache-reserve-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active', 'ai_enabled' => true]);
        Http::preventStrayRequests();

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['Asset number'], 'assets', ['asset_number']);

        $this->assertSame('fallback', $suggestions['Asset number']['source']);
        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $organization->id, 'error_code' => 'monthly_budget_exceeded']);
        Http::assertNothingSent();
    }

    public function test_usage_log_marks_unknown_model_pricing_instead_of_reporting_free_usage(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.models.fast' => 'custom-model-without-price',
            'opsdeck.ai.models.deep' => 'custom-model-without-price',
        ]);
        $organization = Organization::query()->create(['name' => 'AI unknown cost '.Str::random(8), 'slug' => 'ai-unknown-cost-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '社員番号', 'field' => 'employee_number', 'confidence' => 0.98, 'reason' => '社員番号を示す列名です', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 100000, 'output_tokens' => 20000],
        ], 200)]);

        app(ImportMappingSuggestionService::class)->suggest($organization, ['社員番号'], 'employees', ['employee_number', 'email']);

        $usage = AiUsageLog::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('budget_configuration_unavailable', $usage->error_code);
        Http::assertNothingSent();
    }
}
