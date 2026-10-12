<?php

namespace App\Services;

class ResendIdempotencyKeyFactory
{
    public function forOrganizationInvitation(int|string $invitationId, string $token): string
    {
        return $this->make('organization-invitation', (string) $invitationId, hash('sha256', $token));
    }

    public function forPasswordReset(int|string $userId, string $token): string
    {
        return $this->make('password-reset', (string) $userId, hash('sha256', $token));
    }

    public function forServerErrorAlert(string $errorReference, int $statusCode, string $recipient): string
    {
        return $this->make('server-error-alert', $errorReference, (string) $statusCode, hash('sha256', strtolower(trim($recipient))));
    }

    private function make(string $purpose, string ...$parts): string
    {
        $applicationKey = config('app.key');
        if (! is_string($applicationKey) || trim($applicationKey) === '') {
            throw new \RuntimeException('Application key is required to create an email idempotency key.');
        }

        return $purpose.'/'.hash_hmac('sha256', implode("\0", $parts), $applicationKey);
    }
}
