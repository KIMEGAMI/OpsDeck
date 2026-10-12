<?php

namespace Tests\Feature;

use App\Models\ImpactMetricSnapshot;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Services\ImpactReportMetricsService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
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
        $metricsService = Mockery::mock(ImpactReportMetricsService::class)->makePartial();
        $metricsService->shouldReceive('current')
            ->once()
            ->withArgs(fn (Organization $capturedOrganization): bool => $capturedOrganization->is($organization))
            ->andReturn(['employees' => 0, 'assets' => 0]);

        $first = $metricsService->captureDaily($organization);
        $second = $metricsService->captureDaily($organization);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->id, $second->id);
    }

    public function test_existing_daily_snapshots_are_checked_in_a_batch_for_organizations_with_the_same_local_date(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $organizations = collect(range(1, 2))->map(function (int $index): Organization {
            $suffix = Str::lower(Str::random(8));

            return Organization::factory()->create([
                'name' => 'Batch snapshot '.$index.' '.$suffix,
                'timezone' => 'Asia/Tokyo',
            ]);
        });

        foreach ($organizations as $organization) {
            ImpactMetricSnapshot::factory()->create([
                'organization_id' => $organization->id,
                'import_batch_id' => null,
                'stage' => 'daily',
                'snapshot_date' => now($organization->timezone)->toDateString(),
            ]);
        }

        config(['opsdeck.pagination.per_page' => Organization::query()->count() + 1]);
        $expectedSnapshotDateGroups = Organization::query()
            ->get()
            ->groupBy(fn (Organization $organization): string => now($organization->timezone)->toDateString())
            ->count();

        $snapshotSelects = [];
        DB::listen(static function (QueryExecuted $query) use (&$snapshotSelects): void {
            $normalizedSql = strtolower(ltrim($query->sql));
            if (str_starts_with($normalizedSql, 'select') && str_contains($normalizedSql, 'impact_metric_snapshots')) {
                $snapshotSelects[] = $query->sql;
            }
        });

        $this->artisan('opsdeck:impact:snapshots')
            ->expectsOutput('Captured daily impact snapshots: 0')
            ->assertExitCode(0);

        $this->assertSame($expectedSnapshotDateGroups, count($snapshotSelects), implode(PHP_EOL, $snapshotSelects));
        $this->assertLessThan(Organization::query()->count(), count($snapshotSelects));
    }

    public function test_pruning_only_deletes_daily_snapshots_older_than_the_configured_duration(): void
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
            'captured_at' => now()->subDays(366),
        ]);
        $cutoffSnapshot = ImpactMetricSnapshot::factory()->create([
            'organization_id' => $organization->id,
            'import_batch_id' => null,
            'stage' => 'daily',
            'snapshot_date' => $cutoffDate,
            'captured_at' => now()->subDays(365),
        ]);
        $currentSnapshot = ImpactMetricSnapshot::factory()->create([
            'organization_id' => $organization->id,
            'import_batch_id' => null,
            'stage' => 'daily',
            'snapshot_date' => $localToday,
            'captured_at' => now()->subDays(364),
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

    public function test_pruning_uses_the_same_exact_retention_duration_across_organization_timezones(): void
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
            $expiredSnapshots->push(ImpactMetricSnapshot::factory()->create([
                'organization_id' => $organization->id,
                'import_batch_id' => null,
                'stage' => 'daily',
                'snapshot_date' => now($organization->timezone)->subDays(366)->toDateString(),
                'captured_at' => now()->subDays(366),
            ]));
            $cutoffSnapshots->push(ImpactMetricSnapshot::factory()->create([
                'organization_id' => $organization->id,
                'import_batch_id' => null,
                'stage' => 'daily',
                'snapshot_date' => now($organization->timezone)->subDays(365)->toDateString(),
                'captured_at' => now()->subDays(365),
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
