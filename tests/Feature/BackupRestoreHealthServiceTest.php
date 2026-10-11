<?php

namespace Tests\Feature;

use App\Services\BackupRestoreHealthService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupRestoreHealthServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.backup.restore_test_max_age_days' => 10]);
    }

    public function test_restore_test_status_is_not_applicable_outside_production(): void
    {
        $this->assertSame(
            ['status' => 'not_applicable', 'completed_at' => '対象外'],
            app(BackupRestoreHealthService::class)->report(),
        );
    }

    public function test_production_restore_test_status_is_missing_until_a_run_is_recorded(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(
            ['status' => 'missing', 'completed_at' => '未確認'],
            app(BackupRestoreHealthService::class)->report(),
        );
    }

    public function test_recent_successful_restore_test_is_healthy(): void
    {
        $this->app['env'] = 'production';
        $this->recordCheck('passed', now()->subDay());

        $report = app(BackupRestoreHealthService::class)->report();

        $this->assertSame('ok', $report['status']);
        $this->assertSame(now()->subDay()->format('Y-m-d H:i:s'), $report['completed_at']);
    }

    public function test_failed_restore_test_is_reported_as_failed(): void
    {
        $this->app['env'] = 'production';
        $this->recordCheck('failed', now());

        $this->assertSame('failed', app(BackupRestoreHealthService::class)->report()['status']);
    }

    public function test_old_successful_restore_test_is_reported_as_stale(): void
    {
        $this->app['env'] = 'production';
        $this->recordCheck('passed', now()->subDays(11));

        $this->assertSame('stale', app(BackupRestoreHealthService::class)->report()['status']);
    }

    public function test_future_restore_test_timestamp_is_invalid(): void
    {
        $this->app['env'] = 'production';
        $this->recordCheck('passed', now()->addMinute());

        $this->assertSame('invalid', app(BackupRestoreHealthService::class)->report()['status']);
    }

    private function recordCheck(string $status, CarbonInterface $completedAt): void
    {
        DB::table('backup_restore_checks')->insert([
            'status' => $status,
            'completed_at' => $completedAt,
        ]);
    }
}
