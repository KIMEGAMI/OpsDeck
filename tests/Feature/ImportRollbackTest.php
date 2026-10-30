<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\RollbackImportBatch as RollbackImportBatchJob;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ImpactMetricSnapshot;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ImportBatchExecutionService;
use App\Services\ImportBatchRollbackService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ImportRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_synchronous_rollback_records_the_user_who_cancelled_the_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.release_features.impact_report' => true]);
        config(['opsdeck.import.async_threshold_rows' => PHP_INT_MAX]);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member();
        $email = 'sync-rollback-'.Str::uuid().'@example.test';
        $path = 'imports/'.$organization->id.'/sync-rollback.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nSync Rollback,$email\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Sync rollback',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email'],
            'status' => 'ready',
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        app(ImportBatchExecutionService::class)->execute((int) $batch->id, (int) $user->id, 'ready');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.rollback', $batch->refresh()), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $batch->id)->where('action', AuditLog::ImportBatchRolledBack)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['status' => 'cancelled'], $auditLog->after_json);
        $this->assertSame('データ取込を取り消し', $auditLog->actionLabel());
        $this->assertStringNotContainsString($email, json_encode($auditLog->toArray()));
    }

    public function test_completed_employee_import_can_be_safely_rolled_back(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.async_threshold_rows' => PHP_INT_MAX, 'opsdeck.import.preview_insert_chunk_size' => 1]);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member();
        $suffix = Str::random(8);
        $emails = [
            strtolower('rollback-a-'.$suffix.'@example.test'),
            strtolower('rollback-b-'.$suffix.'@example.test'),
            strtolower('rollback-c-'.$suffix.'@example.test'),
        ];
        $path = 'imports/'.$organization->id.'/employees.csv';
        $rows = array_map(static fn (string $email): string => 'Rollback User,'.$email, $emails);
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\n".implode("\n", $rows)."\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'employees', 'source_file_path' => $path, 'headers_json' => ['name', 'email'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();
        $employeeIds = Employee::query()->where('organization_id', $organization->id)->whereIn('email', $emails)->pluck('id', 'email');
        $this->assertCount(count($emails), $employeeIds);

        config(['opsdeck.import.async_threshold_rows' => 1]);
        Queue::fake();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.rollback', $batch->refresh()), ['confirm' => '1'])->assertRedirect(route('imports.show', $batch));
        $this->assertSame('rolling_back', $batch->refresh()->status);
        Queue::assertPushed(RollbackImportBatchJob::class, 1);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.rollback', $batch), ['confirm' => '1'])->assertStatus(422);

        (new RollbackImportBatchJob((int) $batch->id, (int) $user->id))->handle(app(ImportBatchRollbackService::class));

        foreach ($emails as $email) {
            $this->assertDatabaseMissing('employees', ['email' => $email]);
        }
        $this->assertSame('cancelled', $batch->refresh()->status);
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $batch->id)->where('action', AuditLog::ImportBatchRolledBack)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['status' => 'cancelled'], $auditLog->after_json);
        $rollbackSnapshot = ImpactMetricSnapshot::query()->where('import_batch_id', $batch->id)->where('stage', 'rollback_after')->firstOrFail();
        $this->assertSame(0, $rollbackSnapshot->metrics_json['employees']);
    }

    public function test_queued_rollback_rechecks_actor_membership_before_mutating_imported_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $email = 'revoked-rollback-'.Str::uuid().'@example.test';
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Imported Employee',
            'email' => $email,
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'status' => 'rolling_back',
            'executed_by' => $user->id,
        ]);
        $batch->rowResults()->create([
            'source_row_number' => 2,
            'target_type' => 'employees',
            'target_id' => $employee->id,
            'action' => 'inserted',
            'after_json' => [
                'name' => 'Imported Employee',
                'email' => $email,
                'employment_status' => config('opsdeck.employee_status.active'),
            ],
        ]);
        $user->organizationMemberships()->update(['status' => MembershipStatus::Suspended->value]);
        $job = new RollbackImportBatchJob((int) $batch->id, (int) $user->id);
        $authorizationFailure = null;

        try {
            $job->handle(app(ImportBatchRollbackService::class));
        } catch (AuthorizationException $exception) {
            $authorizationFailure = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $authorizationFailure);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'email' => $email]);
        $this->assertSame('rolling_back', $batch->refresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $batch->id, 'action' => AuditLog::ImportBatchRolledBack]);

        $job->failed($authorizationFailure);

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'email' => $email]);
    }

    public function test_employee_update_rollback_restores_imported_fields_and_preserves_unrelated_changes(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member();
        $suffix = Str::lower(Str::random(8));
        $email = 'rollback-update-'.$suffix.'@example.test';
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Before Import',
            'email' => $email,
            'employment_status' => config('opsdeck.employee_status.active'),
            'memo' => 'Original memo',
        ]);
        $path = 'imports/'.$organization->id.'/employee-update.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nAfter Import,$email\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Employee update',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email'],
            'status' => 'ready',
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        app(ImportBatchExecutionService::class)->execute((int) $batch->id, (int) $user->id, 'ready');

        $this->assertSame('After Import', $employee->refresh()->name);
        $this->assertSame('updated', $batch->rowResults()->sole()->action);

        $employee->update(['memo' => 'Changed after import']);
        $batch->update(['status' => 'rolling_back']);
        app(ImportBatchRollbackService::class)->rollback((int) $batch->id, (int) $user->id);

        $this->assertSame('cancelled', $batch->refresh()->status);
        $this->assertSame('Before Import', $employee->refresh()->name);
        $this->assertSame('Changed after import', $employee->memo);
    }

    public function test_unsafe_synchronous_rollback_returns_to_history_with_a_clear_message(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.async_threshold_rows' => PHP_INT_MAX]);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member();
        $email = 'rollback-conflict-'.Str::uuid().'@example.test';
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Before Import',
            'email' => $email,
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $path = 'imports/'.$organization->id.'/employee-conflict.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nAfter Import,$email\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Employee conflict',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email'],
            'status' => 'ready',
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        app(ImportBatchExecutionService::class)->execute((int) $batch->id, (int) $user->id, 'ready');
        $employee->update(['name' => 'Changed after Import']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('imports.show', $batch))
            ->followingRedirects()
            ->post(route('imports.rollback', $batch), ['confirm' => '1'])
            ->assertOk()
            ->assertSee('Import後に変更されたデータがあるため、安全な取消を中止しました。')
            ->assertSee('この取込を取り消す');

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame('Changed after Import', $employee->refresh()->name);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $batch->id, 'action' => AuditLog::ImportBatchRolledBack]);
    }

    public function test_asset_assignment_import_rollback_restores_asset_only_when_its_state_is_unchanged(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member();
        $asset = Asset::query()->create([
            'organization_id' => $organization->id,
            'asset_number' => 'ROLLBACK-ASSET-001',
            'asset_type' => 'pc',
            'status' => config('opsdeck.asset_status.in_stock'),
        ]);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Rollback Employee',
            'email' => 'rollback-asset-owner@example.test',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $path = 'imports/'.$organization->id.'/asset-assignment-rollback.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "asset_number,employee_email,assigned_at\nROLLBACK-ASSET-001,rollback-asset-owner@example.test,2026-10-01\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Asset assignment rollback',
            'source_file_path' => $path,
            'headers_json' => ['asset_number', 'employee_email', 'assigned_at'],
            'status' => 'ready',
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'asset_assignments',
            'column_map' => ['asset_number' => 'asset_number', 'employee_email' => 'employee_email', 'assigned_at' => 'assigned_at'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        app(ImportBatchExecutionService::class)->execute($batch->id, (int) $user->id, 'ready');
        $assignment = AssetAssignment::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame($asset->id, $assignment->asset_id);
        $this->assertSame($employee->id, $assignment->employee_id);
        $this->assertSame(config('opsdeck.asset_status.assigned'), $asset->refresh()->status);

        $batch->update(['status' => 'rolling_back']);
        $asset->update(['status' => 'lost']);
        try {
            app(ImportBatchRollbackService::class)->rollback((int) $batch->id, (int) $user->id);
            $this->fail('Rollback must stop when the related asset was changed after import.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('rolling_back', $batch->refresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $batch->id, 'action' => AuditLog::ImportBatchRolledBack]);
        $this->assertDatabaseHas('asset_assignments', ['id' => $assignment->id, 'asset_id' => $asset->id]);
        $this->assertSame('lost', $asset->refresh()->status);

        $asset->update(['status' => config('opsdeck.asset_status.assigned')]);
        app(ImportBatchRollbackService::class)->rollback((int) $batch->id, (int) $user->id);

        $this->assertSame('cancelled', $batch->refresh()->status);
        $this->assertDatabaseMissing('asset_assignments', ['id' => $assignment->id]);
        $this->assertSame(config('opsdeck.asset_status.in_stock'), $asset->refresh()->status);
    }

    public function test_failed_background_rollback_restores_completed_status_with_safe_message(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'status' => 'rolling_back',
            'failure_message' => null,
        ]);

        (new RollbackImportBatchJob((int) $batch->id, (int) $user->id))->failed(new \RuntimeException('sensitive internal failure details'));

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame('Importの取消を完了できませんでした。現在のデータを確認してから再試行してください。', $batch->failure_message);
        $this->assertStringNotContainsString('sensitive', $batch->failure_message);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'rollback-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Operator, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
