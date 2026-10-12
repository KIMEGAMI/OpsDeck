<?php

namespace App\Console\Commands;

use App\Models\ImpactMetricSnapshot;
use App\Models\Organization;
use App\Services\ImpactReportMetricsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('opsdeck:impact:snapshots')]
#[Description('Capture one organization-scoped impact metrics snapshot per local day')]
class CaptureImpactMetricSnapshots extends Command
{
    public function handle(ImpactReportMetricsService $metricsService): int
    {
        $capturedSnapshots = 0;
        $chunkSize = max(1, (int) config('opsdeck.pagination.per_page'));

        try {
            Organization::query()->orderBy('id')->chunkById($chunkSize, function (EloquentCollection $organizations) use ($metricsService, &$capturedSnapshots): void {
                $organizationsBySnapshotDate = $organizations->groupBy(fn (Organization $organization): string => Carbon::today($organization->timezone)->toDateString());

                foreach ($organizationsBySnapshotDate as $snapshotDate => $organizationsForDate) {
                    $existingOrganizationIds = ImpactMetricSnapshot::query()
                        ->whereIn('organization_id', $organizationsForDate->pluck('id'))
                        ->where('stage', 'daily')
                        ->where('snapshot_date', Carbon::today($organizationsForDate->first()->timezone))
                        ->pluck('organization_id')
                        ->map(static fn (mixed $organizationId): int => (int) $organizationId)
                        ->all();
                    $existingOrganizationIds = array_fill_keys($existingOrganizationIds, true);

                    foreach ($organizationsForDate as $organization) {
                        if (isset($existingOrganizationIds[$organization->id])) {
                            continue;
                        }

                        if ($metricsService->captureDaily($organization)->wasRecentlyCreated) {
                            $capturedSnapshots++;
                        }
                    }
                }
            });
        } catch (Throwable) {
            $this->error('Daily impact metrics snapshots failed.');

            return self::FAILURE;
        }

        $this->info('Captured daily impact snapshots: '.$capturedSnapshots);

        return self::SUCCESS;
    }
}
