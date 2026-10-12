<?php

namespace App\Mail;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class OrganizationInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public OrganizationInvitation $invitation, public string $token, public ?string $idempotencyKey = null)
    {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: config('opsdeck.admin_mail_from_address'),
            subject: $this->invitation->organization->name.'への招待',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.organization-invitation',
            with: ['invitation' => $this->invitation, 'url' => route('invitations.show', $this->token)],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->idempotencyKey === null
            ? []
            : [ResendIdempotentTransport::IDEMPOTENCY_HEADER => $this->idempotencyKey]);
    }
}
