<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Services\Ai\ImportMappingSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiMappingLiveSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_openai_mapping_works_with_synthetic_headers_when_explicitly_enabled(): void
    {
        if (getenv('OPSDECK_RUN_LIVE_AI_SMOKE') !== '1') {
            $this->markTestSkipped('Set OPSDECK_RUN_LIVE_AI_SMOKE=1 to make one live OpenAI API request.');
        }

        $apiKey = getenv('OPSDECK_LIVE_AI_SMOKE_API_KEY');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            $this->markTestSkipped('Set OPSDECK_LIVE_AI_SMOKE_API_KEY for the explicitly enabled live test.');
        }

        $this->assertTrue((bool) config('opsdeck.ai.enabled'));
        $this->assertSame('openai', config('opsdeck.ai.provider'));
        $this->assertSame('gpt-6-luna', config('opsdeck.ai.models.fast'));

        config([
            'opsdeck.ai.api_key' => $apiKey,
            'opsdeck.ai.escalation_enabled' => false,
        ]);
        Http::allowStrayRequests(['https://api.openai.com/v1/responses']);

        $organization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'ai_enabled' => true,
        ]);

        $suggestions = app(ImportMappingSuggestionService::class)->suggest(
            $organization,
            ['氏名', 'メールアドレス'],
            'employees',
            ['name', 'email'],
        );

        $usage = AiUsageLog::query()
            ->where('organization_id', $organization->id)
            ->where('purpose', 'import_column_mapping')
            ->sole();

        $this->assertTrue($usage->success, 'The live provider request did not complete successfully.');
        $this->assertSame('gpt-6-luna', $usage->model);
        $this->assertGreaterThan(0, $usage->input_tokens);
        $this->assertGreaterThan(0, $usage->output_tokens);
        $this->assertLessThanOrEqual(
            (float) config('opsdeck.ai.monthly_budget_jpy.premium'),
            (float) $usage->estimated_cost_jpy,
        );
        $this->assertDatabaseMissing('ai_usage_reservations', ['organization_id' => $organization->id]);
        $this->assertSame(['氏名', 'メールアドレス'], array_keys($suggestions));
        $this->assertSame('name', $suggestions['氏名']['field']);
        $this->assertSame('email', $suggestions['メールアドレス']['field']);

        foreach ($suggestions as $suggestion) {
            $this->assertTrue($suggestion['field'] === null || in_array($suggestion['field'], ['name', 'email'], true));
        }

        fwrite(STDOUT, sprintf(
            "\nLIVE_AI_SMOKE model=%s input_tokens=%d output_tokens=%d estimated_jpy=%.4f mappings=name,email\n",
            $usage->model,
            $usage->input_tokens,
            $usage->output_tokens,
            (float) $usage->estimated_cost_jpy,
        ));
    }
}
