<?php

namespace App\Services\Ai;

class AiModelPricing
{
    private const int COST_DECIMAL_PLACES = 6;

    public function hasValidRates(mixed $pricing): bool
    {
        if (! is_array($pricing)) {
            return false;
        }

        $rates = ['input', 'cached_input', 'cache_write_input', 'output'];
        foreach ($rates as $rate) {
            if (! is_numeric($pricing[$rate] ?? null) || (float) $pricing[$rate] < 0) {
                return false;
            }
        }

        return max(array_map(static fn (string $rate): float => (float) $pricing[$rate], $rates)) > 0;
    }

    public function hasValidLongContextPricing(mixed $pricing): bool
    {
        return is_array($pricing)
            && is_numeric($pricing['input_token_threshold'] ?? null)
            && (int) $pricing['input_token_threshold'] > 0
            && is_numeric($pricing['input_cache_rate_multiplier'] ?? null)
            && (float) $pricing['input_cache_rate_multiplier'] >= 1
            && is_numeric($pricing['output_rate_multiplier'] ?? null)
            && (float) $pricing['output_rate_multiplier'] >= 1;
    }

    /** @param array{input: int|float, cached_input: int|float, cache_write_input: int|float, output: int|float} $pricing
     * @param  array{input_token_threshold: int|float, input_cache_rate_multiplier: int|float, output_rate_multiplier: int|float}|null  $longContextPricing
     */
    public function reservationCostUsd(array $pricing, int $inputTokens, int $outputTokens, int $tokensPerMillion, ?array $longContextPricing = null): float
    {
        $maximumInputPrice = max(
            (float) $pricing['input'],
            (float) $pricing['cached_input'],
            (float) $pricing['cache_write_input'],
        );
        $outputPrice = (float) $pricing['output'];
        if ($this->usesLongContextPricing($longContextPricing, $inputTokens)) {
            $maximumInputPrice *= (float) $longContextPricing['input_cache_rate_multiplier'];
            $outputPrice *= (float) $longContextPricing['output_rate_multiplier'];
        }
        $estimatedCostUsd = (max(0, $inputTokens) * $maximumInputPrice)
            + (max(0, $outputTokens) * $outputPrice);

        return round($estimatedCostUsd / max(1, $tokensPerMillion), self::COST_DECIMAL_PLACES);
    }

    /** @param array{input: int|float, cached_input: int|float, cache_write_input: int|float, output: int|float} $pricing
     * @param  array{input_token_threshold: int|float, input_cache_rate_multiplier: int|float, output_rate_multiplier: int|float}|null  $longContextPricing
     */
    public function actualCostUsd(array $pricing, int $inputTokens, int $cachedInputTokens, int $cacheWriteInputTokens, int $outputTokens, int $tokensPerMillion, ?array $longContextPricing = null): float
    {
        $inputTokens = max(0, $inputTokens);
        $cachedInputTokens = max(0, $cachedInputTokens);
        $cacheWriteInputTokens = max(0, $cacheWriteInputTokens);
        $outputTokens = max(0, $outputTokens);
        $inputCacheRateMultiplier = 1.0;
        $outputRateMultiplier = 1.0;
        if ($this->usesLongContextPricing($longContextPricing, $inputTokens)) {
            $inputCacheRateMultiplier = (float) $longContextPricing['input_cache_rate_multiplier'];
            $outputRateMultiplier = (float) $longContextPricing['output_rate_multiplier'];
        }
        if ($cachedInputTokens + $cacheWriteInputTokens > $inputTokens) {
            $highestInputPrice = max(
                (float) $pricing['input'],
                (float) $pricing['cached_input'],
                (float) $pricing['cache_write_input'],
            ) * $inputCacheRateMultiplier;
            $estimatedCostUsd = ($inputTokens * $highestInputPrice) + ($outputTokens * (float) $pricing['output'] * $outputRateMultiplier);

            return round($estimatedCostUsd / max(1, $tokensPerMillion), self::COST_DECIMAL_PLACES);
        }

        $standardInputTokens = $inputTokens - $cachedInputTokens - $cacheWriteInputTokens;
        $estimatedCostUsd = ($standardInputTokens * (float) $pricing['input'] * $inputCacheRateMultiplier)
            + ($cachedInputTokens * (float) $pricing['cached_input'] * $inputCacheRateMultiplier)
            + ($cacheWriteInputTokens * (float) $pricing['cache_write_input'] * $inputCacheRateMultiplier)
            + ($outputTokens * (float) $pricing['output'] * $outputRateMultiplier);

        return round($estimatedCostUsd / max(1, $tokensPerMillion), self::COST_DECIMAL_PLACES);
    }

    /** @param array{input_token_threshold: int|float, input_cache_rate_multiplier: int|float, output_rate_multiplier: int|float}|null $longContextPricing */
    private function usesLongContextPricing(?array $longContextPricing, int $inputTokens): bool
    {
        return $longContextPricing !== null
            && $this->hasValidLongContextPricing($longContextPricing)
            && max(0, $inputTokens) > (int) $longContextPricing['input_token_threshold'];
    }
}
