<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class ResendIdempotentTransport extends AbstractTransport
{
    public const IDEMPOTENCY_HEADER = 'X-OpsDeck-Idempotency-Key';

    public function __construct(private readonly string $apiKey, private readonly int $timeoutSeconds)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $idempotencyKey = $email->getHeaders()->getHeaderBody(self::IDEMPOTENCY_HEADER);

        if (! is_string($idempotencyKey) || $idempotencyKey === '' || mb_strlen($idempotencyKey) > 256) {
            throw new TransportException('Resend email requires a valid idempotency key.');
        }

        if ($this->apiKey === '') {
            throw new TransportException('Resend API credentials are not configured.');
        }

        $envelope = $message->getEnvelope();
        $payload = [
            'from' => $envelope->getSender()->toString(),
            'to' => $this->stringifyAddresses($this->getRecipients($email, $envelope)),
            'subject' => $email->getSubject(),
            'headers' => $this->headers($email),
            'attachments' => $this->attachments($email),
        ];

        foreach ([
            'cc' => $email->getCc(),
            'bcc' => $email->getBcc(),
            'reply_to' => $email->getReplyTo(),
        ] as $field => $addresses) {
            if ($addresses !== []) {
                $payload[$field] = $this->stringifyAddresses($addresses);
            }
        }

        foreach (['html' => $email->getHtmlBody(), 'text' => $email->getTextBody()] as $field => $body) {
            $content = $this->bodyToString($body);
            if ($content !== null) {
                $payload[$field] = $content;
            }
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->connectTimeout($this->timeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('https://api.resend.com/emails', $payload);
        } catch (\Throwable) {
            throw new TransportException('Resend API request failed.');
        }

        if (! $response->successful()) {
            if ($response->status() === 409 && $response->json('name') === 'invalid_idempotent_request') {
                throw new ResendIdempotencyConflictException('Resend rejected a changed payload for an existing idempotency key.');
            }

            throw new TransportException('Resend API rejected the email request with status '.$response->status().'.');
        }

        $messageId = $response->json('id');
        if (! is_string($messageId) || $messageId === '') {
            throw new TransportException('Resend API response did not include an email identifier.');
        }

        $message->setMessageId($messageId);
    }

    public function __toString(): string
    {
        return 'resend-idempotent';
    }

    /** @return array<string, string> */
    private function headers(Email $email): array
    {
        $reservedHeaders = [
            'bcc', 'cc', 'content-type', 'date', 'from', 'message-id', 'mime-version',
            'reply-to', 'sender', 'subject', 'to', strtolower(self::IDEMPOTENCY_HEADER),
        ];
        $headers = [];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (in_array(strtolower($name), $reservedHeaders, true)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }

    /** @return list<array{content_type:string,content:string,filename:string,content_id?:string}> */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $contentType = $headers->get('Content-Type')->getBody();
            $disposition = $headers->getHeaderBody('Content-Disposition');
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            if (! is_string($filename)) {
                throw new TransportException('Resend attachment could not be prepared.');
            }

            $item = [
                'content_type' => $contentType,
                'content' => base64_encode($attachment->getBody()),
                'filename' => $filename,
            ];

            if ($disposition === 'inline') {
                $item['content_id'] = $attachment->hasContentId() ? $attachment->getContentId() : $filename;
            }

            $attachments[] = $item;
        }

        return $attachments;
    }

    /** @param resource|string|null $body */
    private function bodyToString(mixed $body): ?string
    {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        return is_string($body) ? $body : null;
    }

    /** @return list<Address> */
    private function getRecipients(Email $email, Envelope $envelope): array
    {
        return array_values(array_filter(
            $envelope->getRecipients(),
            static fn (Address $address): bool => ! in_array($address, array_merge($email->getCc(), $email->getBcc()), true),
        ));
    }
}
