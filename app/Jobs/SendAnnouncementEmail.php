<?php

namespace App\Jobs;

use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\User;
use App\Services\AnnouncementRecipientQuery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendAnnouncementEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public Announcement $announcement, public int $recipientUserId)
    {
        //
    }

    public function uniqueId(): string
    {
        return $this->announcement->getKey().':'.$this->recipientUserId;
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
        $organizationIds = $this->announcement->organizations()->pluck('organizations.id')->map(fn (int|string $id): int => (int) $id)->all();
        if (! app(AnnouncementRecipientQuery::class)->eligibleFor($organizationIds)->whereKey($recipient->id)->exists()) {
            AnnouncementDelivery::query()
                ->where('announcement_id', $this->announcement->id)
                ->where('user_id', $recipient->id)
                ->whereNotNull('failed_at')
                ->whereNull('sent_at')
                ->whereNull('suppressed_at')
                ->update(['suppressed_at' => now()]);

            return;
        }
        $delivery = AnnouncementDelivery::query()->firstOrCreate(['announcement_id' => $this->announcement->id, 'user_id' => $recipient->id]);
        if ($delivery->sent_at !== null || $delivery->suppressed_at !== null) {
            return;
        }
        try {
            Mail::to($recipient->email)->send(new AnnouncementMail($this->announcement));
            $delivery->update(['sent_at' => now(), 'failed_at' => null]);
        } catch (Throwable $exception) {
            $delivery->update(['failed_at' => now()]);

            throw new RuntimeException('Announcement delivery failed: '.$exception::class);
        }
    }
}
