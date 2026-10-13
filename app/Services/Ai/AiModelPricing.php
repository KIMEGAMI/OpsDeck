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

    /** @param array{input: int|float, cached_input: int|float, cache_write_input: int|float, output: int|float} $pricing */
    public function reservationCostUsd(array $pricing, int $inputTokens, int $outputTokens, int $tokensPerMillion): float
    {
        $maximumInputPrice = max(
            (float) $pricing['input'],
            (float) $pricing['cached_input'],
            (float) $pricing['cache_write_input'],
        );
        $estimatedCostUsd = (max(0, $inputTokens) * $maximumInputPrice)
            + (max(0, $outputTokens) * (float) $pricing['output']);

        return round($estimatedCostUsd / max(1, $tokensPerMillion), self::COST_DECIMAL_PLACES);
    }

    /** @param array{input: int|float, cached_input: int|float, cache_write_input: int|float, output: int|float} $pricing */
    public function actualCostUsd(array $pricing, int $inputTokens, int $cachedInputTokens, int $cacheWriteInputTokens, int $outputTokens, int $tokensPerMillion): float
    {
        $inputTokens = max(0, $inputTokens);
        $cachedInputTokens = max(0, $cachedInputTokens);
        $cacheWriteInputTokens = max(0, $cacheWriteInputTokens);
        $outputTokens = max(0, $outputTokens);
        if ($cachedInputTokens + $cacheWriteInputTokens > $inputTokens) {
            $highestInputPrice = max(
                (float) $pricing['input'],
                (float) $pricing['cached_input'],
                (float) $pricing['cache_write_input'],
            );
            $estimatedCostUsd = ($inputTokens * $highestInputPrice) + ($outputTokens * (float) $pricing['output']);

            return round($estimatedCostUsd / max(1, $tokensPerMillion), self::COST_DECIMAL_PLACES);
        }

        $standardInputTokens = $inputTokens - $cachedInputTokens - $cacheWriteInputTokens;
        $estimatedCostUsd = ($standardInputTokens * (float) $pricing['input'])
            + ($cachedInputTokens * (float) $pricing['cached_input'])
            + ($cacheWriteInputTokens * (float) $pricing['cache_write_input'])
            + ($outputTokens * (float) $pricing['output']);

        return round($estimatedCostUsd / max(1, $tokensPerMillion), self::COST_DECIMAL_PLACES);
    }
}
