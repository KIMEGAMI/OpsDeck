<?php

namespace Tests\Feature;

use App\Models\ImpactMetricSnapshot;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Services\ImpactReportMetricsService;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImpactMetricSnapshotCommandTest extends TestCase
{
    public function test_daily_snapshots_are_captured_once_per_organization_local_date(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(12));
        $organization = Organization::query()->create([
            'name' => 'Snapshot '.$suffix,
            'slug' => 'snapshot-'.$suffix,
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);

        $this->artisan('opsdeck:impact:snapshots')->assertExitCode(0);
        $this->artisan('opsdeck:impact:snapshots')->assertExitCode(0);

        $snapshots = ImpactMetricSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('stage', 'daily')
            ->get();
        $this->assertCount(1, $snapshots);
        $this->assertSame(now('Asia/Tokyo')->toDateString(), $snapshots->firstOrFail()->snapshot_date->toDateString());
    }

    public function test_daily_snapshot_service_is_idempotent_for_the_same_organization_and_date(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(12));
        $organization = Organization::query()->create([
            'name' => 'Snapshot '.$suffix,
            'slug' => 'snapshot-'.$suffix,
            'timezone' => 'Asia/Tokyo',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $metricsService = app(ImpactReportMetricsService::class);

        $first = $metricsService->captureDaily($organization);
        $second = $metricsService->captureDaily($organization);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->id, $second->id);
    }

    public function test_pruning_only_deletes_daily_snapshots_older_than_the_organization_local_cutoff(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.impact_snapshots.daily_retention_days' => 365]);
        $this->travelTo('2026-10-11 06:30:00');
        $suffix = Str::lower(Str::random(12));
        $organization = Organization::query()->create([
            'name' => 'Snapshot retention '.$suffix,
            'slug' => 'snapshot-retention-'.$suffix,
            'timezone' => 'America/Los_Angeles',
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $localToday = now($organization->timezone)->toDateString();
        $eligibleDate = now($organization->timezone)->subDays(366)->toDateString();
        $cutoffDate = now($organization->timezone)->subDays(365)->toDateString();
        $eligibleSnapshot = ImpactMetricSnapshot::factory()->create([
            'organization_id' => $organization->id,
            'import_batch_id' => null,
            'stage' => 'daily',
            'snapshot_date' => $eligibleDate,
        ]);
        $cutoffSnapshot = ImpactMetricSnapshot::factory()->create([
            'organization_id' => $organization->id,
            'import_batch_id' => null,
            'stage' => 'daily',
            'snapshot_date' => $cutoffDate,
        ]);
        $currentSnapshot = ImpactMetricSnapshot::factory()->create([
            'organization_id' => $organization->id,
            'import_batch_id' => null,
            'stage' => 'daily',
            'snapshot_date' => $localToday,
        ]);
        $importBatch = ImportBatch::factory()->create(['organization_id' => $organization->id]);
        $comparisonSnapshots = collect(['import_before', 'import_after', 'rollback_after'])
            ->map(fn (string $stage): ImpactMetricSnapshot => ImpactMetricSnapshot::factory()->create([
                'organization_id' => $organization->id,
                'import_batch_id' => $importBatch->id,
                'stage' => $stage,
                'snapshot_date' => null,
                'captured_at' => now()->subYears(2),
            ]));

        $this->artisan('opsdeck:impact:prune-snapshots --dry-run')
            ->expectsOutput('Old daily impact snapshots eligible for deletion: 1')
            ->assertExitCode(0);
        $this->assertDatabaseHas('impact_metric_snapshots', ['id' => $eligibleSnapshot->id]);

        $this->artisan('opsdeck:impact:prune-snapshots')
            ->expectsOutput('Deleted old daily impact snapshots: 1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('impact_metric_snapshots', ['id' => $eligibleSnapshot->id]);
        $this->assertDatabaseHas('impact_metric_snapshots', ['id' => $cutoffSnapshot->id]);
        $this->assertDatabaseHas('impact_metric_snapshots', ['id' => $currentSnapshot->id]);
        foreach ($comparisonSnapshots as $comparisonSnapshot) {
            $this->assertDatabaseHas('impact_metric_snapshots', ['id' => $comparisonSnapshot->id]);
        }
    }

    public function test_pruning_preserves_local_cutoff_dates_for_organizations_in_different_timezones(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.impact_snapshots.daily_retention_days' => 365]);
        $this->travelTo('2026-10-11 06:30:00');

        $organizations = collect(['America/Los_Angeles', 'Asia/Tokyo'])
            ->map(function (string $timezone): Organization {
                $suffix = Str::lower(Str::random(8));

                return Organization::query()->create([
                    'name' => 'Snapshot timezone '.$suffix,
                    'slug' => 'snapshot-timezone-'.$suffix,
                    'timezone' => $timezone,
                    'plan_code' => config('opsdeck.default_plan_code'),
                    'billing_status' => config('opsdeck.default_billing_status'),
                ]);
            });

        $expiredSnapshots = collect();
        $cutoffSnapshots = collect();
        foreach ($organizations as $organization) {
            $cutoffDate = now($organization->timezone)->subDays(365)->toDateString();

            $expiredSnapshots->push(ImpactMetricSnapshot::factory()->create([
                'organization_id' => $organization->id,
                'import_batch_id' => null,
                'stage' => 'daily',
                'snapshot_date' => now($organization->timezone)->subDays(366)->toDateString(),
            ]));
            $cutoffSnapshots->push(ImpactMetricSnapshot::factory()->create([
                'organization_id' => $organization->id,
                'import_batch_id' => null,
                'stage' => 'daily',
                'snapshot_date' => $cutoffDate,
            ]));
        }

        $this->artisan('opsdeck:impact:prune-snapshots')
            ->expectsOutput('Deleted old daily impact snapshots: 2')
            ->assertExitCode(0);

        foreach ($expiredSnapshots as $snapshot) {
            $this->assertDatabaseMissing('impact_metric_snapshots', ['id' => $snapshot->id]);
        }
        foreach ($cutoffSnapshots as $snapshot) {
            $this->assertDatabaseHas('impact_metric_snapshots', ['id' => $snapshot->id]);
        }
    }
}
