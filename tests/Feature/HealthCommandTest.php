<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class HealthCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_health_command_passes_when_database_and_backup_destination_are_available(): void
    {
        $healthPath = $this->healthPath('healthy');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();

        $this->artisan('opsdeck:health')
            ->expectsOutput('OpsDeck health check passed.')
            ->assertExitCode(0);

        DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
        File::deleteDirectory($healthPath);
    }

    public function test_health_command_can_output_json(): void
    {
        $healthPath = $this->healthPath('json');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();

        $this->artisan('opsdeck:health', ['--json' => true])
            ->expectsOutput('{"status":"ok","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
            ->assertExitCode(0);

        DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
        File::deleteDirectory($healthPath);
    }

    public function test_health_command_reports_an_unavailable_failed_jobs_count(): void
    {
        $healthPath = $this->healthPath('missing-failed-jobs-table');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();
        $schemaBuilder = Schema::getFacadeRoot();
        Schema::shouldReceive('hasTable')->andReturnUsing(static function (string $table) use ($schemaBuilder): bool {
            return $table !== 'failed_jobs' && $schemaBuilder->hasTable($table);
        });

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":null,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_strict_health_check_fails_when_production_backup_restore_test_failed(): void
    {
        $healthPath = $this->healthPath('failed-backup-restore-test');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->app['env'] = 'production';
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();
        DB::table('backup_restore_checks')->insert(['status' => 'failed', 'completed_at' => now()]);

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutputToContain('"backup_restore_test":"failed"')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            DB::table('backup_restore_checks')->where('status', 'failed')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_health_command_returns_safe_json_on_database_failure(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('connection failed'));

        $this->artisan('opsdeck:health', ['--json' => true])
            ->expectsOutput('{"status":"failed","failed_check":"database_connection"}')
            ->assertExitCode(1);
    }

    public function test_strict_health_check_fails_when_queue_errors_exist(): void
    {
        $healthPath = $this->healthPath('failed-jobs');
        $uuid = (string) Str::uuid();
        config(['opsdeck.backup.path' => $healthPath]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'testing', 'queue' => 'default', 'payload' => '{}', 'exception' => 'test', 'failed_at' => now()]);

        $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
            ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":1,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
            ->assertExitCode(1);

        DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
        File::deleteDirectory($healthPath);
    }

    public function test_health_command_does_not_report_zero_stale_jobs_for_an_unmonitored_queue_driver(): void
    {
        $healthPath = $this->healthPath('unsupported-queue-monitoring');
        config(['opsdeck.backup.path' => $healthPath, 'queue.default' => 'sqs']);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":null,"queue_backlog_status":"unknown","queue_timeout_status":"unknown","queue_worker_status":"stale","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_health_command_warns_when_import_timeout_is_not_shorter_than_queue_retry_after(): void
    {
        $healthPath = $this->healthPath('invalid-queue-timeout');
        config([
            'opsdeck.backup.path' => $healthPath,
            'opsdeck.import.job_timeout_seconds' => 300,
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 300,
        ]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_backlog_status":"ok","queue_timeout_status":"invalid","queue_worker_status":"stale","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_health_command_warns_when_webhook_timeout_is_not_shorter_than_queue_retry_after(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $healthPath = $this->healthPath('invalid-webhook-queue-timeout');
        config([
            'opsdeck.backup.path' => $healthPath,
            'opsdeck.import.job_timeout_seconds' => 300,
            'services.stripe.webhook_job_timeout_seconds' => 400,
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 360,
        ]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutputToContain('"queue_timeout_status":"invalid"')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_health_command_warns_when_production_uses_the_synchronous_queue(): void
    {
        $healthPath = $this->healthPath('production-sync-queue');
        config(['opsdeck.backup.path' => $healthPath, 'queue.default' => 'sync']);
        $this->app['env'] = 'production';
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":null,"queue_backlog_status":"invalid","queue_timeout_status":"invalid","queue_worker_status":"invalid","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"missing"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_strict_health_check_fails_when_webhook_errors_exist(): void
    {
        $healthPath = $this->healthPath('webhook-errors');
        $eventId = 'evt_test_'.bin2hex(random_bytes(8));
        config(['opsdeck.backup.path' => $healthPath]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();
        DB::table('stripe_webhook_events')->insert(['stripe_event_id' => $eventId, 'event_type' => 'customer.updated', 'payload_hash' => hash('sha256', $eventId), 'processing_error' => 'test', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
            ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":1,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
            ->assertExitCode(1);

        DB::table('stripe_webhook_events')->where('stripe_event_id', $eventId)->delete();
        DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
        File::deleteDirectory($healthPath);
    }

    public function test_strict_health_check_fails_when_a_webhook_remains_unprocessed_past_the_cutoff(): void
    {
        $healthPath = $this->healthPath('stale-webhook');
        $staleEventId = 'evt_stale_'.Str::uuid();
        $recentEventId = 'evt_recent_'.Str::uuid();
        config(['opsdeck.backup.path' => $healthPath, 'opsdeck.stripe_webhook.stale_after_minutes' => 5]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();
        DB::table('stripe_webhook_events')->insert([
            ['stripe_event_id' => $staleEventId, 'event_type' => 'invoice.paid', 'payload_hash' => hash('sha256', $staleEventId), 'created_at' => now()->subMinutes(6), 'updated_at' => now()->subMinutes(6)],
            ['stripe_event_id' => $recentEventId, 'event_type' => 'invoice.paid', 'payload_hash' => hash('sha256', $recentEventId), 'created_at' => now()->subMinutes(4), 'updated_at' => now()->subMinutes(4)],
        ]);

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":1,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('stripe_webhook_events')->whereIn('stripe_event_id', [$staleEventId, $recentEventId])->delete();
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_strict_health_check_fails_when_an_import_batch_is_stale(): void
    {
        $healthPath = $this->healthPath('stale-import');
        config(['opsdeck.backup.path' => $healthPath, 'opsdeck.import.stale_after_minutes' => 10]);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();
        $organization = Organization::query()->create([
            'name' => 'Health test organization',
            'slug' => 'health-test-'.Str::lower(Str::random(12)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        DB::table('import_batches')->insert([
            [
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'health-test-queued',
                'status' => 'queued',
                'started_at' => null,
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
            ],
            [
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'health-test-previewing',
                'status' => 'previewing',
                'started_at' => now()->subMinutes(20),
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(20),
            ],
            [
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'health-test-analyzing',
                'status' => 'analyzing',
                'started_at' => now()->subMinutes(20),
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(20),
            ],
            [
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'health-test-executing',
                'status' => 'executing',
                'started_at' => now()->subMinutes(20),
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(20),
            ],
        ]);

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":4,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('import_batches')->where('organization_id', $organization->id)->delete();
            $organization->delete();
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_strict_health_check_fails_when_database_queue_jobs_are_stale(): void
    {
        $healthPath = $this->healthPath('stale-queue');
        config(['opsdeck.backup.path' => $healthPath, 'opsdeck.queue.stale_after_minutes' => 10, 'queue.default' => 'database']);
        $this->createFreshBackup($healthPath);
        $this->recordFreshSchedulerHeartbeat();
        $queueName = 'health-test-'.Str::lower(Str::random(12));
        $now = now()->timestamp;
        DB::table('jobs')->insert([
            ['queue' => $queueName, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now - 1200, 'created_at' => $now - 1200],
            ['queue' => $queueName, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now - 300, 'created_at' => $now - 300],
            ['queue' => $queueName, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now + 3600, 'created_at' => $now - 7200],
            ['queue' => $queueName, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $now - 300, 'available_at' => $now - 300, 'created_at' => $now - 300],
            ['queue' => $queueName, 'payload' => '{}', 'attempts' => 1, 'reserved_at' => $now - 1200, 'available_at' => $now - 1200, 'created_at' => $now - 1200],
        ]);

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":2,"queue_backlog_status":"stale","queue_timeout_status":"ok","queue_worker_status":"stale","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('jobs')->where('queue', $queueName)->delete();
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_health_check_distinguishes_missing_and_stale_scheduler_heartbeats(): void
    {
        $healthPath = $this->healthPath('missing-scheduler-heartbeat');
        config(['opsdeck.backup.path' => $healthPath, 'opsdeck.scheduler.heartbeat_max_age_minutes' => 5]);
        $this->createFreshBackup($healthPath);

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"missing","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);

            DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()->subMinutes(10)]);

            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"ok","scheduler_heartbeat":"stale","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_scheduler_heartbeat_command_is_idempotent_and_refreshes_last_seen(): void
    {
        try {
            $this->artisan('opsdeck:scheduler:heartbeat')->assertExitCode(0);
            $firstSeenAt = DB::table('scheduler_heartbeats')->where('name', 'scheduler')->value('last_seen_at');

            $this->travel(2)->minutes();
            $this->artisan('opsdeck:scheduler:heartbeat')->assertExitCode(0);

            $this->assertSame(1, DB::table('scheduler_heartbeats')->where('name', 'scheduler')->count());
            $this->assertGreaterThan(strtotime($firstSeenAt), strtotime(DB::table('scheduler_heartbeats')->where('name', 'scheduler')->value('last_seen_at')));
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
        }
    }

    private function healthPath(string $suffix): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-health-'.$suffix.'-'.Str::uuid();
    }

    private function recordFreshSchedulerHeartbeat(): void
    {
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
    }

    private function createFreshBackup(string $backupRoot): void
    {
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'backup-'.Str::uuid();
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
    }

    public function test_strict_health_check_fails_when_backup_is_missing_or_stale(): void
    {
        $healthPath = $this->healthPath('backup-freshness');
        config(['opsdeck.backup.path' => $healthPath, 'opsdeck.backup.max_age_hours' => 26]);
        $this->recordFreshSchedulerHeartbeat();

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"missing","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);

            $backupDirectory = $healthPath.DIRECTORY_SEPARATOR.'old-backup';
            File::ensureDirectoryExists($backupDirectory);
            File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
            File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->subHours(27)->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));

            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"stale","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_strict_health_check_fails_when_latest_backup_database_file_is_missing(): void
    {
        $healthPath = $this->healthPath('backup-database-missing');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->recordFreshSchedulerHeartbeat();
        $backupDirectory = $healthPath.DIRECTORY_SEPARATOR.'broken-backup';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"invalid","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_fresh_backup_with_incomplete_manifest_is_not_accepted(): void
    {
        $healthPath = $this->healthPath('backup-invalid-manifest');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->recordFreshSchedulerHeartbeat();
        $backupDirectory = $healthPath.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"invalid","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_strict_health_check_rejects_evidence_that_does_not_match_its_backup_checksum(): void
    {
        $healthPath = $this->healthPath('backup-evidence-checksum');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->recordFreshSchedulerHeartbeat();
        $backupDirectory = $healthPath.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.'contracts');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'proof.pdf', 'changed-evidence');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'created_at' => now()->toIso8601String(),
            'database' => ['driver' => 'mysql', 'file' => 'database.sql'],
            'evidence_files' => ['contracts/proof.pdf'],
            'evidence_sha256' => [hash('sha256', 'expected-evidence')],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"invalid","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_strict_health_check_rejects_database_dump_that_does_not_match_its_backup_checksum(): void
    {
        $healthPath = $this->healthPath('backup-database-checksum');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->recordFreshSchedulerHeartbeat();
        $backupDirectory = $healthPath.DIRECTORY_SEPARATOR.'backup';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'changed-database-dump');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'created_at' => now()->toIso8601String(),
            'database' => ['driver' => 'mysql', 'file' => 'database.sql'],
            'database_sha256' => hash('sha256', 'original-database-dump'),
            'evidence_files' => [],
            'evidence_sha256' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"invalid","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }

    public function test_backup_manifest_timestamp_in_the_future_is_not_accepted(): void
    {
        $healthPath = $this->healthPath('backup-future-timestamp');
        config(['opsdeck.backup.path' => $healthPath]);
        $this->recordFreshSchedulerHeartbeat();
        $backupDirectory = $healthPath.DIRECTORY_SEPARATOR.'future-backup';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->addDay()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('opsdeck:health', ['--json' => true, '--strict' => true])
                ->expectsOutput('{"status":"warning","database":"ok","backup":"invalid","scheduler_heartbeat":"ok","queue_failed_jobs":0,"stale_queue_jobs":0,"queue_timeout_status":"not_applicable","queue_worker_status":"not_applicable","webhook_errors":0,"stale_webhooks":0,"stale_import_batches":0,"backup_restore_test":"not_applicable"}')
                ->assertExitCode(1);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($healthPath);
        }
    }
}
