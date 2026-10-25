<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Jobs\SendWeeklySummaryEmail;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\WeeklySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Signature('opsdeck:weekly-summaries {--dry-run : Count eligible recipients without dispatching email jobs}')]
#[Description('Queue weekly summary emails for opted-in organization members')]
class SendWeeklySummaries extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $eligibleMemberships = $this->eligibleMemberships();

        if ($this->option('dry-run')) {
            $eligibleOrganizationCount = (clone $eligibleMemberships)->distinct('organization_id')->count('organization_id');
            $eligibleRecipientCount = (clone $eligibleMemberships)->count();

            $this->info(sprintf(
                'Dry run: no jobs dispatched. Eligible organizations: %d; recipients: %d.',
                $eligibleOrganizationCount,
                $eligibleRecipientCount,
            ));

            return self::SUCCESS;
        }

        $queued = 0;
        $summaryBuilder = app(WeeklySummaryBuilder::class);
        $eligibleMemberships->select('organization_id')->distinct()->orderBy('organization_id')->chunkById(100, function (Collection $organizations) use (&$queued, $summaryBuilder): void {
            foreach ($organizations as $organization) {
                $organizationId = (int) $organization->organization_id;
                $summary = $summaryBuilder->build($organizationId);
                $timezone = Organization::query()->whereKey($organizationId)->value('timezone');
                $weekStart = CarbonImmutable::parse($summary['period_end'], $timezone)->startOfWeek(CarbonImmutable::SUNDAY)->toDateString();

                $this->eligibleMemberships($organizationId)
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

    private function eligibleMemberships(?int $organizationId = null): Builder
    {
        return OrganizationMembership::query()
            ->where('status', MembershipStatus::Active->value)
            ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->whereHas('user', fn (Builder $userQuery): Builder => $userQuery->canReceiveNonTransactionalEmail())
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('user.notificationPreferences', function (Builder $preferenceQuery): void {
                    $preferenceQuery->whereColumn('user_notification_preferences.organization_id', 'organization_memberships.organization_id');
                })->orWhereHas('user.notificationPreferences', function (Builder $preferenceQuery): void {
                    $preferenceQuery->whereColumn('user_notification_preferences.organization_id', 'organization_memberships.organization_id')
                        ->where('weekly_summary_enabled', true);
                });
            });
    }
}
