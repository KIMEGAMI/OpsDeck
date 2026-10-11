<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Services\Ai\ImportMappingSuggestionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiMappingSuggestionTest extends TestCase
{
    public function test_it_returns_validated_ai_mapping_and_logs_usage(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        $organization = Organization::query()->create(['name' => 'AI '.Str::random(8), 'slug' => 'ai-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status'), 'ai_enabled' => true]);
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['suggestions' => ['氏名' => ['field' => 'name', 'confidence' => 0.95, 'reason' => '氏名を示す列名のため'], '秘密' => ['field' => 'password', 'confidence' => 0.99, 'reason' => '許可された取込項目にありません']]])]]], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8]], 200)]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest($organization, ['氏名', '秘密'], 'employees', ['name', 'email']);

        $this->assertSame('name', $suggestions['氏名']['field']);
        $this->assertSame(0.95, $suggestions['氏名']['confidence']);
        $this->assertSame('氏名を示す列名のため', $suggestions['氏名']['reason']);
        $this->assertNull($suggestions['秘密']['field']);
        $this->assertDatabaseHas('ai_usage_logs', ['organization_id' => $organization->id, 'purpose' => 'import_column_mapping', 'success' => true]);
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
}
