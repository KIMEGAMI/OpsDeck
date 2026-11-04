<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Models\AiUsageLog;
use App\Models\AiUsageReservation;
use App\Models\Organization;
use App\Services\Ai\AiProviderResponseException;
use App\Services\Ai\AiUsageBudgetService;
use App\Services\Ai\ImportMappingSuggestionService;
use App\Services\PlanAccessService;
use App\Services\PlanFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiMappingSuggestionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_environment_example_contains_valid_ai_model_pricing_json(): void
    {
        $environmentExample = file_get_contents(base_path('.env.example'));
        $this->assertIsString($environmentExample);
        $matches = [];

        $this->assertSame(1, preg_match('/^OPSDECK_AI_MODEL_PRICES_PER_MILLION_TOKENS=\'([^\']+)\'$/m', $environmentExample, $matches));

        $modelPrices = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
            'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
        ], $modelPrices);
    }

    public function test_ai_timeout_configuration_enforces_a_positive_request_timeout(): void
    {
        $environmentKey = 'OPSDECK_AI_TIMEOUT_SECONDS';
        $previousEnvironmentValue = getenv($environmentKey);
        $previousEnvironmentValueWasSet = array_key_exists($environmentKey, $_ENV);
        $previousEnvironmentValueInEnv = $_ENV[$environmentKey] ?? null;
        $previousEnvironmentValueWasInServer = array_key_exists($environmentKey, $_SERVER);
        $previousEnvironmentValueInServer = $_SERVER[$environmentKey] ?? null;

        putenv($environmentKey.'=0');
        $_ENV[$environmentKey] = '0';
        $_SERVER[$environmentKey] = '0';

        try {
            $configuration = require config_path('opsdeck.php');

            $this->assertSame(1, $configuration['ai']['timeout_seconds']);
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

    public function test_free_ai_budget_is_loaded_from_environment_configuration_and_shared_by_all_plans(): void
    {
        $environmentKey = 'OPSDECK_AI_MONTHLY_BUDGET_JPY';
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

            $this->assertSame(725, $configuration['ai']['monthly_budget_jpy']['free']);
            $this->assertSame(725, $configuration['ai']['monthly_budget_jpy']['premium']);
            $this->assertSame(725, $configuration['ai']['legacy_monthly_budget_jpy']);
            $this->assertSame(3, $configuration['ai']['daily_request_limit']);
            $this->assertContains('AI列マッピング候補（GPT-6 Luna・1日3回/組織）', $configuration['plans']['free']['features']);
            $this->assertSame(0, $configuration['plans']['premium']['monthly_price_jpy']);
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

    public function test_unusable_provider_responses_fall_back_and_log_safe_error_codes(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
        ]);
        $validOutput = ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
            ['source_column' => '氏名', 'field' => 'name', 'confidence' => 0.95, 'reason' => '氏名を示す列名のため', 'requires_review' => false, 'question' => null],
        ]])]]]]];
        Http::fakeSequence('api.openai.com/v1/responses')
            ->push(['status' => 'in_progress'], 200)
            ->push(['status' => 'completed', 'usage' => ['input_tokens' => 12, 'output_tokens' => 8], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'not-json']]]]], 200)
            ->push(['status' => 'completed'] + $validOutput, 200)
            ->push(['status' => 'completed', 'usage' => ['input_tokens' => '12', 'output_tokens' => -1]] + $validOutput, 200);

        $cases = [
            'Incomplete response' => AiProviderResponseException::RESPONSE_INCOMPLETE,
            'Invalid JSON' => AiProviderResponseException::INVALID_JSON,
            'Missing usage' => AiProviderResponseException::INVALID_USAGE,
            'Invalid usage' => AiProviderResponseException::INVALID_USAGE,
        ];

        foreach ($cases as $name => $expectedErrorCode) {
            $organization = Organization::query()->create([
                'name' => $name.' '.Str::random(8),
                'slug' => Str::slug($name.'-'.Str::random(8)),
                'timezone' => config('opsdeck.default_timezone'),
                'plan_code' => config('opsdeck.premium_plan_code'),
                'billing_status' => config('opsdeck.default_billing_status'),
                'ai_enabled' => true,
            ]);

            $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名'], 'employees', ['name']);

            $this->assertSame('fallback', $suggestions['氏名']['source']);
            $usage = AiUsageLog::query()->where('organization_id', $organization->id)->firstOrFail();
            $this->assertFalse($usage->success);
            $this->assertSame($expectedErrorCode, $usage->error_code);
            $this->assertGreaterThan(0, (float) $usage->estimated_cost_usd);
            $this->assertDatabaseMissing('ai_usage_reservations', ['organization_id' => $organization->id]);
        }

        Http::assertSentCount(4);
    }

    public function test_provider_rate_limit_returns_manual_fallback_without_leaving_a_reservation(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.escalation_enabled' => false,
            'opsdeck.ai.monthly_budget_jpy.premium' => 1,
            'opsdeck.ai.model_prices_per_million_tokens.gpt-6-luna' => [
                'input' => 1.0,
                'cached_input' => 0.1,
                'cache_write_input' => 1.0,
                'output' => 1.0,
            ],
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI provider rate limit '.Str::random(8),
            'slug' => 'ai-provider-rate-limit-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(
            ['error' => ['type' => 'rate_limit_error']],
            429,
            ['Retry-After' => '30'],
        )]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['社員番号'], 'employees', ['employee_number']);

        $this->assertSame('fallback', $suggestions['社員番号']['source']);
        $this->assertNull($suggestions['社員番号']['field']);
        $this->assertTrue($suggestions['社員番号']['requires_review']);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'success' => false,
            'error_code' => 'provider_http_429',
        ]);
        $usage = AiUsageLog::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(0.0, (float) $usage->estimated_cost_usd);
        $this->assertStringContainsString('AIサービス側の一時的な利用制限', $suggestions['社員番号']['reason']);
        $this->assertStringContainsString('30秒以上待ってから再実行', $suggestions['社員番号']['reason']);
        $this->assertDatabaseMissing('ai_usage_reservations', ['organization_id' => $organization->id]);
        $nextReservation = app(AiUsageBudgetService::class)->reserve(
            $organization,
            null,
            'gpt-6-luna',
            ['社員番号'],
            'employees',
            ['employee_number'],
        );
        $this->assertNotNull($nextReservation);
        $nextReservation->delete();
        Http::assertSentCount(1);
    }

    public function test_provider_http_statuses_are_logged_without_exposing_error_details(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.escalation_enabled' => false,
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence('api.openai.com/v1/responses')
            ->push(['error' => ['message' => 'provider_detail_must_not_be_shown']], 401)
            ->push(['error' => ['message' => 'provider_detail_must_not_be_shown']], 408)
            ->push(['error' => ['message' => 'provider_detail_must_not_be_shown']], 503);

        foreach ([401, 408, 503] as $status) {
            $organization = Organization::query()->create([
                'name' => 'AI HTTP '.$status.' '.Str::random(8),
                'slug' => 'ai-http-'.$status.'-'.Str::lower(Str::random(8)),
                'timezone' => config('opsdeck.default_timezone'),
                'plan_code' => config('opsdeck.premium_plan_code'),
                'billing_status' => config('opsdeck.default_billing_status'),
                'ai_enabled' => true,
            ]);

            $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['社員番号'], 'employees', ['employee_number']);
            $usage = AiUsageLog::query()->where('organization_id', $organization->id)->firstOrFail();

            $this->assertSame('fallback', $suggestions['社員番号']['source']);
            $this->assertSame('provider_http_'.$status, $usage->error_code);
            $this->assertStringNotContainsString('provider_detail_must_not_be_shown', $suggestions['社員番号']['reason']);
            $this->assertDatabaseMissing('ai_usage_reservations', ['organization_id' => $organization->id]);
        }

        Http::assertSentCount(3);
    }

    public function test_provider_quota_error_is_distinguished_from_a_generic_http_429(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.escalation_enabled' => false,
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI provider quota '.Str::random(8),
            'slug' => 'ai-provider-quota-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'error' => [
                'type' => 'insufficient_quota',
                'code' => 'insufficient_quota',
                'message' => 'provider_internal_detail_must_not_be_shown',
            ],
        ], 429)]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['社員番号'], 'employees', ['employee_number']);

        $this->assertSame('fallback', $suggestions['社員番号']['source']);
        $this->assertNull($suggestions['社員番号']['field']);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'success' => false,
            'error_code' => 'provider_quota_exhausted',
        ]);
        $usage = AiUsageLog::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(0.0, (float) $usage->estimated_cost_usd);
        $this->assertStringContainsString('API残高', $suggestions['社員番号']['reason']);
        $this->assertStringContainsString('AIを再実行せず', $suggestions['社員番号']['reason']);
        $this->assertStringNotContainsString('provider_internal_detail_must_not_be_shown', $suggestions['社員番号']['reason']);
        $this->assertDatabaseMissing('ai_usage_reservations', ['organization_id' => $organization->id]);
        Http::assertSentCount(1);
    }

    public function test_provider_connection_timeout_returns_manual_fallback_without_leaving_a_reservation(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.escalation_enabled' => false,
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI provider timeout '.Str::random(8),
            'slug' => 'ai-provider-timeout-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::failedConnection('Simulated provider timeout')]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['社員番号'], 'employees', ['employee_number']);

        $this->assertSame('fallback', $suggestions['社員番号']['source']);
        $this->assertNull($suggestions['社員番号']['field']);
        $this->assertTrue($suggestions['社員番号']['requires_review']);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'success' => false,
            'error_code' => 'provider_unavailable',
        ]);
        $usage = AiUsageLog::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertGreaterThan(0, (float) $usage->estimated_cost_usd);
        $this->assertDatabaseMissing('ai_usage_reservations', ['organization_id' => $organization->id]);
        Http::assertSentCount(1);
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
        $this->assertCount(1, $requests);
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

    public function test_free_plan_can_use_the_luna_model_within_its_daily_cap(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.monthly_budget_jpy.free' => 100,
            'opsdeck.ai.daily_request_limit' => 3,
        ]);
        $organization = Organization::query()->create([
            'name' => 'Free AI '.Str::random(8),
            'slug' => 'free-ai-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.free_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '社員名', 'field' => 'name', 'confidence' => 0.95, 'reason' => '名前を示す列名です', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['社員名'], 'employees', ['name', 'email']);

        $this->assertTrue(app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping'));
        $this->assertSame('name', $suggestions['社員名']['field']);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'model' => 'gpt-6-luna',
            'provider_request_attempted' => true,
            'success' => true,
        ]);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->data()['model'] === 'gpt-6-luna');
    }

    public function test_uncertain_mapping_uses_only_luna_and_keeps_human_review_required(): void
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
                ['source_column' => '社員名', 'field' => 'name', 'confidence' => 0.99, 'reason' => '社員名の列です', 'requires_review' => false, 'question' => null],
                ['source_column' => '担当', 'field' => null, 'confidence' => 0.0, 'reason' => '意味を判断できません', 'requires_review' => true, 'question' => '担当の意味を確認してください'],
            ]), 200)
            ->push($responseFor([
                ['source_column' => '担当', 'field' => 'email', 'confidence' => 0.85, 'reason' => 'メール列の可能性があります', 'requires_review' => false, 'question' => null],
            ]), 200);

        $this->assertSame([150, 500, 'gpt-6-luna', 'gpt-6-luna', false, 'premium'], [
            config('opsdeck.ai.usd_to_jpy_rate'),
            config('opsdeck.ai.monthly_budget_jpy.premium'),
            config('opsdeck.ai.models.fast'),
            config('opsdeck.ai.models.deep'),
            config('opsdeck.ai.escalation_enabled'),
            $organization->plan_code,
        ]);
        $this->assertSame(config('opsdeck.free_plan_code'), app(PlanAccessService::class)->effectivePlanCode($organization));
        $this->assertTrue(app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping'));
        $this->assertSame(['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00], config('opsdeck.ai.model_prices_per_million_tokens')['gpt-6.1-sol']);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['社員名', '担当'], 'employees', ['name', 'email']);

        Http::assertSentCount(1);
        $this->assertSame('name', $suggestions['社員名']['field']);
        $this->assertNull($suggestions['担当']['field']);
        $this->assertTrue($suggestions['担当']['requires_review']);
        $this->assertSame('none', $suggestions['担当']['source']);
        Http::assertSent(static fn (Request $request): bool => $request->data()['model'] === 'gpt-6-luna');
        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $organization->id, 'model' => 'gpt-6-luna', 'success' => true, 'provider_request_attempted' => true]);
        $this->assertDatabaseMissing('ai_usage_logs', ['organization_id' => $organization->id, 'model' => 'gpt-6.1-sol']);
    }

    public function test_uncertain_mapping_does_not_send_an_additional_ai_request(): void
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
        $this->assertDatabaseMissing('ai_usage_logs', ['organization_id' => $organization->id, 'error_code' => 'rate_limited']);
        Http::assertSentCount(1);
    }

    public function test_free_plan_ai_usage_is_limited_to_three_provider_requests_per_organization_day(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.monthly_budget_jpy.free' => 100,
            'opsdeck.ai.daily_request_limit' => 3,
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI limit '.Str::random(8),
            'slug' => 'ai-limit-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.free_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'success' => true,
            'provider_request_attempted' => true,
            'provider_requested_at' => now(),
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'success' => true,
            'provider_request_attempted' => true,
            'provider_requested_at' => now(),
        ]);
        AiUsageReservation::query()->create([
            'organization_id' => $organization->id,
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'reserved_cost_usd' => 0.001,
            'expires_at' => now()->addMinutes(5),
        ]);
        Http::preventStrayRequests();

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['社員名'], 'employees', ['name', 'email']);
        $summary = app(AiUsageBudgetService::class)->usageSummary($organization);

        $this->assertTrue(app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping'));
        $this->assertStringContainsString('本日のAI候補回数の上限（3回/組織）', $suggestions['社員名']['reason']);
        $this->assertSame(2, $summary['requests_used']);
        $this->assertSame(1, $summary['requests_pending']);
        $this->assertSame(0, $summary['requests_remaining']);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'error_code' => 'daily_request_limit_exceeded',
            'provider_request_attempted' => false,
        ]);
        Http::assertNothingSent();
    }

    public function test_expired_provider_attempt_reservations_continue_to_count_toward_the_daily_limit(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.monthly_budget_jpy.free' => 100,
            'opsdeck.ai.daily_request_limit' => 3,
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI expired attempt '.Str::random(8),
            'slug' => 'ai-expired-attempt-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.free_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        foreach (range(1, 3) as $attempt) {
            AiUsageReservation::query()->create([
                'organization_id' => $organization->id,
                'model' => 'gpt-6-luna',
                'purpose' => 'import_column_mapping',
                'reserved_cost_usd' => 0.001,
                'provider_requested_at' => now()->subMinutes(5)->subSeconds($attempt),
                'expires_at' => now()->subMinutes(1),
            ]);
        }
        Http::preventStrayRequests();

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['社員名'], 'employees', ['name', 'email']);
        $summary = app(AiUsageBudgetService::class)->usageSummary($organization);

        $this->assertStringContainsString('本日のAI候補回数の上限（3回/組織）', $suggestions['社員名']['reason']);
        $this->assertSame(3, $summary['requests_used']);
        $this->assertSame(0, $summary['requests_pending']);
        $this->assertSame(0, $summary['requests_remaining']);
        $this->assertNotNull(AiUsageReservation::query()
            ->where('organization_id', $organization->id)
            ->firstOrFail()
            ->provider_requested_at);
        Http::assertNothingSent();
    }

    public function test_provider_attempt_is_recorded_before_the_external_request(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.endpoint' => 'https://api.openai.com/v1/responses',
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.monthly_budget_jpy.free' => 100,
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI attempt persistence '.Str::random(8),
            'slug' => 'ai-attempt-persistence-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.free_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        $this->mock(AiProvider::class)
            ->shouldReceive('suggestColumnMapping')
            ->once()
            ->andReturnUsing(function () use ($organization): array {
                $this->assertTrue(AiUsageReservation::query()
                    ->where('organization_id', $organization->id)
                    ->whereNotNull('provider_requested_at')
                    ->exists());

                return [
                    'suggestions' => ['社員名' => ['field' => 'name', 'confidence' => 0.9, 'reason' => '列名から候補を選択', 'requires_review' => true, 'question' => null]],
                    'input_tokens' => 10,
                    'cached_input_tokens' => 0,
                    'cache_write_input_tokens' => 0,
                    'output_tokens' => 5,
                ];
            });

        app(ImportMappingSuggestionService::class)->suggest($organization, ['社員名'], 'employees', ['name', 'email']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'provider_request_attempted' => true,
        ]);
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

    public function test_ai_usage_cost_applies_long_context_rates_only_above_the_provider_threshold(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.escalation_enabled' => false,
            'opsdeck.ai.usd_to_jpy_rate' => 150,
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI long context '.Str::random(8),
            'slug' => 'ai-long-context-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        $responseFor = static fn (int $inputTokens): array => [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '社員番号', 'field' => 'employee_number', 'confidence' => 0.98, 'reason' => '社員番号の列です', 'requires_review' => false, 'question' => null],
            ]], JSON_THROW_ON_ERROR)]]]],
            'usage' => [
                'input_tokens' => $inputTokens,
                'input_tokens_details' => ['cached_tokens' => 10000, 'cache_write_tokens' => 20000],
                'output_tokens' => 100,
            ],
        ];
        Http::preventStrayRequests();
        Http::fakeSequence('api.openai.com/v1/responses')
            ->push($responseFor(272000), 200)
            ->push($responseFor(272001), 200);

        app(ImportMappingSuggestionService::class)->suggest($organization, ['社員番号'], 'employees', ['employee_number']);
        app(ImportMappingSuggestionService::class)->suggest($organization, ['社員番号'], 'employees', ['employee_number']);

        $usageLogs = AiUsageLog::query()->where('organization_id', $organization->id)->orderBy('id')->get();
        $this->assertCount(2, $usageLogs);
        $this->assertSame('0.026850', $usageLogs[0]->estimated_cost_usd);
        $this->assertSame('0.053675', $usageLogs[1]->estimated_cost_usd);
        $this->assertSame('4.03', $usageLogs[0]->estimated_cost_jpy);
        $this->assertSame('8.05', $usageLogs[1]->estimated_cost_jpy);
        Http::assertSentCount(2);
    }

    public function test_ai_budget_reservation_accounts_for_configured_long_context_rates(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $longContextPricing = [
            'input_token_threshold' => 1,
            'input_cache_rate_multiplier' => 2.0,
            'output_rate_multiplier' => 1.5,
        ];
        config([
            'opsdeck.ai.usd_to_jpy_rate' => 1,
            'opsdeck.ai.monthly_budget_jpy.premium' => 10000,
            'opsdeck.ai.input_token_estimate_multiplier' => 1,
            'opsdeck.ai.estimated_input_overhead_tokens' => 0,
            'opsdeck.ai.max_output_tokens' => 10,
            'opsdeck.ai.tokens_per_million' => 1,
            'opsdeck.ai.long_context_pricing.gpt-6-luna' => $longContextPricing,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 1.00, 'cached_input' => 0.10, 'cache_write_input' => 2.00, 'output' => 3.00],
            ],
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI long reserve '.Str::random(8),
            'slug' => 'ai-long-reserve-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        $headers = ['Asset number'];
        $targetFields = ['asset_number'];
        $encodedInput = json_encode([
            'headers' => $headers,
            'target_type' => 'assets',
            'target_fields' => $targetFields,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $inputTokens = strlen($encodedInput);

        $reservation = app(AiUsageBudgetService::class)->reserve($organization, null, 'gpt-6-luna', $headers, 'assets', $targetFields);

        $this->assertNotNull($reservation);
        $this->assertSame(round(($inputTokens * 2.00 * 2.0) + (10 * 3.00 * 1.5), 6), round((float) $reservation->reserved_cost_usd, 6));
    }

    public function test_monthly_budget_reserves_input_at_cache_write_rate_to_prevent_under_reservation(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.usd_to_jpy_rate' => 10000,
            'opsdeck.ai.monthly_budget_jpy.free' => 1,
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

    public function test_ai_usage_reservation_outlives_configured_provider_timeout(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config([
            'opsdeck.ai.timeout_seconds' => 240,
            'opsdeck.ai.monthly_budget_reservation_ttl_minutes' => 1,
        ]);
        $organization = Organization::query()->create([
            'name' => 'AI reservation timeout '.Str::random(8),
            'slug' => 'ai-reservation-timeout-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
            'ai_enabled' => true,
        ]);
        $startedAt = now();

        $reservation = app(AiUsageBudgetService::class)->reserve(
            $organization,
            null,
            'gpt-6-luna',
            ['社員番号'],
            'employees',
            ['employee_number'],
        );

        $this->assertNotNull($reservation);
        $this->assertGreaterThanOrEqual(
            $startedAt->copy()->addMinutes(5)->getTimestamp(),
            $reservation->expires_at->getTimestamp(),
        );
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
