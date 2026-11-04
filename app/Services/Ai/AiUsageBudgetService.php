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
    private const int RESERVATION_SETTLEMENT_BUFFER_MINUTES = 1;

    public function __construct(private AiModelPricing $modelPricing) {}

    /**
     * @return array{budget_jpy: int, cost_tracking_available: bool, spent_jpy: int|null, reserved_jpy: int|null, remaining_jpy: int|null, daily_request_limit: int, requests_used: int, requests_pending: int, requests_remaining: int}
     */
    public function usageSummary(Organization $organization): array
    {
        $plan = app(PlanAccessService::class)->effectivePlanCode($organization);
        $budgetJpy = (int) config("opsdeck.ai.monthly_budget_jpy.{$plan}", $plan === config('opsdeck.legacy_plan_code') ? config('opsdeck.ai.legacy_monthly_budget_jpy') : 0);
        $rate = config('opsdeck.ai.usd_to_jpy_rate');
        $monthStart = now($organization->timezone)->startOfMonth()->setTimezone(config('app.timezone'));
        $costTrackingAvailable = $budgetJpy > 0 && is_numeric($rate) && (float) $rate > 0;
        $spentJpy = null;
        $reservedJpy = null;
        if ($costTrackingAvailable) {
            $spentUsd = (float) AiUsageLog::query()
                ->where('organization_id', $organization->id)
                ->where('created_at', '>=', $monthStart)
                ->sum('estimated_cost_usd');
            $reservedUsd = (float) AiUsageReservation::query()
                ->where('organization_id', $organization->id)
                ->where('expires_at', '>', now())
                ->sum('reserved_cost_usd');
            $spentJpy = (int) round($spentUsd * (float) $rate);
            $reservedJpy = (int) round($reservedUsd * (float) $rate);
        }

        $requestLimit = (int) config('opsdeck.ai.daily_request_limit');
        $localNow = now($organization->timezone);
        $dayStart = $localNow->copy()->startOfDay()->setTimezone(config('app.timezone'));
        $nextDayStart = $localNow->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone'));
        $requestsUsed = AiUsageLog::query()
            ->where('organization_id', $organization->id)
            ->where('provider_requested_at', '>=', $dayStart)
            ->where('provider_requested_at', '<', $nextDayStart)
            ->where('provider_request_attempted', true)
            ->count() + AiUsageReservation::query()
            ->where('organization_id', $organization->id)
            ->where('provider_requested_at', '>=', $dayStart)
            ->where('provider_requested_at', '<', $nextDayStart)
            ->where('expires_at', '<=', now())
            ->count();
        $requestsPending = AiUsageReservation::query()
            ->where('organization_id', $organization->id)
            ->where('expires_at', '>', now())
            ->count();

        return [
            'budget_jpy' => $budgetJpy,
            'cost_tracking_available' => $costTrackingAvailable,
            'spent_jpy' => $spentJpy,
            'reserved_jpy' => $reservedJpy,
            'remaining_jpy' => $costTrackingAvailable ? max(0, $budgetJpy - $spentJpy - $reservedJpy) : null,
            'daily_request_limit' => $requestLimit,
            'requests_used' => $requestsUsed,
            'requests_pending' => $requestsPending,
            'requests_remaining' => max(0, $requestLimit - $requestsUsed - $requestsPending),
        ];
    }

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
        $longContextPricing = $this->longContextPricingForModel($model);
        if (! app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping')
            || $budgetJpy <= 0
            || ! is_numeric($rate)
            || (float) $rate <= 0
            || ! $this->modelPricing->hasValidRates($pricing)
            || ($longContextPricing !== null && ! $this->modelPricing->hasValidLongContextPricing($longContextPricing))) {
            return null;
        }

        $encodedInput = json_encode(['headers' => $headers, 'target_type' => $targetType, 'target_fields' => $targetFields], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $inputTokens = (strlen($encodedInput) * (int) config('opsdeck.ai.input_token_estimate_multiplier'))
            + (int) config('opsdeck.ai.estimated_input_overhead_tokens');
        $outputTokens = (int) config('opsdeck.ai.max_output_tokens');
        $maximumCostUsd = $this->modelPricing->reservationCostUsd(
            $pricing,
            $inputTokens,
            $outputTokens,
            (int) config('opsdeck.ai.tokens_per_million'),
            is_array($longContextPricing) ? $longContextPricing : null,
        );
        $monthlyBudgetUsd = $budgetJpy / (float) $rate;
        $configuredReservationTtlMinutes = (int) config('opsdeck.ai.monthly_budget_reservation_ttl_minutes');
        $providerTimeoutMinutes = (int) ceil((int) config('opsdeck.ai.timeout_seconds') / 60)
            + self::RESERVATION_SETTLEMENT_BUFFER_MINUTES;
        $reservationTtlMinutes = max($configuredReservationTtlMinutes, $providerTimeoutMinutes);
        $requestLimit = (int) config('opsdeck.ai.daily_request_limit');
        $localNow = now($organization->timezone);
        $dayStart = $localNow->copy()->startOfDay()->setTimezone(config('app.timezone'));
        $nextDayStart = $localNow->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone'));
        $monthStart = $localNow->copy()->startOfMonth()->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($organization, $userId, $model, $maximumCostUsd, $monthlyBudgetUsd, $reservationTtlMinutes, $requestLimit, $dayStart, $nextDayStart, $monthStart): ?AiUsageReservation {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            AiUsageReservation::query()
                ->where('organization_id', $organization->id)
                ->where('expires_at', '<=', now())
                ->where(function ($query) use ($dayStart): void {
                    $query->whereNull('provider_requested_at')
                        ->orWhere('provider_requested_at', '<', $dayStart);
                })
                ->delete();

            $dailyRequests = AiUsageLog::query()
                ->where('organization_id', $organization->id)
                ->where('provider_requested_at', '>=', $dayStart)
                ->where('provider_requested_at', '<', $nextDayStart)
                ->where('provider_request_attempted', true)
                ->count();
            $expiredAttemptedRequests = AiUsageReservation::query()
                ->where('organization_id', $organization->id)
                ->where('provider_requested_at', '>=', $dayStart)
                ->where('provider_requested_at', '<', $nextDayStart)
                ->where('expires_at', '<=', now())
                ->count();
            $pendingRequests = AiUsageReservation::query()
                ->where('organization_id', $organization->id)
                ->where('expires_at', '>', now())
                ->count();
            if ($dailyRequests + $expiredAttemptedRequests + $pendingRequests >= $requestLimit) {
                return null;
            }

            $spentUsd = (float) AiUsageLog::query()->where('organization_id', $organization->id)->where('created_at', '>=', $monthStart)->sum('estimated_cost_usd');
            $reservedUsd = (float) AiUsageReservation::query()->where('organization_id', $organization->id)->where('expires_at', '>', now())->sum('reserved_cost_usd');
            if ($spentUsd + $reservedUsd + $maximumCostUsd > $monthlyBudgetUsd) {
                return null;
            }

            return AiUsageReservation::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $userId,
                'model' => $model,
                'purpose' => 'import_column_mapping',
                'reserved_cost_usd' => $maximumCostUsd,
                'expires_at' => now()->addMinutes($reservationTtlMinutes),
            ]);
        });
    }

    public function markProviderRequestAttempted(AiUsageReservation $reservation): bool
    {
        return DB::transaction(function () use ($reservation): bool {
            $lockedReservation = AiUsageReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if ($lockedReservation === null) {
                return false;
            }

            if ($lockedReservation->provider_requested_at === null) {
                $lockedReservation->forceFill(['provider_requested_at' => now()])->save();
            }

            return true;
        });
    }

    public function settle(AiUsageReservation $reservation, int $inputTokens, int $outputTokens, bool $success, ?string $errorCode = null, int $cachedInputTokens = 0, int $cacheWriteInputTokens = 0, bool $usageOutcomeUnknown = false): void
    {
        $inputTokens = max(0, $inputTokens);
        $outputTokens = max(0, $outputTokens);

        DB::transaction(function () use ($reservation, $inputTokens, $outputTokens, $success, $errorCode, $cachedInputTokens, $cacheWriteInputTokens, $usageOutcomeUnknown): void {
            $lockedReservation = AiUsageReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if ($lockedReservation === null) {
                return;
            }

            $modelPrices = config('opsdeck.ai.model_prices_per_million_tokens', []);
            $pricing = is_array($modelPrices) ? ($modelPrices[$lockedReservation->model] ?? null) : null;
            $longContextPricing = $this->longContextPricingForModel($lockedReservation->model);
            $hasPricing = $this->modelPricing->hasValidRates($pricing)
                && ($longContextPricing === null || $this->modelPricing->hasValidLongContextPricing($longContextPricing));
            $costUsd = $hasPricing
                ? $this->modelPricing->actualCostUsd(
                    $pricing,
                    $inputTokens,
                    $cachedInputTokens,
                    $cacheWriteInputTokens,
                    $outputTokens,
                    (int) config('opsdeck.ai.tokens_per_million'),
                    is_array($longContextPricing) ? $longContextPricing : null,
                )
                : (float) $lockedReservation->reserved_cost_usd;
            if (! $success && $usageOutcomeUnknown && $inputTokens === 0 && $outputTokens === 0) {
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
                'provider_request_attempted' => true,
                'provider_requested_at' => $lockedReservation->provider_requested_at ?? now(),
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
            'provider_request_attempted' => false,
            'provider_requested_at' => null,
            'error_code' => $errorCode,
        ]);
    }

    private function longContextPricingForModel(string $model): mixed
    {
        $pricing = config('opsdeck.ai.long_context_pricing', []);

        if (! is_array($pricing)) {
            return false;
        }

        return $pricing[$model] ?? null;
    }
}
