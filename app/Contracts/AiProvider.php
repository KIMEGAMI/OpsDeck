<?php

namespace App\Contracts;

interface AiProvider
{
    /** @return array{suggestions: array<string, array{field: string|null, confidence: float, reason: string}>, input_tokens: int, output_tokens: int} */
    public function suggestColumnMapping(array $headers, string $targetType, array $targetFields): array;
}
