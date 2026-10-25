<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\ExecuteImportBatch;
use App\Jobs\PrepareImportRetryBatch;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ImpactMetricSnapshot;
use App\Models\ImportBatch;
use App\Models\ImportReviewCandidate;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ImpactReportMetricsService;
use App\Services\ImportBatchExecutionService;
use App\Services\PrepareImportRetryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportRetryTest extends TestCase
{
    public function test_failed_batch_is_cloned_for_retry_without_changing_history(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.async_threshold_rows' => 1, 'opsdeck.import.preview_insert_chunk_size' => 1]);
        Storage::fake(config('opsdeck.import.disk'));
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'retry-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Retry '.$suffix, 'slug' => 'retry-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Operator, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $sourcePath = 'imports/'.$organization->id.'/failed-import.csv';
        $email = 'alice-'.$suffix.'@example.test';
        $sourceContents = "name,email\nAlice,$email\nAlice duplicate,$email\nExcluded,excluded-$suffix@example.test\n";
        Storage::disk(config('opsdeck.import.disk'))->put($sourcePath, $sourceContents);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed-import', 'source_file_path' => $sourcePath, 'source_storage_disk' => config('opsdeck.import.disk'), 'source_file_size_bytes' => strlen($sourceContents), 'headers_json' => ['name', 'email'], 'status' => 'failed', 'total_rows' => 3, 'preview_new_rows' => 2, 'preview_confirmation_rows' => 1, 'error_rows' => 2]);
        $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 2, 'target_type' => 'employees', 'action' => 'error', 'errors_json' => ['row' => 'previous preview result'], 'is_selected' => false]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 2, 'target_type' => 'employees', 'action' => 'new_candidate', 'after_json' => ['name' => 'Alice', 'email' => $email], 'is_selected' => true]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 3, 'target_type' => 'employees', 'action' => 'confirmation_required', 'after_json' => ['name' => 'Alice duplicate', 'email' => $email], 'is_selected' => true]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 4, 'target_type' => 'employees', 'action' => 'new_candidate', 'after_json' => ['name' => 'Excluded', 'email' => 'excluded-'.$suffix.'@example.test'], 'is_selected' => false]);
        ImportReviewCandidate::query()->create([
            'import_batch_id' => $batch->id,
            'candidate_type' => 'duplicate',
            'source_row_numbers_json' => [2, 3],
            'confidence' => 0.94,
            'details_json' => ['target_type' => 'employees', 'key_hash' => hash('sha256', 'employee:'.$email)],
            'status' => 'accepted',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        Queue::fake();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.retry', $batch), ['confirm' => '1'])->assertRedirect();

        $retryBatch = ImportBatch::query()->where('id', '>', $batch->id)->latest('id')->firstOrFail();
        $this->assertSame('retrying', $batch->refresh()->status);
        $this->assertSame('retrying', $retryBatch->status);
        $this->assertSame($batch->source_storage_disk, $retryBatch->source_storage_disk);
        $this->assertSame($batch->source_file_size_bytes, $retryBatch->source_file_size_bytes);
        Queue::assertPushedTimes(PrepareImportRetryBatch::class, 1);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $batch), ['confirm' => '1'])
            ->assertUnprocessable();
        Queue::assertPushedTimes(PrepareImportRetryBatch::class, 1);

        (new PrepareImportRetryBatch($batch->id, $retryBatch->id, $user->id))->handle(app(PrepareImportRetryService::class));

        $this->assertSame('failed', $batch->refresh()->status);
        $this->assertSame('ready', $retryBatch->refresh()->status);
        $this->assertSame(3, $retryBatch->total_rows);
        $this->assertSame(3, $retryBatch->rowResults()->count());
        $this->assertSame('employees', $retryBatch->mappings()->firstOrFail()->target_type);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $retryBatch->id, 'source_row_number' => 2, 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $retryBatch->id, 'source_row_number' => 3, 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $retryBatch->id, 'source_row_number' => 4, 'is_selected' => false]);
        $this->assertDatabaseHas('import_review_candidates', ['import_batch_id' => $retryBatch->id, 'status' => 'accepted']);
        $this->assertSame('confirmation_required', $retryBatch->rowResults()->where('source_row_number', 3)->firstOrFail()->action);

        config(['opsdeck.import.async_threshold_rows' => PHP_INT_MAX]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $retryBatch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $retryBatch));

        $this->assertSame('completed', $retryBatch->refresh()->status);
        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $retryBatch->id, 'source_row_number' => 3, 'action' => 'merged']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $retryBatch->id, 'source_row_number' => 4, 'action' => 'skipped_selected']);
        $this->assertSame('failed', $batch->refresh()->status);
    }

    public function test_retry_preparation_failure_restores_the_original_failure_state_without_exposing_exception_text(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'retry-failure-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Retry Failure '.$suffix, 'slug' => 'retry-failure-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $sourceBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed-source', 'status' => 'retrying']);
        $retryBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed-retry', 'status' => 'retrying']);

        (new PrepareImportRetryBatch($sourceBatch->id, $retryBatch->id, $user->id))->failed(new \RuntimeException('secret@example.test stack trace'));

        $this->assertSame('failed', $sourceBatch->refresh()->status);
        $this->assertSame('failed', $retryBatch->refresh()->status);
        $this->assertNull($retryBatch->failure_message);
        $this->assertNotNull($retryBatch->completed_at);
    }

    public function test_retry_job_rechecks_actor_membership_before_copying_import_history(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'revoked-retry-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Revoked Retry '.$suffix, 'slug' => 'revoked-retry-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $sourceBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'revoked-source', 'status' => 'retrying']);
        $retryBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'revoked-retry', 'status' => 'retrying']);
        $sourceBatch->mappings()->create(['target_type' => 'employees', 'column_map' => ['name' => 'name'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);
        ImportRowResult::query()->create(['import_batch_id' => $sourceBatch->id, 'source_row_number' => 2, 'target_type' => 'employees', 'action' => 'new_candidate', 'after_json' => ['name' => 'Private employee'], 'is_selected' => true]);
        $membership->update(['status' => MembershipStatus::Suspended]);

        try {
            (new PrepareImportRetryBatch($sourceBatch->id, $retryBatch->id, $user->id))->handle(app(PrepareImportRetryService::class));
            $this->fail('A queued retry must be rejected after the actor loses organization access.');
        } catch (AuthorizationException) {
            $this->assertSame('retrying', $sourceBatch->refresh()->status);
            $this->assertSame('retrying', $retryBatch->refresh()->status);
            $this->assertSame(0, $retryBatch->mappings()->count());
            $this->assertSame(0, $retryBatch->rowResults()->count());
        }
    }

    public function test_duplicate_queued_import_job_does_not_repeat_a_completed_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'duplicate-job-'.$suffix.'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Duplicate job '.$suffix,
            'slug' => 'duplicate-job-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $csv = "name,email\nQueued Employee,queued-".$suffix."@example.test\n";
        $path = 'imports/'.$organization->id.'/duplicate-job.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, $csv);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'duplicate-job.csv',
            'source_file_path' => $path,
            'source_storage_disk' => config('opsdeck.import.disk'),
            'source_file_size_bytes' => strlen($csv),
            'source_file_sha256' => hash('sha256', $csv),
            'headers_json' => ['name', 'email'],
            'status' => 'queued',
            'total_rows' => 1,
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $job = new ExecuteImportBatch((int) $batch->id, (int) $user->id);
        $executionService = app(ImportBatchExecutionService::class);

        $job->handle($executionService);
        $job->handle($executionService);

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(1, $batch->rowResults()->count());
        $this->assertSame(1, AuditLog::query()->where('entity_type', ImportBatch::class)->where('entity_id', $batch->id)->where('action', AuditLog::ImportBatchExecuted)->count());
    }

    public function test_queued_job_failure_rolls_back_imported_rows_and_marks_batch_failed(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.release_features.impact_report' => true]);
        Storage::fake(config('opsdeck.import.disk'));
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'failed-job-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Failed job '.$suffix, 'slug' => 'failed-job-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Operator, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $path = 'imports/'.$organization->id.'/queued-employees.csv';
        $email = 'queued-failure-'.$suffix.'@example.test';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nQueued Employee,$email\nExcluded Employee,excluded-$suffix@example.test\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'queued-employees',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email'],
            'status' => 'queued',
            'total_rows' => 2,
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 2, 'target_type' => 'employees', 'action' => 'new_candidate', 'is_selected' => true]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 3, 'target_type' => 'employees', 'action' => 'new_candidate', 'is_selected' => false]);
        $this->app->instance(ImpactReportMetricsService::class, new class extends ImpactReportMetricsService
        {
            public function capture(Organization $organization, ImportBatch $importBatch, string $stage): ImpactMetricSnapshot
            {
                if ($stage === 'import_after') {
                    throw new \RuntimeException('Simulated post-import snapshot failure.');
                }

                return parent::capture($organization, $importBatch, $stage);
            }
        });

        $job = new ExecuteImportBatch($batch->id, $user->id);
        try {
            $job->handle(app(ImportBatchExecutionService::class));
            $this->fail('The queued import should fail when its final snapshot cannot be saved.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Import batch execution failed.', $exception->getMessage());
            $job->failed($exception);
        }

        $this->assertSame('failed', $batch->refresh()->status);
        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(2, $batch->rowResults()->count());
        $this->assertSame('new_candidate', $batch->rowResults()->where('source_row_number', 2)->firstOrFail()->action);
        $this->assertSame(1, ImpactMetricSnapshot::query()->where('import_batch_id', $batch->id)->where('stage', 'import_before')->count());
        $this->assertSame(0, ImpactMetricSnapshot::query()->where('import_batch_id', $batch->id)->where('stage', 'import_after')->count());
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $batch->id, 'action' => AuditLog::ImportBatchExecuted]);
    }

    public function test_failed_callback_marks_a_batch_failed_when_worker_stops_before_execution(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'worker-stop-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Worker stop '.$suffix, 'slug' => 'worker-stop-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'stopped-worker', 'status' => 'queued']);

        (new ExecuteImportBatch($batch->id, $user->id))->failed(new \RuntimeException('Worker stopped before handling the import.'));

        $this->assertSame('failed', $batch->refresh()->status);
        $this->assertNotNull($batch->completed_at);
    }
}
