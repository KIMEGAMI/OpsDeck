<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiProvider implements AiProvider
{
    public function suggestColumnMapping(array $headers, string $targetType, array $targetFields): array
    {
        $response = Http::withToken(config('opsdeck.ai.api_key'))->timeout(config('opsdeck.ai.timeout_seconds'))->post(config('opsdeck.ai.endpoint'), [
            'model' => config('opsdeck.ai.models.fast'),
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => 'Return only JSON. Suggest mappings, never invent fields. Confidence must be between 0 and 1. For every header, include a concise Japanese reason based only on the header and target field; do not invent evidence or include sample data. Use an empty field and explain that no allowed field matches when there is no safe mapping.'],
                ['role' => 'user', 'content' => json_encode(['headers' => array_values($headers), 'target_type' => $targetType, 'target_fields' => array_values($targetFields)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);
        if ($response->failed()) {
            throw new RuntimeException('AI provider request failed.');
        }
        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($decoded) || ! is_array($decoded['suggestions'] ?? null)) {
            throw new RuntimeException('AI provider returned invalid JSON.');
        }
        $suggestions = [];
        foreach ($headers as $header) {
            $candidate = $decoded['suggestions'][$header] ?? [];
            $field = is_string($candidate['field'] ?? null) && in_array($candidate['field'], $targetFields, true) ? $candidate['field'] : null;
            $confidence = is_numeric($candidate['confidence'] ?? null) ? max(0.0, min(1.0, (float) $candidate['confidence'])) : 0.0;
            $suggestions[$header] = ['field' => $field, 'confidence' => $confidence, 'reason' => $this->reason($candidate['reason'] ?? null, $field)];
        }

        return ['suggestions' => $suggestions, 'input_tokens' => (int) $response->json('usage.prompt_tokens', 0), 'output_tokens' => (int) $response->json('usage.completion_tokens', 0)];
    }

    private function reason(mixed $reason, ?string $field): string
    {
        if (! is_string($reason) || trim($reason) === '') {
            return $field === null
                ? '許可された項目との対応を特定できませんでした。'
                : '列名と対象項目の対応を確認してください。';
        }

        $reason = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($reason)) ?? '';

        return mb_substr($reason, 0, (int) config('opsdeck.ai.mapping_reason_max_length'));
    }
}
