<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

final class ProductionLogMailTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        throw new TransportException('Mail delivery is disabled for the production log transport.');
    }

    public function __toString(): string
    {
        return 'production-log-disabled';
    }
}
