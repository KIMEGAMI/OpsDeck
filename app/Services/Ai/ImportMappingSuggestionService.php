<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use App\Models\AiUsageLog;
use App\Models\Organization;

class ImportMappingSuggestionService
{
    public function __construct(private AiProvider $provider) {}

    /** @return array<string, array{field: string|null, confidence: float, reason: string, source: string}> */
    public function suggest(Organization $organization, array $headers, string $targetType, array $targetFields, ?int $userId = null): array
    {
        if (! config('opsdeck.ai.enabled') || ! $organization->ai_enabled) {
            return array_fill_keys($headers, ['field' => null, 'confidence' => 0.0, 'reason' => 'AI候補が無効です。列名を確認して手動で設定してください。', 'source' => 'disabled']);
        }
        try {
            $result = $this->provider->suggestColumnMapping($headers, $targetType, $targetFields);
        } catch (\Throwable) {
            AiUsageLog::query()->create(['organization_id' => $organization->id, 'user_id' => $userId, 'provider' => config('opsdeck.ai.provider'), 'model' => config('opsdeck.ai.models.fast'), 'purpose' => 'import_column_mapping', 'input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost_usd' => 0, 'estimated_cost_jpy' => 0, 'success' => false, 'error_code' => 'provider_unavailable']);

            return array_fill_keys($headers, ['field' => null, 'confidence' => 0.0, 'reason' => 'AIサービスから候補を取得できませんでした。列名を確認して手動で設定してください。', 'source' => 'fallback']);
        }
        if (config('opsdeck.ai.enabled')) {
            AiUsageLog::query()->create(['organization_id' => $organization->id, 'user_id' => $userId, 'provider' => config('opsdeck.ai.provider'), 'model' => config('opsdeck.ai.models.fast'), 'purpose' => 'import_column_mapping', 'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'], 'estimated_cost_usd' => 0, 'estimated_cost_jpy' => 0, 'success' => true]);
        }

        return array_map(fn (array $suggestion): array => $suggestion + ['source' => $suggestion['field'] === null ? 'none' : 'ai'], $result['suggestions']);
    }
}
