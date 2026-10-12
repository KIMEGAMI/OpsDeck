<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use App\Models\Organization;
use App\Services\PlanAccessService;
use App\Services\PlanFeatureService;
use Illuminate\Support\Facades\RateLimiter;

class ImportMappingSuggestionService
{
    public function __construct(private AiProvider $provider, private AiUsageBudgetService $budgetService) {}

    /** @return array<string, array{field: string|null, confidence: float, reason: string, requires_review: bool, question: string|null, source: string}> */
    public function suggest(Organization $organization, array $headers, string $targetType, array $targetFields, ?int $userId = null): array
    {
        if (! config('opsdeck.ai.enabled') || ! $organization->ai_enabled || ! app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping')) {
            return array_fill_keys($headers, ['field' => null, 'confidence' => 0.0, 'reason' => 'AI候補が無効です。列名を確認して手動で設定してください。', 'requires_review' => true, 'question' => null, 'source' => 'disabled']);
        }
        $fastModel = (string) config('opsdeck.ai.models.fast');
        $apiKey = config('opsdeck.ai.api_key');
        if (config('opsdeck.ai.provider') !== 'openai' || ! is_string($apiKey) || trim($apiKey) === '') {
            $this->budgetService->recordBlocked($organization, $userId, $fastModel, 'provider_unavailable');

            return array_fill_keys($headers, ['field' => null, 'confidence' => 0.0, 'reason' => 'AIサービスの接続設定がありません。列名を確認して手動で設定してください。', 'requires_review' => true, 'question' => null, 'source' => 'fallback']);
        }
        $providerHeaders = $this->headersForProvider($headers);
        if (! $this->reserveProviderAttempt($organization, $userId)) {
            abort(429, 'AI候補の取得回数が上限に達しました。しばらくしてから再度お試しください。');
        }
        $fastReservation = $this->budgetService->reserve($organization, $userId, $fastModel, $providerHeaders, $targetType, $targetFields);
        if ($fastReservation === null) {
            $this->budgetService->recordBlocked($organization, $userId, $fastModel, $this->budgetBlockReason($organization, $fastModel));

            return array_fill_keys($headers, ['field' => null, 'confidence' => 0.0, 'reason' => 'AI月額上限または費用設定によりAI候補を利用できません。列名を確認して手動で設定してください。', 'requires_review' => true, 'question' => null, 'source' => 'fallback']);
        }
        try {
            $result = $this->provider->suggestColumnMapping($providerHeaders, $targetType, $targetFields, $fastModel, (string) config('opsdeck.ai.reasoning_effort'));
        } catch (\Throwable) {
            $this->budgetService->settle($fastReservation, 0, 0, false);

            return array_fill_keys($headers, ['field' => null, 'confidence' => 0.0, 'reason' => 'AIサービスから候補を取得できませんでした。列名を確認して手動で設定してください。', 'requires_review' => true, 'question' => null, 'source' => 'fallback']);
        }
        $this->budgetService->settle($fastReservation, $result['input_tokens'], $result['output_tokens'], true);

        $suggestions = $this->restoreOriginalHeaders($result['suggestions'], $headers, $providerHeaders);
        $escalationModel = (string) config('opsdeck.ai.models.deep');
        $escalationHeaders = $this->headersRequiringEscalation($suggestions);
        if (config('opsdeck.ai.escalation_enabled') && $escalationModel !== '' && $escalationModel !== $fastModel && $escalationHeaders !== []) {
            if (! $this->reserveProviderAttempt($organization, $userId)) {
                $this->budgetService->recordBlocked($organization, $userId, $escalationModel, 'rate_limited');

                return array_map(fn (array $suggestion): array => $suggestion + ['source' => $suggestion['field'] === null ? 'none' : 'ai'], $suggestions);
            }

            $escalationReservation = $this->budgetService->reserve($organization, $userId, $escalationModel, $providerHeaders, $targetType, $targetFields);
            if ($escalationReservation === null) {
                $this->budgetService->recordBlocked($organization, $userId, $escalationModel, $this->budgetBlockReason($organization, $escalationModel));

                return array_map(fn (array $suggestion): array => $suggestion + ['source' => $suggestion['field'] === null ? 'none' : 'ai'], $suggestions);
            }

            try {
                $escalated = $this->provider->suggestColumnMapping($providerHeaders, $targetType, $targetFields, $escalationModel, (string) config('opsdeck.ai.escalation_reasoning_effort'));
                $this->budgetService->settle($escalationReservation, $escalated['input_tokens'], $escalated['output_tokens'], true);
                $escalatedSuggestions = $this->restoreOriginalHeaders($escalated['suggestions'], $headers, $providerHeaders);
                foreach ($escalationHeaders as $header) {
                    $candidate = $escalatedSuggestions[$header] ?? null;
                    if (! is_array($candidate) || ($candidate['field'] ?? null) === null) {
                        continue;
                    }

                    $suggestions[$header] = $candidate + ['source' => 'ai_escalated'];
                    $suggestions[$header]['source'] = 'ai_escalated';
                    $suggestions[$header]['requires_review'] = true;
                }
            } catch (\Throwable) {
                $this->budgetService->settle($escalationReservation, 0, 0, false);
            }
        }

        return array_map(fn (array $suggestion): array => $suggestion + ['source' => $suggestion['field'] === null ? 'none' : 'ai'], $suggestions);
    }

