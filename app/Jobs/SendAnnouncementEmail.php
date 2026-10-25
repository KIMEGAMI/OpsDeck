<?php

namespace App\Jobs;

use App\Mail\AnnouncementMail;
use App\Mail\ResendIdempotencyConflictException;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\User;
use App\Services\AnnouncementRecipientQuery;
use App\Services\ResendIdempotencyWindow;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendAnnouncementEmail implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return config('mail.delivery_job_backoff_seconds');
    }

    /**
     * Create a new job instance.
     */
    public function __construct(public Announcement $announcement, public int $recipientUserId)
    {
        //
    }

    public function uniqueId(): string
    {
        return hash_hmac('sha256', $this->announcement->getKey().':'.$this->recipientUserId, (string) config('app.key'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $recipient = User::query()->find($this->recipientUserId);
        if ($recipient === null) {
            return;
        }
        $delivery = AnnouncementDelivery::query()->firstOrCreate(['announcement_id' => $this->announcement->id, 'user_id' => $recipient->id]);
        if ($delivery->sent_at !== null || $delivery->suppressed_at !== null) {
            return;
        }
        $organizationIds = $this->announcement->organizations()->pluck('organizations.id')->map(fn (int|string $id): int => (int) $id)->all();
        if (! app(AnnouncementRecipientQuery::class)->eligibleFor($organizationIds)->whereKey($recipient->id)->exists()) {
            $delivery->update(['suppressed_at' => now()]);

            return;
        }
        $useResend = (bool) config('mail.mailers.resend_idempotent.enabled');
        if ($useResend && ! app(ResendIdempotencyWindow::class)->startOrRequireManualReview($delivery)) {
            return;
        }

        try {
            $idempotencyKey = $useResend ? 'announcement-delivery/'.$delivery->getKey() : null;
            $mail = new AnnouncementMail($this->announcement, $idempotencyKey);
            if ($useResend) {
                Mail::mailer('resend_idempotent')->to($recipient->email)->send($mail);
            } else {
                Mail::to($recipient->email)->send($mail);
            }
            $delivery->update(['sent_at' => now(), 'failed_at' => null, 'manual_review_at' => null]);
        } catch (ResendIdempotencyConflictException) {
            $delivery->update(['failed_at' => now(), 'manual_review_at' => now()]);
        } catch (Throwable $exception) {
            $delivery->update(['failed_at' => now()]);

            throw new RuntimeException('Announcement delivery failed: '.$exception::class);
        }
    }
}
