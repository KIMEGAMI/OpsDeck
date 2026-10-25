<?php

namespace App\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

final class PrivacySafeLogMailTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        // Local log mail must not persist recipients, reset links, or message bodies.
    }

    public function __toString(): string
    {
        return 'privacy-safe-local-log';
    }
}
