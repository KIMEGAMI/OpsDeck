<?php

namespace App\Contracts;

interface AiProvider
{
    /** @return array{suggestions: array<string, array{field: string|null, confidence: float, reason: string, requires_review: bool, question: string|null}>, input_tokens: int, output_tokens: int} */
    public function suggestColumnMapping(array $headers, string $targetType, array $targetFields, ?string $model = null, ?string $reasoningEffort = null): array;
}