    private function budgetBlockReason(Organization $organization, string $model): string
    {
        $plan = app(PlanAccessService::class)->effectivePlanCode($organization);
        $rate = config('opsdeck.ai.usd_to_jpy_rate');
        $modelPrices = config('opsdeck.ai.model_prices_per_million_tokens', []);
        $pricing = is_array($modelPrices) ? ($modelPrices[$model] ?? null) : null;

        $budgetJpy = (int) config("opsdeck.ai.monthly_budget_jpy.{$plan}", $plan === config('opsdeck.legacy_plan_code') ? config('opsdeck.ai.legacy_monthly_budget_jpy') : 0);

        return $budgetJpy <= 0
            || ! is_numeric($rate)
            || (float) $rate <= 0
            || ! is_array($pricing)
            || ! is_numeric($pricing['input'] ?? null)
            || ! is_numeric($pricing['output'] ?? null)
            || (float) $pricing['input'] < 0
            || (float) $pricing['output'] < 0
            || ((float) $pricing['input'] === 0.0 && (float) $pricing['output'] === 0.0)
            ? 'budget_configuration_unavailable'
            : 'monthly_budget_exceeded';
    }

    private function reserveProviderAttempt(Organization $organization, ?int $userId): bool
    {
        $decaySeconds = config('opsdeck.ai.mapping_suggestion_decay_seconds');
        $maximumAttempts = config('opsdeck.ai.mapping_suggestions_max_attempts');
        $userRateLimitKey = 'opsdeck:ai-mapping-suggestions:user:'.hash('sha256', $organization->getKey().'|'.($userId ?? 'system'));
        if (RateLimiter::hit($userRateLimitKey, $decaySeconds) > $maximumAttempts) {
            return false;
        }

        $organizationRateLimitKey = 'opsdeck:ai-mapping-suggestions:organization:'.hash('sha256', (string) $organization->getKey());

        return RateLimiter::hit($organizationRateLimitKey, $decaySeconds) <= $maximumAttempts;
    }

    /** @param list<string> $headers
     * @return list<string>
     */
    private function headersForProvider(array $headers): array
    {
        $headers = array_values($headers);

        return array_map(function (string $header, int $index): string {
            if (! mb_check_encoding($header, 'UTF-8')) {
                return '[文字コード確認が必要な列] [列'.($index + 1).']';
            }

            $sanitizedHeader = preg_replace(
                '/(?<![\p{L}\p{N}._%+-])[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}(?![\p{L}\p{N}.-])/u',
                '[メールアドレス]',
                $header,
            ) ?? $header;
            $sanitizedHeader = preg_replace_callback(
                '/(?<!\d)\+?[\d().\s-]{9,}\d(?!\d)/u',
                static function (array $matches): string {
                    $digitCount = strlen(preg_replace('/\D/', '', $matches[0]) ?? '');
                    preg_match('/^\s*/u', $matches[0], $leadingWhitespace);
                    preg_match('/\s*$/u', $matches[0], $trailingWhitespace);

                    return $digitCount >= 10
                        ? ($leadingWhitespace[0] ?? '').'[電話番号]'.($trailingWhitespace[0] ?? '')
                        : $matches[0];
                },
                $sanitizedHeader,
            ) ?? $sanitizedHeader;

            return $sanitizedHeader === $header
                ? $header
                : $sanitizedHeader.' [列'.($index + 1).']';
        }, $headers, array_keys($headers));
    }

    /** @param array<string, array{field: string|null, confidence: float, reason: string, requires_review: bool, question: string|null}> $suggestions
     * @param  list<string>  $originalHeaders
     * @param  list<string>  $providerHeaders
     * @return array<string, array{field: string|null, confidence: float, reason: string, requires_review: bool, question: string|null}>
     */
    private function restoreOriginalHeaders(array $suggestions, array $originalHeaders, array $providerHeaders): array
    {
        $originalHeadersByProviderHeader = array_combine($providerHeaders, $originalHeaders);
        $restoredSuggestions = [];
        foreach ($suggestions as $providerHeader => $suggestion) {
            $originalHeader = $originalHeadersByProviderHeader[$providerHeader] ?? null;
            if ($originalHeader !== null) {
                $restoredSuggestions[$originalHeader] = $suggestion;
            }
        }

        return $restoredSuggestions;
    }

    /** @param array<string, array{field: string|null, confidence: float, reason: string, requires_review: bool, question: string|null}> $suggestions
     * @return list<string>
     */
    private function headersRequiringEscalation(array $suggestions): array
    {
        $headers = [];
        $fieldHeaders = [];
        foreach ($suggestions as $header => $suggestion) {
            if ($suggestion['field'] === null || $suggestion['requires_review']) {
                $headers[$header] = true;
            }
            if ($suggestion['field'] !== null) {
                $fieldHeaders[$suggestion['field']][] = $header;
            }
        }

        foreach ($fieldHeaders as $headersForField) {
            if (count($headersForField) > 1) {
                foreach ($headersForField as $header) {
                    $headers[$header] = true;
                }
            }
        }

        return array_keys($headers);
    }
}
