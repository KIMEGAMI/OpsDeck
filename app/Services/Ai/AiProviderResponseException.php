<?php

namespace App\Services\Ai;

use RuntimeException;

final class AiProviderResponseException extends RuntimeException
{
    public const RESPONSE_INCOMPLETE = 'provider_response_incomplete';

    public const INVALID_JSON = 'provider_response_invalid_json';

    public const INVALID_USAGE = 'provider_response_invalid_usage';

    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('AI provider response could not be processed.');
    }
}
