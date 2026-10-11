<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\ImpactReportMetricsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
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
            Organization::query()->orderBy('id')->chunkById($chunkSize, function (Collection $organizations) use ($metricsService, &$capturedSnapshots): void {
                foreach ($organizations as $organization) {
                    if ($metricsService->captureDaily($organization)->wasRecentlyCreated) {
                        $capturedSnapshots++;
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
