<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\ProductEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('opsdeck:billing:enforce-grace')]
#[Description('Downgrade Premium organizations after the configured payment grace period')]
class EnforceBillingGracePeriod extends Command
{
    public function handle(ProductEventRecorder $productEventRecorder): int
    {
        $graceDays = max(0, (int) config('opsdeck.billing.past_due_grace_days'));
        $chunkSize = max(1, (int) config('opsdeck.pagination.per_page'));
        $cutoff = now()->subDays($graceDays);
        $downgradedOrganizations = 0;
        $processingFailed = false;

        Organization::query()
            ->where('plan_code', config('opsdeck.premium_plan_code'))
            ->where('billing_status', 'past_due')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('billing_past_due_since')->orWhere('billing_past_due_since', '<=', $cutoff);
            })
            ->orderBy('id')
            ->chunkById($chunkSize, function ($organizations) use (&$downgradedOrganizations, &$processingFailed, $productEventRecorder, $cutoff): bool {
                if ($processingFailed) {
                    return false;
                }

                foreach ($organizations as $organization) {
                    try {
                        DB::transaction(function () use ($organization, &$downgradedOrganizations, $productEventRecorder, $cutoff): void {
                            $lockedOrganization = Organization::query()->whereKey($organization->id)->lockForUpdate()->first();
                            if ($lockedOrganization === null
                                || $lockedOrganization->plan_code !== config('opsdeck.premium_plan_code')
                                || $lockedOrganization->billing_status !== 'past_due') {
                                return;
                            }

                            $pastDueSince = $lockedOrganization->billing_past_due_since;
                            if ($pastDueSince === null) {
                                $pastDueSince = $lockedOrganization->stripe_last_event_created_at === null
                                    ? null
                                    : CarbonImmutable::createFromTimestamp($lockedOrganization->stripe_last_event_created_at);
                                $lockedOrganization->update(['billing_past_due_since' => $pastDueSince ?? now()]);

                                if ($pastDueSince === null || $pastDueSince->greaterThan($cutoff)) {
                                    return;
                                }
                            }
                            if ($pastDueSince->greaterThan($cutoff)) {
                                return;
                            }

                            $lockedOrganization->update(['plan_code' => config('opsdeck.free_plan_code')]);
                            $productEventRecorder->recordOnce('billing_past_due_grace_expired', $lockedOrganization);
                            $downgradedOrganizations++;
                        });
                    } catch (Throwable) {
                        $this->error('Billing grace enforcement failed.');
                        $processingFailed = true;

                        break;
                    }
                }

                return ! $processingFailed;
            });

        if ($processingFailed) {
            return self::FAILURE;
        }

        $this->info('Downgraded organizations after billing grace period: '.$downgradedOrganizations);

        return self::SUCCESS;
    }
}
