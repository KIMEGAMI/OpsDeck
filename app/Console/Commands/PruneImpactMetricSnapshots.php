<?php

namespace App\Console\Commands;

use App\Models\ImpactMetricSnapshot;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('opsdeck:impact:prune-snapshots {--dry-run : Report eligible snapshots without deleting them}')]
#[Description('Prune daily impact snapshots older than the configured retention period')]
class PruneImpactMetricSnapshots extends Command
{
    public function handle(): int
    {
        $retentionDays = max(1, (int) config('opsdeck.impact_snapshots.daily_retention_days'));
        $chunkSize = max(1, (int) config('opsdeck.pagination.per_page'));
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($retentionDays);
        $affectedSnapshots = 0;

        try {
            Organization::query()->orderBy('id')->chunkById($chunkSize, function (Collection $organizations) use ($cutoff, $dryRun, &$affectedSnapshots): void {
                $eligibleSnapshots = ImpactMetricSnapshot::query()
                    ->whereIn('organization_id', $organizations->modelKeys())
                    ->where('stage', 'daily')
                    ->where('captured_at', '<', $cutoff);

                $affectedSnapshots += $dryRun
                    ? $eligibleSnapshots->count()
                    : $eligibleSnapshots->delete();
            });
        } catch (Throwable) {
            $this->error('Daily impact snapshot pruning failed.');

            return self::FAILURE;
        }

        $message = $dryRun
            ? 'Old daily impact snapshots eligible for deletion: '
            : 'Deleted old daily impact snapshots: ';
        $this->info($message.$affectedSnapshots);

        return self::SUCCESS;
    }
}
