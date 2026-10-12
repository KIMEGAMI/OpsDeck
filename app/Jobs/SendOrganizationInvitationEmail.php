<?php

namespace App\Jobs;

use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use App\Services\ResendIdempotencyKeyFactory;
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
        return (string) $this->invitation->getKey();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $invitation = OrganizationInvitation::query()->with('organization')->find($this->invitation->getKey());
        if ($invitation === null || ! $invitation->isUsable()) {
            return;
        }

        try {
            $idempotencyKey = app(ResendIdempotencyKeyFactory::class)
                ->forOrganizationInvitation($invitation->getKey(), $this->token);
            Mail::to($invitation->email)->send(new OrganizationInvitationMail($invitation, $this->token, $idempotencyKey));
        } catch (Throwable $exception) {
            throw new RuntimeException('Organization invitation email delivery failed: '.$exception::class);
        }
    }
}
