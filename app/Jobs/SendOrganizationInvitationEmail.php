<?php

namespace App\Jobs;

use App\Mail\OrganizationInvitationMail;
use App\Mail\ResendIdempotencyConflictException;
use App\Models\OrganizationInvitation;
use App\Services\ResendIdempotencyKeyFactory;
use App\Services\ResendIdempotencyWindow;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendOrganizationInvitationEmail implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public OrganizationInvitation $invitation, public string $token) {}

    public function uniqueId(): string
    {
        return hash_hmac('sha256', (string) $this->invitation->getKey(), (string) config('app.key'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $invitation = OrganizationInvitation::query()->with('organization')->find($this->invitation->getKey());
        if ($invitation === null
            || ! $invitation->isUsable()
            || $invitation->invitation_email_sent_at !== null
            || $invitation->invitation_email_manual_review_at !== null) {
            return;
        }

        $useResendIdempotency = (bool) config('mail.mailers.resend_idempotent.enabled');
        if ($useResendIdempotency
            && ! app(ResendIdempotencyWindow::class)->startOrRequireManualReview($invitation)) {
            return;
        }

        try {
            $idempotencyKey = app(ResendIdempotencyKeyFactory::class)
                ->forOrganizationInvitation($invitation->getKey(), $this->token);
            $mail = new OrganizationInvitationMail($invitation, $this->token, $idempotencyKey);
            if ($useResendIdempotency) {
                Mail::mailer('resend_idempotent')->to($invitation->email)->send($mail);
            } else {
                Mail::to($invitation->email)->send($mail);
            }
            $invitation->update([
                'invitation_email_sent_at' => now(),
                'invitation_email_failed_at' => null,
                'invitation_email_manual_review_at' => null,
            ]);
        } catch (ResendIdempotencyConflictException) {
            $invitation->update([
                'invitation_email_failed_at' => now(),
                'invitation_email_manual_review_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $invitation->update(['invitation_email_failed_at' => now()]);
            throw new RuntimeException('Organization invitation email delivery failed: '.$exception::class);
        }
    }
}
