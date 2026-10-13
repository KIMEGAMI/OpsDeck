<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\AiUsageReservation;
use App\Models\Organization;
use App\Services\PlanAccessService;
use App\Services\PlanFeatureService;
use Illuminate\Support\Facades\DB;

class AiUsageBudgetService
{
    public function __construct(private AiModelPricing $modelPricing) {}

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $targetFields
     */
    public function reserve(Organization $organization, ?int $userId, string $model, array $headers, string $targetType, array $targetFields): ?AiUsageReservation
    {
        $plan = app(PlanAccessService::class)->effectivePlanCode($organization);
        $budgetJpy = (int) config("opsdeck.ai.monthly_budget_jpy.{$plan}", $plan === config('opsdeck.legacy_plan_code') ? config('opsdeck.ai.legacy_monthly_budget_jpy') : 0);
        $rate = config('opsdeck.ai.usd_to_jpy_rate');
        $modelPrices = config('opsdeck.ai.model_prices_per_million_tokens', []);
        $pricing = is_array($modelPrices) ? ($modelPrices[$model] ?? null) : null;
        if (! app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping')
            || $budgetJpy <= 0
            || ! is_numeric($rate)
            || (float) $rate <= 0
            || ! $this->modelPricing->hasValidRates($pricing)) {
            return null;
        }

        $encodedInput = json_encode(['headers' => $headers, 'target_type' => $targetType, 'target_fields' => $targetFields], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $inputTokens = (strlen($encodedInput) * (int) config('opsdeck.ai.input_token_estimate_multiplier'))
            + (int) config('opsdeck.ai.estimated_input_overhead_tokens');
        $outputTokens = (int) config('opsdeck.ai.max_output_tokens');
        $maximumCostUsd = $this->modelPricing->reservationCostUsd($pricing, $inputTokens, $outputTokens, (int) config('opsdeck.ai.tokens_per_million'));
        $monthlyBudgetUsd = $budgetJpy / (float) $rate;

        return DB::transaction(function () use ($organization, $userId, $model, $maximumCostUsd, $monthlyBudgetUsd): ?AiUsageReservation {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            AiUsageReservation::query()->where('organization_id', $organization->id)->where('expires_at', '<=', now())->delete();

            $monthStart = now($organization->timezone)->startOfMonth()->setTimezone(config('app.timezone'));
            $spentUsd = (float) AiUsageLog::query()->where('organization_id', $organization->id)->where('created_at', '>=', $monthStart)->sum('estimated_cost_usd');
            $reservedUsd = (float) AiUsageReservation::query()->where('organization_id', $organization->id)->sum('reserved_cost_usd');
            if ($spentUsd + $reservedUsd + $maximumCostUsd > $monthlyBudgetUsd) {
                return null;
            }

            return AiUsageReservation::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $userId,
                'model' => $model,
                'purpose' => 'import_column_mapping',
                'reserved_cost_usd' => $maximumCostUsd,
                'expires_at' => now()->addMinutes((int) config('opsdeck.ai.monthly_budget_reservation_ttl_minutes')),
            ]);
        });
    }

    public function settle(AiUsageReservation $reservation, int $inputTokens, int $outputTokens, bool $success, ?string $errorCode = null, int $cachedInputTokens = 0, int $cacheWriteInputTokens = 0): void
    {
        $inputTokens = max(0, $inputTokens);
        $outputTokens = max(0, $outputTokens);

        DB::transaction(function () use ($reservation, $inputTokens, $outputTokens, $success, $errorCode, $cachedInputTokens, $cacheWriteInputTokens): void {
            $lockedReservation = AiUsageReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if ($lockedReservation === null) {
                return;
            }

            $modelPrices = config('opsdeck.ai.model_prices_per_million_tokens', []);
            $pricing = is_array($modelPrices) ? ($modelPrices[$lockedReservation->model] ?? null) : null;
            $hasPricing = $this->modelPricing->hasValidRates($pricing);
            $costUsd = $hasPricing
                ? $this->modelPricing->actualCostUsd($pricing, $inputTokens, $cachedInputTokens, $cacheWriteInputTokens, $outputTokens, (int) config('opsdeck.ai.tokens_per_million'))
                : (float) $lockedReservation->reserved_cost_usd;
            if (! $success && $inputTokens === 0 && $outputTokens === 0) {
                $costUsd = (float) $lockedReservation->reserved_cost_usd;
            }
            $rate = config('opsdeck.ai.usd_to_jpy_rate');

            AiUsageLog::query()->create([
                'organization_id' => $lockedReservation->organization_id,
                'user_id' => $lockedReservation->user_id,
                'provider' => config('opsdeck.ai.provider'),
                'model' => $lockedReservation->model,
                'purpose' => $lockedReservation->purpose,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'estimated_cost_usd' => $costUsd,
                'estimated_cost_jpy' => is_numeric($rate) && (float) $rate > 0 ? round($costUsd * (float) $rate, 2) : 0,
                'success' => $success,
                'error_code' => ! $success ? ($errorCode ?? 'provider_unavailable') : ($hasPricing ? null : 'pricing_unavailable'),
            ]);
            $lockedReservation->delete();
        });
    }

    public function recordBlocked(Organization $organization, ?int $userId, string $model, string $errorCode): void
    {
        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $userId,
            'provider' => config('opsdeck.ai.provider'),
            'model' => $model,
            'purpose' => 'import_column_mapping',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => 0,
            'estimated_cost_jpy' => 0,
            'success' => false,
            'error_code' => $errorCode,
        ]);
    }
}
