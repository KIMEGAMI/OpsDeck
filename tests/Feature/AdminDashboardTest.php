<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\ContractMonthlyCostCalculator;
use App\Services\DatabaseMigrationHealthService;
use App\Services\ImportFailureMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_platform_admin_can_view_aggregate_dashboard(): void
    {
        $user = User::factory()->create(['email' => 'platform-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);

        $response = $this->actingAs($user)->get(route('admin.dashboard'));
        $response->assertOk()->assertSee('OpsDeck運営管理')->assertSee('契約社数')->assertSee(route('admin.ai-cost'))->assertSee(route('admin.queue-errors'))->assertSee(route('admin.health'));
        $this->assertSame(1, substr_count($response->getContent(), '製品利用状況'));
    }

    public function test_platform_dashboard_uses_terminal_import_attempts_for_failure_rate(): void
    {
        $user = User::factory()->create(['email' => 'platform-import-rate-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $organization = Organization::query()->create(['name' => 'Dashboard Import Metrics', 'slug' => 'dashboard-import-metrics-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $batches = collect([
            ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'completed', 'status' => 'completed']),
            ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed', 'status' => 'failed']),
            ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'preview-only', 'status' => 'preview']),
            ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'cancelled', 'status' => 'cancelled']),
        ]);
        $metrics = app(ImportFailureMetrics::class)->summarize();

        try {
            $this->actingAs($user)->get(route('admin.dashboard'))
                ->assertOk()
                ->assertSee('Import処理失敗率（完了・失敗した処理）')
                ->assertSee($metrics['rate'].'%');
        } finally {
            ImportBatch::query()->where('organization_id', $organization->id)->delete();
            $organization->delete();
        }
    }

    public function test_non_platform_admin_cannot_view_dashboard(): void
    {
        $user = User::factory()->create(['email' => 'member-'.Str::uuid().'@example.test']);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_non_platform_admin_cannot_view_health_page(): void
    {
        $user = User::factory()->create(['email' => 'member-health-'.Str::uuid().'@example.test']);

        $this->actingAs($user)->get(route('admin.health'))->assertForbidden();
    }

    public function test_platform_admin_can_view_ai_cost_and_error_pages(): void
    {
        $user = User::factory()->create(['email' => 'platform-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);

        $this->actingAs($user)->get(route('admin.ai-cost'))->assertOk()->assertSee('AI原価');
        $this->actingAs($user)->get(route('admin.queue-errors'))
            ->assertOk()
            ->assertSee('Queue / Webhookエラー')
            ->assertSee('php artisan queue:retry &lt;UUID&gt;', false)
            ->assertSee('全件一括再試行は避けてください')
            ->assertSee('メール配信の手動確認')
            ->assertSee(route('admin.mail-delivery-reviews.index'));
    }

    public function test_platform_admin_can_find_an_import_failure_by_the_reference_shown_to_the_member(): void
    {
        $user = User::factory()->create(['email' => 'platform-import-reference-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $organization = Organization::query()->create(['name' => 'Import Reference Org', 'slug' => 'import-reference-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $targetBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'sensitive-source-name.csv', 'status' => 'failed']);
        $otherBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'xlsx', 'source_name' => 'other-source-name.xlsx', 'status' => 'failed']);
        $reference = 'IMP-'.$targetBatch->id;

        $this->actingAs($user)->get(route('admin.queue-errors', ['import_reference' => $reference]))
            ->assertOk()
            ->assertSee($reference)
            ->assertDontSee('IMP-'.$otherBatch->id)
            ->assertDontSee('sensitive-source-name.csv')
            ->assertDontSee('other-source-name.xlsx');
    }

    public function test_platform_admin_can_identify_stale_imports_without_exposing_source_names(): void
    {
        $user = User::factory()->create(['email' => 'platform-stale-import-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.import.stale_after_minutes' => 10]);
        $organization = Organization::query()->create(['name' => 'Stale Import Org', 'slug' => 'stale-import-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $staleBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'confidential-stale-upload.csv', 'status' => 'previewing']);
        $freshBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'xlsx', 'source_name' => 'fresh-upload.xlsx', 'status' => 'previewing']);
        DB::table('import_batches')->where('id', $staleBatch->id)->update(['updated_at' => now()->subMinutes(11)]);

        try {
            $this->actingAs($user)->get(route('admin.queue-errors'))
                ->assertOk()
                ->assertSee('処理が滞留しているImport')
                ->assertSee('IMP-'.$staleBatch->id)
                ->assertSee('状態：previewing')
                ->assertDontSee('<li>IMP-'.$freshBatch->id.' /', false)
                ->assertDontSee('confidential-stale-upload.csv')
                ->assertDontSee('fresh-upload.xlsx')
                ->assertSee('ここでは自動再実行しません。');
        } finally {
            ImportBatch::query()->whereKey([$staleBatch->id, $freshBatch->id])->delete();
            $organization->delete();
        }
    }

    public function test_platform_admin_import_error_filter_rejects_query_injection(): void
    {
        $user = User::factory()->create(['email' => 'platform-import-filter-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);

        $this->actingAs($user)->get(route('admin.queue-errors', ['import_reference' => 'IMP-1 OR 1=1']))
            ->assertSessionHasErrors('import_reference');
    }

    public function test_platform_admin_can_find_the_stripe_event_reference_shown_to_the_customer(): void
    {
        $user = User::factory()->create(['email' => 'platform-stripe-reference-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $eventId = 'evt_customer_reference_'.Str::uuid();
        StripeWebhookEvent::query()->create(['stripe_event_id' => $eventId, 'event_type' => 'invoice.payment_failed', 'payload_hash' => hash('sha256', $eventId), 'processed_at' => now()]);

        $this->actingAs($user)->get(route('admin.queue-errors', ['stripe_reference' => 'STRIPE-'.$eventId]))
            ->assertOk()
            ->assertSee('STRIPE-'.$eventId)
            ->assertSee('invoice.payment_failed')
            ->assertSee('処理済み');
    }

    public function test_platform_admin_can_find_a_stale_unprocessed_webhook_by_reference(): void
    {
        $user = User::factory()->create(['email' => 'platform-stale-webhook-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.stripe_webhook.stale_after_minutes' => 5]);
        $eventId = 'evt_stale_reference_'.Str::uuid();
        DB::table('stripe_webhook_events')->insert([
            'stripe_event_id' => $eventId,
            'event_type' => 'invoice.paid',
            'payload_hash' => hash('sha256', $eventId),
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(6),
        ]);

        try {
            $this->actingAs($user)->get(route('admin.queue-errors'))
                ->assertOk()
                ->assertSee('処理停止Webhook')
                ->assertSee('STRIPE-'.$eventId)
                ->assertSee(route('admin.queue-errors', ['stripe_reference' => 'STRIPE-'.$eventId]), false);
        } finally {
            DB::table('stripe_webhook_events')->where('stripe_event_id', $eventId)->delete();
        }
    }

    public function test_platform_admin_stripe_reference_filter_rejects_query_injection(): void
    {
        $user = User::factory()->create(['email' => 'platform-stripe-filter-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);

        $this->actingAs($user)->get(route('admin.queue-errors', ['stripe_reference' => 'STRIPE-evt_1 OR 1=1']))
            ->assertSessionHasErrors('stripe_reference');
    }

    public function test_platform_admin_can_view_health_page(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('運用ヘルス')->assertSee('総合状態')->assertSee('role="status"', false)->assertSee('aria-live="polite"', false)->assertSee(route('admin.queue-errors'))->assertSee(route('admin.product-metrics'))->assertSee('最新バックアップ')->assertSee('最新バックアップ確認日時')->assertSee('DBマイグレーション')->assertSee('Scheduler稼働確認')->assertSee('正常')->assertSee('php artisan schedule:run')->assertSee('Queue失敗件数')->assertSee('Webhookエラー件数')->assertSee('処理停止Webhook件数')->assertSee('滞留Import件数');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_warns_when_failed_jobs_table_is_missing(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-missing-failed-jobs-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-missing-failed-jobs-'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);
        $schemaBuilder = Schema::getFacadeRoot();
        Schema::shouldReceive('hasTable')->andReturnUsing(static function (string $table) use ($schemaBuilder): bool {
            return $table !== 'failed_jobs' && $schemaBuilder->hasTable($table);
        });

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('要確認')
                ->assertSee('未確認（failed_jobsテーブルがありません）');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_marks_latest_backup_as_available(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-backup-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-backup/'.Str::uuid());
        $latestBackup = $backupRoot.DIRECTORY_SEPARATOR.'20260927_020000';
        File::ensureDirectoryExists($latestBackup);
        File::put($latestBackup.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($latestBackup.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('正常')->assertSee('利用可能');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_warns_when_database_migrations_are_pending(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-pending-migrations-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $migrationHealth = $this->mock(DatabaseMigrationHealthService::class);
        $migrationHealth->shouldReceive('pendingCount')->once()->andReturn(2);
        $this->app->instance(DatabaseMigrationHealthService::class, $migrationHealth);

        $this->actingAs($user)->get(route('admin.health'))
            ->assertOk()
            ->assertSee('DBマイグレーション')
            ->assertSee('未適用 2件')
            ->assertSee('要確認');
    }

    public function test_health_page_warns_when_the_last_backup_restore_test_failed(): void
    {
        $user = User::factory()->create(['email' => 'platform-restore-health-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $this->app['env'] = 'production';
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        DB::table('backup_restore_checks')->insert(['status' => 'failed', 'completed_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-restore-health/'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('要確認')
                ->assertSee('バックアップ復元テスト')
                ->assertSee('失敗');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            DB::table('backup_restore_checks')->where('status', 'failed')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_warns_when_latest_backup_is_stale(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-stale-backup-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.backup.max_age_hours' => 26]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-stale-backup/'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'old';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->subHours(27)->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('要確認')->assertSee('期限超過');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_marks_latest_backup_invalid_when_database_file_is_missing(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-invalid-backup-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-invalid-backup/'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('要確認')->assertSee('検証失敗');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_reports_stale_import_batches(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-import-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.import.stale_after_minutes' => 10]);
        $organization = Organization::query()->create([
            'name' => 'Admin health import',
            'slug' => 'admin-health-import-'.Str::lower(Str::random(12)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        DB::table('import_batches')->insert([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'admin-health-import',
            'status' => 'analyzing',
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('滞留Import件数</dt><dd>1</dd>', false);
        } finally {
            DB::table('import_batches')->where('organization_id', $organization->id)->delete();
            $organization->delete();
        }
    }

    public function test_health_page_reports_stale_database_queue_jobs(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-queue-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.queue.stale_after_minutes' => 10, 'queue.default' => 'database']);
        $queueName = 'admin-health-'.Str::lower(Str::random(12));
        $now = now()->timestamp;
        DB::table('jobs')->insert([
            'queue' => $queueName,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 1200,
            'created_at' => $now - 1200,
        ]);

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('滞留Queue件数</dt><dd>1</dd>', false)
                ->assertSee('要確認');
        } finally {
            DB::table('jobs')->where('queue', $queueName)->delete();
        }
    }

    public function test_health_page_marks_an_unmonitored_queue_driver_as_unknown(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-unsupported-queue-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'queue.default' => 'sqs']);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-queue-'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('要確認')
                ->assertSee('未確認（現在のQueue方式またはjobsテーブルを監視できません）')
                ->assertSee('ImportタイムアウトとQueue再配信猶予</dt><dd>未確認（Queue基盤側の設定確認が必要）</dd>', false)
                ->assertSee('滞留Queue件数</dt><dd>未確認</dd>', false);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_admin_dashboard_converts_annual_contracts_to_monthly_cost(): void
    {
        $user = User::factory()->create(['email' => 'platform-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $suffix = Str::lower(Str::random(8));
        $organization = Organization::query()->create(['name' => 'Admin Metrics '.$suffix, 'slug' => 'admin-metrics-'.$suffix, 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Vendor', 'contract_name' => 'Annual', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000.50, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active']);
        $monthlyCalculator = app(ContractMonthlyCostCalculator::class);
        $expectedMonthlyJpy = $monthlyCalculator->currencyUnitsFromCents($monthlyCalculator->monthlyTotalInCents(
            Contract::query()->where('status', 'active')->where('currency', 'JPY')->get(['amount', 'billing_cycle', 'currency']),
            'JPY',
        ));

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()->assertSee(number_format($expectedMonthlyJpy));
    }
}
