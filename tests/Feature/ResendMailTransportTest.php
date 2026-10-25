<?php

namespace Tests\Feature;

use App\Mail\AnnouncementMail;
use App\Mail\ResendIdempotencyConflictException;
use App\Mail\ResendIdempotentTransport;
use App\Models\Announcement;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class ResendMailTransportTest extends TestCase
{
    public function test_resend_api_uses_existing_mail_password_for_credentials(): void
    {
        $smtpPassword = config('mail.mailers.smtp.password');

        $this->assertSame($smtpPassword, config('mail.mailers.resend_idempotent.key'));
        $this->assertSame($smtpPassword, config('services.resend.key'));
    }

    public function test_resend_api_receives_the_same_key_and_payload_for_retries(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'resend-message-id'])]);
        config([
            'mail.mailers.resend_idempotent.key' => Str::random(48),
            'mail.mailers.resend_idempotent.timeout' => 5,
        ]);
        $announcement = new Announcement(['title' => 'メンテナンス', 'body' => '作業予定をお知らせします。']);
        $idempotencyKey = 'announcement-delivery/123';

        foreach (range(1, 2) as $_attempt) {
            Mail::mailer('resend_idempotent')->to('recipient@example.test')->send(new AnnouncementMail($announcement, $idempotencyKey));
        }

        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.resend.com/emails'
            && $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key', $idempotencyKey)
            && $request->hasHeader('Authorization')
            && $request->data()['from'] === config('opsdeck.admin_mail_from_address')
            && $request->data()['to'] === ['recipient@example.test']
            && $request->data()['subject'] === 'メンテナンス'
            && str_contains($request->data()['html'], '作業予定をお知らせします。')
            && ! array_key_exists(ResendIdempotentTransport::IDEMPOTENCY_HEADER, $request->data()['headers']));
    }

    public function test_default_mailer_uses_resend_api_when_idempotent_delivery_is_enabled(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'resend-message-id'])]);
        config([
            'mail.default' => 'resend_idempotent',
            'mail.mailers.resend_idempotent.enabled' => true,
            'mail.mailers.resend_idempotent.key' => Str::random(),
            'mail.mailers.resend_idempotent.timeout' => 5,
        ]);
        $idempotencyKey = 'email-verification/user-123';

        Mail::to('recipient@example.test')->send(new AnnouncementMail(
            new Announcement(['title' => '確認', 'body' => '既定経路の確認です。']),
            $idempotencyKey,
        ));

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.resend.com/emails'
            && $request->hasHeader('Idempotency-Key', $idempotencyKey));
    }

    public function test_resend_transport_rejects_messages_without_a_stable_idempotency_key(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'unused'])]);
        config(['mail.mailers.resend_idempotent.key' => Str::random(48)]);
        $exception = null;

        try {
            Mail::mailer('resend_idempotent')->to('recipient@example.test')
                ->send(new AnnouncementMail(new Announcement(['title' => '確認', 'body' => '本文'])));
        } catch (TransportException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(TransportException::class, $exception);
        $this->assertSame('Resend email requires a valid idempotency key.', $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_resend_transport_base64_encodes_calendar_attachment_content(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'resend-message-id'])]);
        config(['mail.mailers.resend_idempotent.key' => Str::random(48)]);
        $calendarContent = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
        $mail = (new AnnouncementMail(
            new Announcement(['title' => '予定', 'body' => '予定を共有します。']),
            'announcement-delivery/calendar',
        ))->attachData($calendarContent, 'event.ics', ['mime' => 'text/calendar']);

        Mail::mailer('resend_idempotent')->to('recipient@example.test')->send($mail);

        Http::assertSent(static fn (Request $request): bool => $request->data()['attachments'] === [[
            'content_type' => 'text/calendar',
            'content' => base64_encode($calendarContent),
            'filename' => 'event.ics',
        ]]);
    }

    public function test_resend_transport_base64_encodes_binary_attachment_content(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'resend-message-id'])]);
        config(['mail.mailers.resend_idempotent.key' => Str::random(48)]);
        $binaryContent = "%PDF-1.7\0\xFF\r\nOpsDeck report";
        $mail = (new AnnouncementMail(
            new Announcement(['title' => 'レポート', 'body' => '添付を確認してください。']),
            'announcement-delivery/report',
        ))->attachData($binaryContent, 'report.pdf', ['mime' => 'application/pdf']);

        Mail::mailer('resend_idempotent')->to('recipient@example.test')->send($mail);

        Http::assertSent(static fn (Request $request): bool => $request->data()['attachments'] === [[
            'content_type' => 'application/pdf',
            'content' => base64_encode($binaryContent),
            'filename' => 'report.pdf',
        ]]);
    }

    public function test_resend_transport_does_not_expose_provider_error_body_or_recipient(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response(['message' => 'recipient@example.test secret details'], 503)]);
        config(['mail.mailers.resend_idempotent.key' => Str::random(48)]);
        $exception = null;

        try {
            Mail::mailer('resend_idempotent')->to('recipient@example.test')->send(
                new AnnouncementMail(new Announcement(['title' => '確認', 'body' => '本文']), 'announcement-delivery/456'),
            );
        } catch (TransportException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(TransportException::class, $exception);
        $this->assertSame('Resend API rejected the email request with status 503.', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        $this->assertStringNotContainsString('recipient@example.test', $exception->getMessage());
    }

    public function test_resend_payload_conflict_has_a_distinct_safe_exception(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.resend.com/emails' => Http::response([
            'name' => 'invalid_idempotent_request',
            'message' => 'Request mismatch for recipient@example.test',
        ], 409)]);
        config(['mail.mailers.resend_idempotent.key' => Str::random(48)]);
        $exception = null;

        try {
            Mail::mailer('resend_idempotent')->to('recipient@example.test')->send(
                new AnnouncementMail(new Announcement(['title' => '確認', 'body' => '本文']), 'announcement-delivery/789'),
            );
        } catch (ResendIdempotencyConflictException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(ResendIdempotencyConflictException::class, $exception);
        $this->assertSame('Resend rejected a changed payload for an existing idempotency key.', $exception->getMessage());
        $this->assertStringNotContainsString('recipient@example.test', $exception->getMessage());
        Http::assertSentCount(1);
    }
}
