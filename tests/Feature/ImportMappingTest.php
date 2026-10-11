<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\ExecuteImportBatch;
use App\Jobs\PrepareImportMappingAnalysis;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportReviewCandidate;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireQuestion;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Models\User;
use App\Services\ImportBatchExecutionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    }

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
            ->assertDontSee('2,000');
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
