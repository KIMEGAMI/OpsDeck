<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\RollbackImportBatch as RollbackImportBatchJob;
use App\Models\Employee;
use App\Models\ImpactMetricSnapshot;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ImportBatchRollbackService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportRollbackTest extends TestCase
{
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

        (new RollbackImportBatchJob((int) $batch->id))->handle(app(ImportBatchRollbackService::class));

        foreach ($emails as $email) {
            $this->assertDatabaseMissing('employees', ['email' => $email]);
        }
        $this->assertSame('cancelled', $batch->refresh()->status);
        $rollbackSnapshot = ImpactMetricSnapshot::query()->where('import_batch_id', $batch->id)->where('stage', 'rollback_after')->firstOrFail();
        $this->assertSame(0, $rollbackSnapshot->metrics_json['employees']);
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

        (new RollbackImportBatchJob((int) $batch->id))->failed(new \RuntimeException('sensitive internal failure details'));

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
