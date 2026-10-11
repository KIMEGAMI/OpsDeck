<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ImportXlsxExecutionTest extends TestCase
{
    public function test_xlsx_rows_over_the_plan_limit_are_recorded_as_errors(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Config::set('opsdeck.plans.premium.limits.employees', 0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member('premium');
        $path = 'imports/'.$organization->id.'/employees-over-limit.xlsx';
        $temporaryDirectory = storage_path('framework/testing-xlsx');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryPath = tempnam($temporaryDirectory, 'opsdeck-xlsx-limit-');
        $archive = new ZipArchive;
        $archive->open($temporaryPath);
        $archive->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="社員一覧" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $archive->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>名前</t></si><si><t>メール</t></si><si><t>Alice</t></si><si><t>alice-limit-'.Str::random(8).'@example.test</t></si></sst>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row></sheetData></worksheet>');
        $archive->close();
        Storage::disk(config('opsdeck.import.disk'))->put($path, file_get_contents($temporaryPath));
        unlink($temporaryPath);

        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'xlsx', 'source_name' => 'employees-over-limit', 'source_file_path' => $path, 'selected_sheet_name' => '社員一覧', 'headers_json' => ['名前', 'メール'], 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['名前' => 'name', 'メール' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(0, $batch->inserted_rows);
        $this->assertSame(1, $batch->error_rows);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id]);
        $error = $batch->rowResults()->where('action', 'error')->firstOrFail();
        $this->assertStringContainsString('上限', $error->errors_json['row']);
    }

    public function test_operator_can_execute_a_selected_xlsx_sheet(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member();
        $path = 'imports/'.$organization->id.'/employees.xlsx';
        $temporaryDirectory = storage_path('framework/testing-xlsx');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryPath = tempnam($temporaryDirectory, 'opsdeck-xlsx-');
        $archive = new ZipArchive;
        $archive->open($temporaryPath);
        $archive->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="社員一覧" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $archive->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>名前</t></si><si><t>メール</t></si><si><t>Alice</t></si><si><t>alice-'.Str::random(8).'@example.test</t></si></sst>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row></sheetData></worksheet>');
        $archive->close();
        Storage::disk(config('opsdeck.import.disk'))->put($path, file_get_contents($temporaryPath));
        unlink($temporaryPath);

        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'employees',
            'source_file_path' => $path,
            'selected_sheet_name' => '社員一覧',
            'headers_json' => ['名前', 'メール'],
            'status' => 'ready',
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['名前' => 'name', 'メール' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseHas('employees', ['organization_id' => $organization->id, 'name' => 'Alice']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
    }

    public function test_xlsx_contract_preview_and_execution_convert_dates_and_preserve_numeric_amounts(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member();
        $path = 'imports/'.$organization->id.'/contracts.xlsx';
        $headers = ['vendor_name', 'contract_name', 'billing_cycle', 'amount', 'currency', 'started_at', 'renewal_date', 'status'];
        $headerCells = [];
        foreach ($headers as $index => $header) {
            $headerCells[] = '<c r="'.chr(65 + $index).'1" t="inlineStr"><is><t>'.$header.'</t></is></c>';
        }
        $dataCells = [
            '<c r="A2" t="inlineStr"><is><t>Acme</t></is></c>',
            '<c r="B2" t="inlineStr"><is><t>Support contract</t></is></c>',
            '<c r="C2" t="inlineStr"><is><t>annual</t></is></c>',
            '<c r="D2" s="2"><v>120000</v></c>',
            '<c r="E2" t="inlineStr"><is><t>JPY</t></is></c>',
            '<c r="F2" s="1"><v>45292</v></c>',
            '<c r="G2" s="1"><v>46023</v></c>',
            '<c r="H2" t="inlineStr"><is><t>active</t></is></c>',
        ];
        $temporaryDirectory = storage_path('framework/testing-xlsx');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryPath = tempnam($temporaryDirectory, 'opsdeck-xlsx-contract-');
        $archive = new ZipArchive;
        $archive->open($temporaryPath);
        $archive->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Contracts" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $archive->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="165" formatCode="yyyy/mm/dd"/><numFmt numFmtId="166" formatCode="¥#,##0"/></numFmts><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="165"/><xf numFmtId="166"/></cellXfs></styleSheet>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'.implode('', $headerCells).'</row><row r="2">'.implode('', $dataCells).'</row></sheetData></worksheet>');
        $archive->close();
        Storage::disk(config('opsdeck.import.disk'))->put($path, file_get_contents($temporaryPath));
        unlink($temporaryPath);

        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'Contracts',
            'source_file_path' => $path,
            'selected_sheet_name' => 'Contracts',
            'headers_json' => $headers,
            'status' => 'preview',
            'executed_by' => $user->id,
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
                'column_map' => array_combine($headers, $headers),
            ])
            ->assertRedirect();

        $batch->refresh();
        $preview = $batch->rowResults()->latest('id')->firstOrFail();
        $this->assertSame('2024-01-01', $preview->after_json['started_at']);
        $this->assertSame('2026-01-01', $preview->after_json['renewal_date']);
        $this->assertSame('120000', $preview->after_json['amount']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $contract = Contract::query()->where('organization_id', $organization->id)->where('contract_name', 'Support contract')->firstOrFail();
        $this->assertSame('120000.00', $contract->amount);
        $this->assertSame('2024-01-01', $contract->started_at->format('Y-m-d'));
        $this->assertSame('2026-01-01', $contract->renewal_date->format('Y-m-d'));
    }

    public function test_xlsx_contract_amount_cells_use_the_shared_import_validation_rules(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member();
        $path = 'imports/'.$organization->id.'/contract-amount-boundaries.xlsx';
        $headers = ['vendor_name', 'contract_name', 'billing_cycle', 'amount', 'currency', 'status'];
        $headerCells = [];
        foreach ($headers as $index => $header) {
            $headerCells[] = '<c r="'.chr(65 + $index).'1" t="inlineStr"><is><t>'.$header.'</t></is></c>';
        }

        $rows = [
            ['Acme', 'Valid decimal', 'annual', '120000.25', 'JPY', 'active'],
            ['Acme', 'Negative amount', 'annual', '-0.01', 'JPY', 'active'],
            ['Acme', 'Excess precision', 'annual', '1.234', 'JPY', 'active'],
            ['Acme', 'Amount over database range', 'annual', '1000000000000', 'JPY', 'active'],
        ];
        $worksheetRows = ['<row r="1">'.implode('', $headerCells).'</row>'];
        foreach ($rows as $rowIndex => $values) {
            $sourceRowNumber = $rowIndex + 2;
            $cells = [];
            foreach ($values as $columnIndex => $value) {
                $cellReference = chr(65 + $columnIndex).$sourceRowNumber;
                $cells[] = $columnIndex === 3
                    ? '<c r="'.$cellReference.'" s="1"><v>'.$value.'</v></c>'
                    : '<c r="'.$cellReference.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
            }
            $worksheetRows[] = '<row r="'.$sourceRowNumber.'">'.implode('', $cells).'</row>';
        }

        $temporaryDirectory = storage_path('framework/testing-xlsx');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryPath = tempnam($temporaryDirectory, 'opsdeck-xlsx-contract-amount-');
        $archive = new ZipArchive;
        $archive->open($temporaryPath);
        $archive->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Contracts" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $archive->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="166" formatCode="¥#,##0.00"/></numFmts><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="166"/></cellXfs></styleSheet>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $worksheetRows).'</sheetData></worksheet>');
        $archive->close();
        Storage::disk(config('opsdeck.import.disk'))->put($path, file_get_contents($temporaryPath));
        unlink($temporaryPath);

        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'Contract amount boundaries',
            'source_file_path' => $path,
            'selected_sheet_name' => 'Contracts',
            'headers_json' => $headers,
            'status' => 'preview',
            'executed_by' => $user->id,
        ]);
        foreach (range(2, 5) as $sourceRowNumber) {
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
        $this->assertSame(3, $batch->preview_error_rows);
        $validRow = ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', 2)->firstOrFail();
        $this->assertSame('120000.25', $validRow->after_json['amount']);
        foreach ([3, 4, 5] as $sourceRowNumber) {
            $this->assertSame('error', ImportRowResult::query()->where('import_batch_id', $batch->id)->where('source_row_number', $sourceRowNumber)->value('action'));
        }

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->inserted_rows);
        $this->assertSame(3, $batch->error_rows);
        $this->assertDatabaseHas('contracts', [
            'organization_id' => $organization->id,
            'contract_name' => 'Valid decimal',
            'amount' => '120000.25',
        ]);
        foreach (['Negative amount', 'Excess precision', 'Amount over database range'] as $invalidContractName) {
            $this->assertDatabaseMissing('contracts', [
                'organization_id' => $organization->id,
                'contract_name' => $invalidContractName,
            ]);
        }
    }

    public function test_free_plan_cannot_execute_an_import_batch(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member('free');
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'free-preview', 'source_file_path' => 'imports/free-preview.csv', 'status' => 'ready']);
        $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['名前' => 'name'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());
    }

    /** @return array{0: User, 1: Organization} */
    private function member(string $planCode = 'premium'): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'xlsx-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'XLSX '.$suffix, 'slug' => 'xlsx-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => $planCode, 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Operator, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
