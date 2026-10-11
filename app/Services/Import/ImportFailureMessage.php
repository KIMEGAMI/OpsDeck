<?php

namespace App\Services\Import;

use Illuminate\Validation\ValidationException;
use Throwable;

class ImportFailureMessage
{
    public static function safeMessage(?Throwable $exception): ?string
    {
        if (! $exception instanceof ValidationException) {
            return null;
        }

        foreach ($exception->errors()['file'] ?? [] as $message) {
            if (is_string($message) && str_contains($message, '取込上限')) {
                return $message;
            }
        }

        return null;
    }
}
