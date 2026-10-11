<?php

namespace App\Console\Commands;

use App\Jobs\SendWeeklySummaryEmail;
use App\Models\Organization;
use App\Models\UserNotificationPreference;
use App\Services\WeeklySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('opsdeck:weekly-summaries')]
#[Description('Queue weekly summary emails for opted-in organization members')]
class SendWeeklySummaries extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;
        $summaryBuilder = app(WeeklySummaryBuilder::class);
        UserNotificationPreference::query()->where('weekly_summary_enabled', true)->whereHas('user.organizationMemberships', function ($query): void {
            $query->whereColumn('organization_memberships.organization_id', 'user_notification_preferences.organization_id')->where('status', 'active');
        })->select('organization_id')->distinct()->orderBy('organization_id')->chunkById(100, function (Collection $organizations) use (&$queued, $summaryBuilder): void {
            foreach ($organizations as $organization) {
                $organizationId = (int) $organization->organization_id;
                $summary = $summaryBuilder->build($organizationId);
                $timezone = Organization::query()->whereKey($organizationId)->value('timezone');
                $weekStart = CarbonImmutable::parse($summary['period_end'], $timezone)->startOfWeek()->toDateString();

                UserNotificationPreference::query()
                    ->where('organization_id', $organizationId)
                    ->where('weekly_summary_enabled', true)
                    ->whereHas('user.organizationMemberships', function ($query): void {
                        $query->whereColumn('organization_memberships.organization_id', 'user_notification_preferences.organization_id')->where('status', 'active');
                    })
                    ->orderBy('id')
                    ->chunkById(100, function (Collection $preferences) use (&$queued, $organizationId, $summary, $weekStart): void {
                        foreach ($preferences as $preference) {
                            SendWeeklySummaryEmail::dispatch((int) $preference->user_id, $organizationId, $summary, $weekStart);
                            $queued++;
                        }
                    });
            }
        }, 'organization_id', 'organization_id');
        $this->info('Queued weekly summaries: '.$queued);

        return self::SUCCESS;
    }
}
