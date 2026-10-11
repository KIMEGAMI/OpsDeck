<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;

class NullAiProvider implements AiProvider
{
    public function suggestColumnMapping(array $headers, string $targetType, array $targetFields): array
    {
        return ['suggestions' => array_fill_keys($headers, ['field' => null, 'confidence' => 0.0, 'reason' => '対応する候補を取得できませんでした。']), 'input_tokens' => 0, 'output_tokens' => 0];
    }
}
