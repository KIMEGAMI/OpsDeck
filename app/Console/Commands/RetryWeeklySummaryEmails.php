<?php

namespace App\Console\Commands;

use App\Jobs\SendWeeklySummaryEmail;
use App\Models\WeeklySummaryDelivery;
use App\Services\WeeklySummaryBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('opsdeck:retry-weekly-summary-emails {--limit=100 : Maximum number of failed deliveries to retry}')]
#[Description('Queue failed weekly summary email deliveries again')]
class RetryWeeklySummaryEmails extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $queued = 0;
        $summaryBuilder = app(WeeklySummaryBuilder::class);
        WeeklySummaryDelivery::query()->whereNotNull('failed_at')->whereNull('sent_at')->whereHas('user.organizationMemberships', function (Builder $query): void {
            $query->whereColumn('organization_memberships.organization_id', 'weekly_summary_deliveries.organization_id')->where('status', 'active');
        })->whereDoesntHave('user.notificationPreferences', function (Builder $query): void {
            $query->whereColumn('user_notification_preferences.organization_id', 'weekly_summary_deliveries.organization_id')->where('weekly_summary_enabled', false);
        })->latest('failed_at')->limit($limit)->get()->each(function (WeeklySummaryDelivery $delivery) use (&$queued, $summaryBuilder): void {
            SendWeeklySummaryEmail::dispatch((int) $delivery->user_id, (int) $delivery->organization_id, $summaryBuilder->build((int) $delivery->organization_id), $delivery->week_start->toDateString());
            $queued++;
        });
        $this->info('Queued weekly summary email retries: '.$queued);

        return self::SUCCESS;
    }
}
