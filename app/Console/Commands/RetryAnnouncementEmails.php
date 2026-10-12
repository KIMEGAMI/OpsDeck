<?php

namespace App\Console\Commands;

use App\Jobs\SendAnnouncementEmail;
use App\Models\AnnouncementDelivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('opsdeck:retry-announcement-emails {--limit=100 : Maximum number of failed deliveries to retry}')]
#[Description('Queue failed announcement email deliveries again')]
class RetryAnnouncementEmails extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $queued = 0;
        AnnouncementDelivery::query()->whereNotNull('failed_at')->whereNull('sent_at')->whereNull('suppressed_at')->whereNull('manual_review_at')->with('announcement')->latest('failed_at')->limit($limit)->get()->each(function (AnnouncementDelivery $delivery) use (&$queued): void {
            SendAnnouncementEmail::dispatch($delivery->announcement, (int) $delivery->user_id);
            $queued++;
        });
        $this->info('Queued announcement email retries: '.$queued);

        return self::SUCCESS;
    }
}
