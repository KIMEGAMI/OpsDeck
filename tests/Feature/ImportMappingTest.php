<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\ExecuteImportBatch;
use App\Jobs\PrepareImportMappingAnalysis;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportProfile;
use App\Models\ImportReviewCandidate;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireQuestion;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Models\User;
use App\Services\Import\ImportMappingAnalysisService;
use App\Services\Import\ImportNormalizationSuggestionService;
use App\Services\Import\ImportPreviewPreparationService;
use App\Services\Import\ImportProfileService;
use App\Services\ImportBatchExecutionService;
use App\Services\ImportBatchRollbackService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ImportMappingTest extends TestCase
{
    public function test_operator_can_execute_a_shift_jis_csv_import_without_mojibake(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/employees-shift-jis.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, mb_convert_encoding("name,email\r\n山田太郎,shift-jis@example.test\r\n", 'SJIS-win', 'UTF-8'));
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Windows Excel CSV',
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

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'organization_id' => $organization->id,
            'name' => '山田太郎',
            'email' => 'shift-jis@example.test',
        ]);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $batch->id,
            'source_row_number' => 2,
            'action' => 'inserted',
        ]);
    }

    public function test_operator_can_execute_a_questionnaire_answer_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $questionnaire = Questionnaire::query()->create(['organization_id' => $organization->id, 'title' => 'ISMS', 'status' => 'draft', 'created_by' => $user->id]);
        QuestionnaireQuestion::query()->create(['questionnaire_id' => $questionnaire->id, 'question_text' => 'MFAを導入していますか？', 'answer_type' => 'text', 'sort_order' => 1]);
        $path = 'imports/'.$organization->id.'/answers.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "questionnaire_title,question_text,answer_text,source_type\nISMS,MFAを導入していますか？,はい,FACT\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'answers', 'source_file_path' => $path, 'headers_json' => ['questionnaire_title', 'question_text', 'answer_text', 'source_type'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'questionnaire_answers', 'column_map' => ['questionnaire_title' => 'questionnaire_title', 'question_text' => 'question_text', 'answer_text' => 'answer_text', 'source_type' => 'source_type'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();

        $this->assertDatabaseHas('questionnaire_answers', ['question_id' => $questionnaire->questions()->first()->id, 'answer_status' => 'draft', 'source_type' => 'FACT']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
    }

    public function test_operator_can_execute_a_saas_assignment_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $service = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Slack', 'status' => 'active']);
        $email = strtolower('assignment-'.Str::random(8).'@example.test');
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '担当者', 'email' => $email, 'employment_status' => 'active']);
        $path = 'imports/'.$organization->id.'/assignments.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "service_name,employee_email,account_identifier,status,mfa_status\nSlack,$email,account-001,active,enabled\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'assignments', 'source_file_path' => $path, 'headers_json' => ['service_name', 'employee_email', 'account_identifier', 'status', 'mfa_status'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'saas_assignments', 'column_map' => ['service_name' => 'service_name', 'employee_email' => 'employee_email', 'account_identifier' => 'account_identifier', 'status' => 'status', 'mfa_status' => 'mfa_status'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();

        $this->assertDatabaseHas('saas_assignments', ['organization_id' => $organization->id, 'saas_service_id' => $service->id, 'account_identifier' => 'account-001']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
    }

    public function test_saas_assignment_preview_matches_existing_records_and_import_updates_them(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $service = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Slack', 'status' => 'active']);
        $employee = Employee::query()->create(['organization_id' => $organization->id, 'name' => '担当者', 'email' => 'assignment-owner@example.test', 'employment_status' => 'active']);
        $assignment = SaasAssignment::query()->create([
            'organization_id' => $organization->id,
            'saas_service_id' => $service->id,
            'employee_id' => $employee->id,
            'account_identifier' => 'account-001',
            'license_name' => 'Old license',
            'status' => 'active',
            'mfa_status' => 'unknown',
        ]);
        $path = 'imports/'.$organization->id.'/existing-assignment.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "service_name,employee_email,account_identifier,license_name,status,mfa_status\nslack,ASSIGNMENT-OWNER@EXAMPLE.TEST,account-001,Premium,active,enabled\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Existing SaaS assignment',
            'source_file_path' => $path,
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);
        app(ImportPreviewPreparationService::class)->prepare($batch->id);
        $mapping = $batch->mappings()->create([
            'target_type' => 'saas_assignments',
            'column_map' => ['service_name' => 'service_name', 'employee_email' => 'employee_email', 'account_identifier' => 'account_identifier', 'license_name' => 'license_name', 'status' => 'status', 'mfa_status' => 'mfa_status'],
            'normalization_rules' => [],
            'status' => 'analyzing',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id, 'status' => 'analyzing']);

        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->handle(app(ImportMappingAnalysisService::class));

        $this->assertSame(1, $batch->refresh()->preview_updated_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 2, 'action' => 'update_candidate']);

        app(ImportBatchExecutionService::class)->execute($batch->id, (int) $user->id, 'ready');

        $this->assertSame(1, $batch->refresh()->updated_rows);
        $this->assertSame(1, SaasAssignment::query()->where('organization_id', $organization->id)->count());
        $this->assertSame('Premium', $assignment->refresh()->license_name);
        $this->assertSame('enabled', $assignment->mfa_status);
    }

    public function test_saas_assignment_preview_rejects_references_from_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        SaasService::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Remote SaaS', 'status' => 'active']);
        Employee::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Remote owner', 'email' => 'remote-assignment@example.test', 'employment_status' => 'active']);
        $path = 'imports/'.$organization->id.'/foreign-assignment.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "service_name,employee_email,account_identifier,status,mfa_status\nRemote SaaS,remote-assignment@example.test,remote-001,active,enabled\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Foreign SaaS assignment references',
            'source_file_path' => $path,
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);
        app(ImportPreviewPreparationService::class)->prepare($batch->id);
        $mapping = $batch->mappings()->create([
            'target_type' => 'saas_assignments',
            'column_map' => ['service_name' => 'service_name', 'employee_email' => 'employee_email', 'account_identifier' => 'account_identifier', 'status' => 'status', 'mfa_status' => 'mfa_status'],
            'normalization_rules' => [],
            'status' => 'analyzing',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id, 'status' => 'analyzing']);

        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->handle(app(ImportMappingAnalysisService::class));

        $this->assertSame(1, $batch->refresh()->preview_error_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 2, 'action' => 'error']);
        $this->assertSame(0, SaasAssignment::query()->where('organization_id', $organization->id)->count());
    }

    public function test_operator_can_execute_evidence_metadata_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/evidence.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "title,evidence_type,valid_from,valid_until\nISMS方針,policy,2026-01-01,2026-12-31\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'evidence', 'source_file_path' => $path, 'headers_json' => ['title', 'evidence_type', 'valid_from', 'valid_until'], 'status' => 'ready', 'executed_by' => $user->id]);
        $batch->mappings()->create(['target_type' => 'evidence', 'column_map' => ['title' => 'title', 'evidence_type' => 'evidence_type', 'valid_from' => 'valid_from', 'valid_until' => 'valid_until'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();

        $this->assertDatabaseHas('evidence', ['organization_id' => $organization->id, 'title' => 'ISMS方針', 'storage_path' => 'metadata-only']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
    }

    public function test_evidence_date_values_are_normalized_and_invalid_dates_are_flagged_in_csv_preview(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/evidence-dates.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "title,evidence_type,valid_from,valid_until\nValid policy,policy,Jan 1 2026,Jan 1 2027\nInvalid policy,policy,not-a-date,Jan 1 2027\n");
        $headers = ['title', 'evidence_type', 'valid_from', 'valid_until'];
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'evidence-dates',
            'source_file_path' => $path,
            'headers_json' => $headers,
            'status' => 'preview',
        ]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 2, 'target_type' => 'unmapped', 'action' => 'preview']);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 3, 'target_type' => 'unmapped', 'action' => 'preview']);
        config()->set('opsdeck.import.preview_insert_chunk_size', 1);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'evidence',
                'column_map' => array_combine($headers, $headers),
            ])->assertRedirect();

        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $batch->id,
            'source_row_number' => 2,
            'action' => 'new_candidate',
        ]);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $batch->id,
            'source_row_number' => 3,
            'action' => 'error',
        ]);
        $validRow = ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 2)->firstOrFail();
        $invalidRow = ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 3)->firstOrFail();
        $this->assertSame('2026-01-01', $validRow->after_json['valid_from']);
        $this->assertSame('2027-01-01', $validRow->after_json['valid_until']);
        $this->assertSame('date fields are invalid or out of order', $invalidRow->errors_json['row']);
        $this->assertSame(1, $batch->refresh()->preview_new_rows);
        $this->assertSame(1, $batch->preview_error_rows);
    }

    public function test_operator_can_execute_a_contract_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/contracts.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "vendor_name,contract_name,billing_cycle,amount,currency,status\nAcme,Support,annual,\"120,000\",JPY,active\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'contracts', 'source_file_path' => $path, 'headers_json' => ['vendor_name', 'contract_name', 'billing_cycle', 'amount', 'currency', 'status'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'contracts', 'column_map' => ['vendor_name' => 'vendor_name', 'contract_name' => 'contract_name', 'billing_cycle' => 'billing_cycle', 'amount' => 'amount', 'currency' => 'currency', 'status' => 'status'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();

        $this->assertDatabaseHas('contracts', ['organization_id' => $organization->id, 'contract_name' => 'Support', 'amount' => 120000]);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
    }

    public function test_contract_import_resolves_organization_scoped_relations_and_rollback_restores_changes(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $service = SaasService::query()->create(['organization_id' => $organization->id, 'name' => 'Office Suite', 'status' => 'active']);
        $owner = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Contract owner',
            'email' => 'Owner-'.Str::random(8).'@example.test',
            'employment_status' => 'active',
        ]);
        $existingContract = Contract::query()->create([
            'organization_id' => $organization->id,
            'saas_service_id' => $service->id,
            'owner_employee_id' => $owner->id,
            'vendor_name' => 'Acme',
            'contract_name' => 'Existing support',
            'contract_type' => 'software',
            'billing_cycle' => 'annual',
            'amount' => 100,
            'currency' => 'JPY',
            'auto_renew' => false,
            'status' => 'active',
        ]);
        $ownerEmail = strtolower((string) $owner->email);
        $path = 'imports/'.$organization->id.'/contract-relations.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "vendor_name,contract_name,billing_cycle,amount,currency,status,service_name,employee_email\nAcme,New support,annual,120000,JPY,active,office suite,{$ownerEmail}\nAcme,Existing support,annual,200,JPY,active,,\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Contract relations',
            'source_file_path' => $path,
            'headers_json' => ['vendor_name', 'contract_name', 'billing_cycle', 'amount', 'currency', 'status', 'service_name', 'employee_email'],
            'status' => 'ready',
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'contracts',
            'column_map' => ['vendor_name' => 'vendor_name', 'contract_name' => 'contract_name', 'billing_cycle' => 'billing_cycle', 'amount' => 'amount', 'currency' => 'currency', 'status' => 'status', 'service_name' => 'service_name', 'employee_email' => 'employee_email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        app(ImportBatchExecutionService::class)->execute($batch->id, (int) $user->id, 'ready');

        $newContract = Contract::query()->where('organization_id', $organization->id)->where('contract_name', 'New support')->firstOrFail();
        $this->assertSame($service->id, $newContract->saas_service_id);
        $this->assertSame($owner->id, $newContract->owner_employee_id);
        $this->assertSame($service->id, $existingContract->refresh()->saas_service_id);
        $this->assertSame($owner->id, $existingContract->owner_employee_id);
        $this->assertSame('200.00', $existingContract->amount);
        $this->assertSame('software', $existingContract->contract_type);
        $this->assertFalse($existingContract->auto_renew);
        $this->assertSame(1, $batch->refresh()->inserted_rows);
        $this->assertSame(1, $batch->updated_rows);

        $batch->update(['status' => 'rolling_back']);
        app(ImportBatchRollbackService::class)->rollback($batch->id);

        $this->assertSame('cancelled', $batch->refresh()->status);
        $this->assertDatabaseMissing('contracts', ['id' => $newContract->id]);
        $this->assertSame('100.00', $existingContract->refresh()->amount);
        $this->assertSame($service->id, $existingContract->saas_service_id);
        $this->assertSame($owner->id, $existingContract->owner_employee_id);
    }

    public function test_rollback_does_not_delete_an_inserted_record_edited_after_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $email = 'rollback-protection-'.Str::uuid().'@example.test';
        $path = 'imports/'.$organization->id.'/rollback-protection.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nImported employee,{$email}\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Rollback protection',
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

        app(ImportBatchExecutionService::class)->execute($batch->id, (int) $user->id, 'ready');
        $employee = Employee::query()->where('organization_id', $organization->id)->where('email', $email)->firstOrFail();
        $afterSnapshot = $batch->rowResults()->where('target_id', $employee->id)->firstOrFail()->after_json;
        $this->assertArrayHasKey('memo', $afterSnapshot);
        $this->assertNull($afterSnapshot['memo']);
        $employee->update(['memo' => 'Added after import']);
        $batch->update(['status' => 'rolling_back']);

        try {
            app(ImportBatchRollbackService::class)->rollback($batch->id);
            $this->fail('A post-import edit should prevent deleting the inserted record.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('Added after import', $employee->refresh()->memo);
        $this->assertSame('rolling_back', $batch->refresh()->status);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'action' => 'inserted']);
    }

    public function test_contract_import_preview_rejects_relations_that_exist_only_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        SaasService::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Remote SaaS', 'status' => 'active']);
        Employee::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Remote owner', 'email' => 'remote-owner@example.test', 'employment_status' => 'active']);
        $path = 'imports/'.$organization->id.'/foreign-contract-relations.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "vendor_name,contract_name,billing_cycle,amount,currency,status,service_name,employee_email\nAcme,Remote support,annual,120000,JPY,active,Remote SaaS,remote-owner@example.test\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Foreign contract relations',
            'source_file_path' => $path,
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);
        app(ImportPreviewPreparationService::class)->prepare($batch->id);
        $mapping = $batch->mappings()->create([
            'target_type' => 'contracts',
            'column_map' => ['vendor_name' => 'vendor_name', 'contract_name' => 'contract_name', 'billing_cycle' => 'billing_cycle', 'amount' => 'amount', 'currency' => 'currency', 'status' => 'status', 'service_name' => 'service_name', 'employee_email' => 'employee_email'],
            'normalization_rules' => [],
            'status' => 'analyzing',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id, 'status' => 'analyzing']);

        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->handle(app(ImportMappingAnalysisService::class));

        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame(1, $batch->preview_error_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 2, 'action' => 'error']);
        $this->assertDatabaseMissing('contracts', ['organization_id' => $organization->id, 'contract_name' => 'Remote support']);
    }

    public function test_contract_import_preview_flags_rows_that_have_invalid_amount_cycle_or_status(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/contract-validation.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "vendor_name,contract_name,billing_cycle,amount,currency,status\nAcme,Negative annual,annual,-0.01,JPY,active\nAcme,Excess decimals,annual,1.234,JPY,active\nAcme,Invalid cycle,biweekly,10.00,JPY,active\nAcme,Invalid status,annual,10.00,JPY,unknown\nAcme,Valid contract,annual,10.00,JPY,active\n");
        $headers = ['vendor_name', 'contract_name', 'billing_cycle', 'amount', 'currency', 'status'];
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'contract-validation',
            'source_file_path' => $path,
            'headers_json' => $headers,
            'status' => 'preview',
        ]);
        foreach (range(2, 6) as $sourceRowNumber) {
            ImportRowResult::query()->create([
                'import_batch_id' => $batch->id,
                'source_row_number' => $sourceRowNumber,
                'target_type' => 'unmapped',
                'action' => 'preview',
            ]);
        }

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'contracts',
                'column_map' => array_combine($headers, $headers),
            ])->assertRedirect();

        $this->assertSame(1, $batch->refresh()->preview_new_rows);
        $this->assertSame(4, $batch->preview_error_rows);
        $this->assertSame('error', ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 2)->value('action'));
        $this->assertSame('error', ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 3)->value('action'));
        $this->assertSame('error', ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 4)->value('action'));
        $this->assertSame('error', ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 5)->value('action'));
        $this->assertSame('new_candidate', ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 6)->value('action'));
    }

    public function test_operator_can_preview_execute_and_rollback_a_security_control_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/security-controls.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "control_code,title,category,description,status,last_reviewed_at,next_review_at,review_frequency\nAC-01,Access policy,access,Review access annually,partial,2026/01/01,2027/01/01,annual\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'security-controls',
            'source_file_path' => $path,
            'headers_json' => ['control_code', 'title', 'category', 'description', 'status', 'last_reviewed_at', 'next_review_at', 'review_frequency'],
            'status' => 'preview',
            'executed_by' => $user->id,
        ]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 2, 'target_type' => 'unmapped', 'action' => 'preview']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'security_controls',
                'column_map' => array_combine($batch->headers_json, $batch->headers_json),
            ])->assertRedirect();

        $row = ImportRowResult::query()->where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('2026-01-01', $row->after_json['last_reviewed_at']);
        $this->assertSame(1, $batch->refresh()->preview_new_rows);
        $this->assertSame(0, $batch->preview_error_rows);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('管理項目コード')
            ->assertSee('セキュリティ管理項目データへの反映')
            ->assertSee('新規登録候補')
            ->assertSee('取込予定の内容')
            ->assertDontSee('&quot;control_code&quot;');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.rows.selection', $batch), ['visible_row_numbers' => [2], 'selected_row_numbers' => [2]])
            ->assertRedirect();
        $this->assertSame('ready', $batch->refresh()->status);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $control = SecurityControl::query()->where('organization_id', $organization->id)->where('control_code', 'AC-01')->firstOrFail();
        $this->assertSame('2026-01-01', $control->last_reviewed_at?->toDateString());
        $this->assertSame('2027-01-01', $control->next_review_at?->toDateString());
        $this->assertSame('completed', $batch->refresh()->status);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.rollback', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseMissing('security_controls', ['id' => $control->id]);
        $this->assertSame('cancelled', $batch->refresh()->status);
    }

    public function test_security_control_import_resolves_owner_within_organization_and_preserves_blank_owner(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $currentOwner = Employee::query()->create(['organization_id' => $organization->id, 'name' => 'Current owner', 'email' => 'current-security-owner@example.test', 'employment_status' => 'active']);
        $newOwner = Employee::query()->create(['organization_id' => $organization->id, 'name' => 'New owner', 'email' => 'new-security-owner@example.test', 'employment_status' => 'active']);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => 'Ambiguous owner one', 'email' => 'duplicate-security-owner@example.test', 'employment_status' => 'active']);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => 'Ambiguous owner two', 'email' => 'duplicate-security-owner@example.test', 'employment_status' => 'active']);
        Employee::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Foreign owner', 'email' => 'foreign-security-owner@example.test', 'employment_status' => 'active']);
        $unchangedControl = SecurityControl::query()->create([
            'organization_id' => $organization->id,
            'control_code' => 'AC-OLD',
            'title' => 'Existing control',
            'category' => 'access',
            'description' => 'Existing description',
            'status' => 'partial',
            'owner_employee_id' => $currentOwner->id,
        ]);
        $changedControl = SecurityControl::query()->create([
            'organization_id' => $organization->id,
            'control_code' => 'AC-CHANGE',
            'title' => 'Control to reassign',
            'category' => 'access',
            'description' => 'Existing description',
            'status' => 'partial',
            'owner_employee_id' => $currentOwner->id,
        ]);
        $path = 'imports/'.$organization->id.'/security-control-owners.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "control_code,title,category,description,status,owner_email\nAC-NEW,New control,access,New description,partial,CURRENT-SECURITY-OWNER@EXAMPLE.TEST\nAC-OLD,Existing control,access,Existing description,partial,\nAC-CHANGE,Control to reassign,access,Existing description,partial,new-security-owner@example.test\nAC-FOREIGN,Foreign owner,access,Foreign owner row,partial,foreign-security-owner@example.test\nAC-AMBIGUOUS,Ambiguous owner,access,Ambiguous owner row,partial,duplicate-security-owner@example.test\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Security control owners',
            'source_file_path' => $path,
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);
        app(ImportPreviewPreparationService::class)->prepare($batch->id);
        $mapping = $batch->mappings()->create([
            'target_type' => 'security_controls',
            'column_map' => ['control_code' => 'control_code', 'title' => 'title', 'category' => 'category', 'description' => 'description', 'status' => 'status', 'owner_email' => 'owner_email'],
            'normalization_rules' => [],
            'status' => 'analyzing',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id, 'status' => 'analyzing']);

        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->handle(app(ImportMappingAnalysisService::class));

        $this->assertSame(1, $batch->refresh()->preview_new_rows);
        $this->assertSame(2, $batch->preview_updated_rows);
        $this->assertSame(2, $batch->preview_error_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 5, 'action' => 'error']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 6, 'action' => 'error']);
        $this->assertSame('ready', $batch->status);

        app(ImportBatchExecutionService::class)->execute($batch->id, (int) $user->id, 'ready');

        $newControl = SecurityControl::query()->where('organization_id', $organization->id)->where('control_code', 'AC-NEW')->firstOrFail();
        $this->assertSame($currentOwner->id, $newControl->owner_employee_id);
        $this->assertSame($currentOwner->id, $unchangedControl->refresh()->owner_employee_id);
        $this->assertSame($newOwner->id, $changedControl->refresh()->owner_employee_id);
        $this->assertDatabaseMissing('security_controls', ['organization_id' => $organization->id, 'control_code' => 'AC-FOREIGN']);
        $this->assertDatabaseMissing('security_controls', ['organization_id' => $organization->id, 'control_code' => 'AC-AMBIGUOUS']);
        $this->assertSame(2, $batch->refresh()->error_rows);

        $batch->update(['status' => 'rolling_back']);
        app(ImportBatchRollbackService::class)->rollback($batch->id);

        $this->assertSame($currentOwner->id, $changedControl->refresh()->owner_employee_id);
        $this->assertDatabaseMissing('security_controls', ['id' => $newControl->id]);
    }

    public function test_operator_can_execute_a_saas_service_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/saas.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,vendor,status\nSlack,Salesforce,active\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'saas', 'source_file_path' => $path, 'headers_json' => ['name', 'vendor', 'status'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'saas_services', 'column_map' => ['name' => 'name', 'vendor' => 'vendor', 'status' => 'status'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();

        $this->assertDatabaseHas('saas_services', ['organization_id' => $organization->id, 'name' => 'Slack', 'status' => 'active']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
    }

    public function test_operator_can_execute_an_asset_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/assets.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "asset_number,asset_type,status\nPC-001,pc,in_stock\nPC-001,pc,in_stock\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'assets', 'source_file_path' => $path, 'headers_json' => ['asset_number', 'asset_type', 'status'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'assets', 'column_map' => ['asset_number' => 'asset_number', 'asset_type' => 'asset_type', 'status' => 'status'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();

        $this->assertDatabaseHas('assets', ['organization_id' => $organization->id, 'asset_number' => 'PC-001', 'asset_type' => 'pc']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
        $this->assertSame(1, $batch->error_rows);
    }

    public function test_operator_can_execute_a_department_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/departments.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,code\n総務,GA\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'departments', 'source_file_path' => $path, 'headers_json' => ['name', 'code'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'departments', 'column_map' => ['name' => 'name', 'code' => 'code'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();

        $this->assertDatabaseHas('departments', ['organization_id' => $organization->id, 'name' => '総務', 'code' => 'GA']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
    }

    public function test_employee_import_preview_resolves_only_departments_in_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        Department::query()->create(['organization_id' => $organization->id, 'name' => 'General Affairs', 'code' => 'GA']);
        Department::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Sales', 'code' => 'SALES']);
        Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Existing employee',
            'email' => 'Existing-'.Str::random(8).'@example.test',
            'employment_status' => 'active',
        ]);

        $path = 'imports/'.$organization->id.'/employees-with-departments.csv';
        $existingEmail = (string) Employee::query()->where('organization_id', $organization->id)->value('email');
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email,department\nAlice,alice-".Str::random(8)."@example.test, GENERAL AFFAIRS \nBob,bob-".Str::random(8)."@example.test,Sales\nExisting employee,".strtolower($existingEmail).",general affairs\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Employees with department references',
            'source_file_path' => $path,
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);
        app(ImportPreviewPreparationService::class)->prepare($batch->id);
        $mapping = $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email', 'department' => 'department_name'],
            'normalization_rules' => [],
            'status' => 'analyzing',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id, 'status' => 'analyzing']);

        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->handle(app(ImportMappingAnalysisService::class));

        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame(1, $batch->preview_new_rows);
        $this->assertSame(1, $batch->preview_updated_rows);
        $this->assertSame(1, $batch->preview_error_rows);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $batch->id,
            'source_row_number' => 3,
            'action' => 'error',
        ]);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $batch->id,
            'source_row_number' => 4,
            'action' => 'update_candidate',
        ]);
    }

    public function test_employee_import_links_a_matching_department_and_blank_department_does_not_clear_existing_link(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $department = Department::query()->create(['organization_id' => $organization->id, 'name' => 'General Affairs', 'code' => 'GA']);
        $existingEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Existing employee',
            'email' => 'existing-'.Str::random(8).'@example.test',
            'department_id' => $department->id,
            'employment_status' => 'active',
        ]);
        $path = 'imports/'.$organization->id.'/employee-department-reference.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email,department\nNew employee,new-".Str::random(8)."@example.test,GENERAL AFFAIRS\nExisting employee,{$existingEmployee->email},\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Employee department reference',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email', 'department'],
            'status' => 'ready',
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email', 'department' => 'department_name'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        app(ImportBatchExecutionService::class)->execute($batch->id, (int) $user->id, 'ready');

        $newEmployee = Employee::query()->where('organization_id', $organization->id)->where('name', 'New employee')->firstOrFail();
        $this->assertSame($department->id, $newEmployee->department_id);
        $this->assertSame($department->id, $existingEmployee->refresh()->department_id);
        $this->assertSame(1, $batch->refresh()->inserted_rows);
        $this->assertSame(1, $batch->updated_rows);
    }

    public function test_operator_can_execute_a_ready_employee_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/employees.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nAlice,a-".Str::random(8)."@example.test\nBob,b-".Str::random(8)."@example.test\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'employees', 'source_file_path' => $path, 'headers_json' => ['name', 'email'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseHas('employees', ['organization_id' => $organization->id, 'name' => 'Alice']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(2, $batch->inserted_rows);
        $this->assertDatabaseHas('impact_metric_snapshots', ['import_batch_id' => $batch->id, 'stage' => 'import_before']);
        $this->assertDatabaseHas('impact_metric_snapshots', ['import_batch_id' => $batch->id, 'stage' => 'import_after']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertUnprocessable();

        $this->assertSame(2, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(2, ImportRowResult::query()->where('import_batch_id', $batch->id)->count());
    }

    public function test_employee_import_preserves_rfc_csv_quotes_and_backslashes(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $expectedName = 'Tokyo, "HQ" \\ branch';
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, ['name', 'email'], ',', '"', '');
        fputcsv($stream, [$expectedName, 'quoted-'.Str::uuid().'@example.test'], ',', '"', '');
        rewind($stream);
        $path = 'imports/'.$organization->id.'/quoted-employees.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, stream_get_contents($stream));
        fclose($stream);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'quoted-employees', 'source_file_path' => $path, 'headers_json' => ['name', 'email'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseHas('employees', ['organization_id' => $organization->id, 'name' => $expectedName]);
    }

    public function test_formula_safe_export_can_be_imported_without_persisting_guard_tab_and_is_guarded_again_on_export(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $formulaLikeName = '=HYPERLINK("https://example.test","open")';
        $email = 'formula-safe-'.Str::uuid().'@example.test';
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, ['name', 'email'], ',', '"', '');
        fputcsv($stream, ["\t{$formulaLikeName}", $email], ',', '"', '');
        rewind($stream);
        $path = 'imports/'.$organization->id.'/formula-safe-employees.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, stream_get_contents($stream));
        fclose($stream);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'formula-safe-employees', 'source_file_path' => $path, 'headers_json' => ['name', 'email'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));
        $this->assertDatabaseHas('employees', ['organization_id' => $organization->id, 'name' => $formulaLikeName, 'email' => $email]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('exports.download', 'employees'));
        $csvHandle = fopen('php://temp', 'w+b');
        fwrite($csvHandle, substr($response->streamedContent(), 3));
        rewind($csvHandle);
        fgetcsv($csvHandle, null, ',', '"', '');
        $row = fgetcsv($csvHandle, null, ',', '"', '');
        fclose($csvHandle);

        $this->assertSame("\t{$formulaLikeName}", $row[2]);
    }

    public function test_large_import_is_queued_and_repeated_jobs_do_not_duplicate_rows(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        Queue::fake();
        config(['opsdeck.import.async_threshold_rows' => 1]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $email = 'queued-'.Str::random(8).'@example.test';
        $path = 'imports/'.$organization->id.'/employees.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nQueued Employee,$email\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email'],
            'status' => 'ready',
            'total_rows' => 1,
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame('queued', $batch->refresh()->status);
        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());
        Queue::assertPushed(ExecuteImportBatch::class, fn (ExecuteImportBatch $job): bool => $job->importBatchId === $batch->id && $job->userId === $user->id);

        $job = new ExecuteImportBatch($batch->id, $user->id);
        $executionService = app(ImportBatchExecutionService::class);
        $job->handle($executionService);
        $job->handle($executionService);

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(1, ImportRowResult::query()->where('import_batch_id', $batch->id)->count());
        $auditLog = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('entity_type', ImportBatch::class)
            ->where('entity_id', $batch->id)
            ->where('action', AuditLog::ImportBatchExecuted)
            ->firstOrFail();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertEqualsCanonicalizing(['status' => 'queued', 'source_type' => 'csv', 'target_type' => 'employees', 'total_rows' => 1], $auditLog->before_json);
        $this->assertEqualsCanonicalizing(['status' => 'completed', 'inserted_rows' => 1, 'updated_rows' => 0, 'skipped_rows' => 0, 'error_rows' => 0], $auditLog->after_json);
        $this->assertStringNotContainsString($email, json_encode([$auditLog->before_json, $auditLog->after_json], JSON_THROW_ON_ERROR));
        $this->assertSame(1, AuditLog::query()->where('entity_type', ImportBatch::class)->where('entity_id', $batch->id)->where('action', AuditLog::ImportBatchExecuted)->count());
    }

    #[RunInSeparateProcess]
    public function test_execution_processes_the_configured_maximum_csv_rows_with_bounded_resources(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        config(['opsdeck.import.max_rows' => 20_000]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $rowCount = (int) config('opsdeck.import.max_rows');
        $path = 'imports/'.$organization->id.'/scale-departments.csv';
        $sourceStream = fopen('php://temp', 'w+b');
        $this->assertIsResource($sourceStream);
        fputcsv($sourceStream, ['name'], ',', '"', '');
        for ($rowNumber = 1; $rowNumber <= $rowCount; $rowNumber++) {
            fputcsv($sourceStream, ['Scale Department '.$rowNumber], ',', '"', '');
        }
        rewind($sourceStream);
        $this->assertTrue(Storage::disk(config('opsdeck.import.disk'))->put($path, $sourceStream));
        fclose($sourceStream);

        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'scale-departments',
            'source_file_path' => $path,
            'headers_json' => ['name'],
            'status' => 'ready',
            'total_rows' => $rowCount,
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'departments',
            'column_map' => ['name' => 'name'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $startedAt = hrtime(true);
        app(ImportBatchExecutionService::class)->execute($batch->id, $user->id, 'ready');
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $peakMemoryBytes = memory_get_peak_usage(true);
        $memoryLimit = $this->memoryLimitBytes((string) ini_get('memory_limit'));

        $this->assertLessThan((int) config('opsdeck.import.job_timeout_seconds'), $elapsedSeconds, 'Maximum-row import must complete within the configured worker timeout.');
        if ($memoryLimit !== null) {
            $this->assertLessThan((int) ($memoryLimit * 0.75), $peakMemoryBytes, 'Maximum-row import must leave at least 25% of PHP memory available.');
        }
        $this->assertSame($rowCount, $batch->refresh()->inserted_rows);
        $this->assertSame($rowCount, Department::query()->where('organization_id', $organization->id)->count());
        $this->assertSame($rowCount, ImportRowResult::query()->where('import_batch_id', $batch->id)->count());
    }

    public function test_operator_can_save_a_mapping_and_mark_batch_ready(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'employees',
            'headers_json' => ['氏名', 'メール'], 'status' => 'preview',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('imports.mapping', $batch))->assertOk()->assertSee('mappingSuggestions')->assertSee('name')->assertSee(route('help.recovery', 'ai'));

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.mapping.store', $batch), [
            'target_type' => 'employees',
            'column_map' => ['氏名' => 'name', 'メール' => 'email'],
        ])->assertRedirect(route('imports.show', $batch));

        $batch->refresh();
        $this->assertSame('ready', $batch->status);
        $this->assertNotNull($batch->mapping_id);
        $this->assertSame(['氏名' => 'name', 'メール' => 'email'], $batch->mappings()->firstOrFail()->column_map);
    }

    public function test_mapping_page_shows_normalization_suggestions_from_the_configured_sample(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/normalization-sample.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "amount\n\"¥1,000\"\n\"2,000\"\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Contract amounts',
            'source_file_path' => $path,
            'headers_json' => ['amount'],
            'status' => 'preview',
        ]);
        config()->set('opsdeck.import.normalization_sample_rows', 1);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai_target_type' => 'contracts']))
            ->assertSee('amount: ¥1,000 → 1000')
            ->assertSee('normalization_rules[amount]')
            ->assertDontSee('2,000');
    }

    public function test_normalization_rules_are_opt_in_strict_and_saved_with_reusable_profiles(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/normalization-opt-in.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "amount\n\"¥1,000\"\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Contract amounts',
            'source_file_path' => $path,
            'headers_json' => ['amount'],
            'status' => 'preview',
        ]);

        $normalizer = app(ImportNormalizationSuggestionService::class);
        $this->assertSame(['amount' => '¥1,000'], $normalizer->apply('contracts', ['amount' => '¥1,000'], []));
        $this->assertSame(['amount' => '1000'], $normalizer->apply('contracts', ['amount' => '¥1,000'], ['amount' => 'strip_amount_format']));
        $this->assertSame(['started_at' => '03/04/2026'], $normalizer->apply('contracts', ['started_at' => '03/04/2026'], ['started_at' => 'normalize_iso_date']));
        $this->assertSame(['started_at' => '2026-04-03'], $normalizer->apply('contracts', ['started_at' => '2026/4/3'], ['started_at' => 'normalize_iso_date']));
        $this->expectException(ValidationException::class);
        $normalizer->validateRules('contracts', ['name' => 'name'], ['name' => 'strip_amount_format']);
    }

    public function test_selected_normalization_rule_is_stored_on_mapping_and_profile_version(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/normalization-profile.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "amount\n\"¥1,000\"\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Contract amounts',
            'source_file_path' => $path,
            'headers_json' => ['amount'],
            'status' => 'preview',
        ]);
        ImportRowResult::query()->create([
            'import_batch_id' => $batch->id,
            'source_row_number' => 2,
            'target_type' => 'unmapped',
            'action' => 'preview',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'contracts',
                'column_map' => ['amount' => 'amount'],
                'normalization_rules' => ['amount' => 'strip_amount_format'],
                'save_profile' => '1',
                'profile_name' => 'Contract amounts',
            ])->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->fresh()->mappings()->firstOrFail();
        $this->assertSame(['amount' => 'strip_amount_format'], $mapping->normalization_rules);
        $this->assertSame('1000', $batch->rowResults()->where('source_row_number', 2)->firstOrFail()->after_json['amount']);
        $profile = $organization->importProfiles()->latest('id')->firstOrFail();
        $this->assertSame(['amount' => 'strip_amount_format'], $profile->normalization_rules);

        $nextBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Next contract amounts',
            'headers_json' => ['amount'],
            'status' => 'preview',
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$nextBatch, 'profile_id' => $profile->id]))
            ->assertOk()
            ->assertSee('normalization_rules[amount]')
            ->assertSee('checked', false);
    }

    public function test_duplicate_candidates_are_saved_and_can_be_reviewed_before_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/employees.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nAlice,duplicate@example.test\nAlice 2,duplicate@example.test\n");
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'duplicates', 'source_file_path' => $path, 'headers_json' => ['name', 'email'], 'status' => 'preview']);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 2, 'target_type' => 'unmapped', 'action' => 'preview']);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 3, 'target_type' => 'unmapped', 'action' => 'preview']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.mapping.store', $batch), ['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email']])->assertRedirect();

        $candidate = ImportReviewCandidate::query()->where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame([2, 3], $candidate->source_row_numbers_json);
        $this->assertSame(1, $batch->refresh()->preview_new_rows);
        $this->assertSame(0, $batch->preview_updated_rows);
        $this->assertSame(1, $batch->preview_confirmation_rows);
        $this->assertSame(0, $batch->preview_error_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 2, 'action' => 'new_candidate', 'target_type' => 'employees']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 3, 'action' => 'confirmation_required', 'target_type' => 'employees']);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.rows.selection', $batch), ['visible_row_numbers' => [2, 3], 'selected_row_numbers' => [2]])->assertRedirect();
        $this->assertFalse(ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 3)->firstOrFail()->is_selected);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertStatus(422);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.candidates.review', [$batch, $candidate]), ['status' => 'deferred'])->assertRedirect();
        $this->assertSame('deferred', $candidate->refresh()->status);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.candidates.review', [$batch, $candidate]), ['status' => 'accepted'])->assertRedirect();
        $reviewLogs = AuditLog::query()->where('organization_id', $organization->id)
            ->where('entity_type', ImportReviewCandidate::class)
            ->where('entity_id', $candidate->id)
            ->where('action', AuditLog::ImportCandidateReviewed)
            ->orderBy('id')
            ->pluck('after_json')
            ->all();
        $this->assertSame([['status' => 'deferred'], ['status' => 'accepted']], $reviewLogs);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.execute', $batch), ['confirm' => '1'])->assertRedirect();
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(0, $batch->error_rows);
        $this->assertSame(1, $batch->skipped_rows);
    }

    public function test_mapping_analysis_looks_up_targets_and_updates_preview_rows_in_bulk(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => 'Existing', 'email' => 'existing@example.test', 'employment_status' => 'active']);
        $path = 'imports/'.$organization->id.'/mapping-bulk.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nExisting,existing@example.test\nNew One,new-one@example.test\nNew Two,new-two@example.test\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'bulk mapping',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email'],
            'total_rows' => 3,
            'status' => 'preview',
        ]);
        foreach ([2, 3, 4] as $sourceRowNumber) {
            ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => $sourceRowNumber, 'target_type' => 'unmapped', 'action' => 'preview']);
        }
        config()->set('opsdeck.import.preview_insert_chunk_size', 2);
        $employeeLookupQueries = 0;
        $rowResultWriteQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$employeeLookupQueries, &$rowResultWriteQueries): void {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'select') && str_contains($sql, 'employees')) {
                $employeeLookupQueries++;
            }
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'import_row_results')) {
                $rowResultWriteQueries++;
            }
        });

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), ['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email']])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame(2, $employeeLookupQueries);
        $this->assertSame(2, $rowResultWriteQueries);
        $this->assertSame(1, $batch->refresh()->preview_updated_rows);
        $this->assertSame(2, $batch->preview_new_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 2, 'action' => 'update_candidate']);
    }

    public function test_failed_mapping_analysis_can_be_retried_without_executing_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/mapping-retry.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nRetry, retry@example.test\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'mapping retry',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email'],
            'status' => 'preview',
        ]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'source_row_number' => 2, 'target_type' => 'unmapped', 'action' => 'preview']);
        Queue::fake([PrepareImportMappingAnalysis::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), ['target_type' => 'employees', 'column_map' => ['name' => 'name', 'email' => 'email']])
            ->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->latest('id')->firstOrFail();
        $this->assertSame('analyzing', $batch->refresh()->status);
        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->failed(new \RuntimeException('Mapping analysis failed'));
        $this->assertSame('failed', $batch->refresh()->status);
        $this->assertSame('failed', $mapping->refresh()->status);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame('analyzing', $batch->refresh()->status);
        $this->assertSame('analyzing', $mapping->refresh()->status);
        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());
        Queue::assertPushed(PrepareImportMappingAnalysis::class, 2);
    }

    public function test_multi_row_import_header_range_is_saved_with_an_import_profile(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $headers = ['社員 / 氏名', '社員 / メール'];
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員管理表',
            'headers_json' => $headers,
            'header_row_number' => 2,
            'header_end_row_number' => 3,
            'data_start_row_number' => 4,
            'status' => 'preview',
            'executed_by' => $user->id,
        ]);
        Queue::fake([PrepareImportMappingAnalysis::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => ['社員 / 氏名' => 'name', '社員 / メール' => 'email'],
                'save_profile' => '1',
                'profile_name' => '複数段見出しの社員台帳',
            ])
            ->assertRedirect(route('imports.show', $batch));

        $profile = ImportProfile::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(2, $profile->header_row_number);
        $this->assertSame(3, $profile->header_end_row_number);
        $this->assertSame(4, $profile->data_start_row_number);
        $this->assertSame(
            app(ImportProfileService::class)->sourceSignature(null, $headers, 2, 4, 3),
            $profile->source_signature,
        );
    }

    public function test_operator_can_save_reuse_and_version_an_organization_scoped_import_profile(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $headers = ['Employee name', 'Work email'];
        $sourceSignature = app(ImportProfileService::class)->sourceSignature('Employees', $headers);
        $profileFromOtherOrganization = ImportProfile::query()->create([
            'organization_id' => $otherOrganization->id,
            'profile_key' => (string) Str::uuid(),
            'version' => 1,
            'name' => 'Other company profile',
            'target_type' => 'employees',
            'source_signature' => $sourceSignature,
            'header_row_number' => 1,
            'data_start_row_number' => 2,
            'selected_sheet_name' => 'Employees',
            'source_headers' => $headers,
            'column_map' => ['Employee name' => 'name', 'Work email' => 'email'],
            'normalization_rules' => [],
            'created_by' => $user->id,
        ]);
        $firstBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'employee-register.xlsx',
            'headers_json' => $headers,
            'selected_sheet_name' => 'Employees',
            'status' => 'preview',
        ]);
        Queue::fake([PrepareImportMappingAnalysis::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$firstBatch, 'profile_id' => $profileFromOtherOrganization->id]))
            ->assertNotFound();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $firstBatch), [
                'target_type' => 'employees',
                'profile_id' => $profileFromOtherOrganization->id,
                'column_map' => ['Employee name' => 'name', 'Work email' => 'email'],
                'save_profile' => '1',
            ])
            ->assertNotFound();
        $this->assertDatabaseMissing('import_profiles', ['organization_id' => $organization->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('imports.mapping', $firstBatch))
            ->post(route('imports.mapping.store', $firstBatch), [
                'target_type' => 'employees',
                'column_map' => ['Employee name' => 'name', 'Work email' => 'email'],
                'save_profile' => '1',
            ])
            ->assertSessionHasErrors('profile_name');
        $this->assertDatabaseMissing('import_profiles', ['organization_id' => $organization->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $firstBatch), [
                'target_type' => 'employees',
                'column_map' => ['Employee name' => 'name', 'Work email' => 'email'],
                'save_profile' => '1',
                'profile_name' => '社員台帳',
            ])
            ->assertRedirect(route('imports.show', $firstBatch));

        $firstProfileVersion = ImportProfile::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(1, $firstProfileVersion->version);
        $this->assertSame(1, $firstProfileVersion->header_end_row_number);
        $this->assertSame($sourceSignature, $firstProfileVersion->source_signature);
        $this->assertDatabaseHas('import_profiles', [
            'id' => $firstProfileVersion->id,
            'organization_id' => $organization->id,
            'column_map' => json_encode(['Employee name' => 'name', 'Work email' => 'email']),
        ]);

        $secondBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'employee-register-next-month.xlsx',
            'headers_json' => $headers,
            'selected_sheet_name' => 'Employees',
            'status' => 'preview',
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$secondBatch, 'profile_id' => $firstProfileVersion->id]))
            ->assertOk()
            ->assertSee('社員台帳（社員・v1）')
            ->assertSee('column_map[Employee name]')
            ->assertSee('value="name"', false);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $secondBatch), [
                'target_type' => 'employees',
                'profile_id' => $firstProfileVersion->id,
                'column_map' => ['Employee name' => 'name', 'Work email' => 'email'],
                'save_profile' => '1',
                'profile_name' => '社員台帳',
            ])
            ->assertRedirect(route('imports.show', $secondBatch));

        $this->assertDatabaseHas('import_profiles', [
            'organization_id' => $organization->id,
            'profile_key' => $firstProfileVersion->profile_key,
            'version' => 2,
            'name' => '社員台帳',
        ]);
        $this->assertSame(2, $firstProfileVersion->newQuery()->where('profile_key', $firstProfileVersion->profile_key)->count());
        $matchingProfiles = app(ImportProfileService::class)->matchingProfiles($organization, $sourceSignature);
        $this->assertCount(2, $matchingProfiles);
        $this->assertSame([2, 1], $matchingProfiles->pluck('version')->all());
        $differentSourceSignature = app(ImportProfileService::class)->sourceSignature('Employees', ['Employee name', 'Contact email']);
        $this->assertCount(0, app(ImportProfileService::class)->matchingProfiles($organization, $differentSourceSignature));
        $differentHeaderRowSignature = app(ImportProfileService::class)->sourceSignature('Employees', $headers, 2);
        $this->assertCount(0, app(ImportProfileService::class)->matchingProfiles($organization, $differentHeaderRowSignature));
        $singleRowSignature = app(ImportProfileService::class)->sourceSignature('Employees', $headers, 1, 2, 1);
        $this->assertSame($sourceSignature, $singleRowSignature);
        $differentHeaderRangeSignature = app(ImportProfileService::class)->sourceSignature('Employees', $headers, 1, 4, 3);
        $this->assertCount(0, app(ImportProfileService::class)->matchingProfiles($organization, $differentHeaderRangeSignature));
        $differentDataStartRowSignature = app(ImportProfileService::class)->sourceSignature('Employees', $headers, 1, 3);
        $this->assertCount(0, app(ImportProfileService::class)->matchingProfiles($organization, $differentDataStartRowSignature));
    }

    public function test_selected_multi_row_csv_headers_and_data_start_rows_are_used_by_preview_analysis_and_execution(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $path = 'imports/'.$organization->id.'/employee-register.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "社員管理台帳\r\n2026年度\r\n社員,社員\r\nname,email\r\nnote,集計対象外\r\nAlice,alice@example.test\r\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員管理台帳',
            'source_file_path' => $path,
            'header_row_number' => 3,
            'header_end_row_number' => 4,
            'data_start_row_number' => 6,
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);

        app(ImportPreviewPreparationService::class)->prepare($batch->id);
        $batch->refresh();
        $this->assertSame(['社員 / name', '社員 / email'], $batch->headers_json);
        $this->assertSame(4, $batch->resolvedHeaderEndRowNumber());
        $this->assertSame(6, $batch->data_start_row_number);
        $this->assertSame(1, $batch->total_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 6, 'action' => 'preview']);

        Queue::fake([PrepareImportMappingAnalysis::class]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => ['社員 / name' => 'name', '社員 / email' => 'email'],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->latest('id')->firstOrFail();
        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->handle(app(ImportMappingAnalysisService::class));
        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 6, 'action' => 'new_candidate']);

        app(ImportBatchExecutionService::class)->execute($batch->id, (int) $user->id, 'ready');
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertDatabaseHas('employees', [
            'organization_id' => $organization->id,
            'name' => 'Alice',
            'email' => 'alice@example.test',
        ]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 6, 'action' => 'inserted']);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'mapping-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'premium', 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }

    private function memoryLimitBytes(string $memoryLimit): ?int
    {
        if ($memoryLimit === '-1' || preg_match('/^(\d+)\s*([KMG])?$/i', trim($memoryLimit), $matches) !== 1) {
            return null;
        }

        $power = match (strtoupper($matches[2] ?? '')) {
            'G' => 3,
            'M' => 2,
            'K' => 1,
            default => 0,
        };

        return (int) $matches[1] * (1024 ** $power);
    }
}
