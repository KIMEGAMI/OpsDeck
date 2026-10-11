<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\PrepareGoogleSheetDiffPreview;
use App\Jobs\PrepareImportMappingAnalysis;
use App\Jobs\PrepareImportPreview;
use App\Models\Contract;
use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Import\GoogleSheetDiffPreviewPreparationService;
use App\Services\Import\ImportMappingAnalysisService;
use App\Services\Import\ImportPreviewPreparationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoogleSheetConnectionTest extends TestCase
{
    public function test_free_plan_cannot_open_google_sheets_import(): void
    {
        [$user, $organization] = $this->member('free');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.google-sheets'))
            ->assertForbidden();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('google-sheets.connect'))
            ->assertForbidden();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('google-sheets.connection'))
            ->assertOk()
            ->assertSee('Premiumプランで利用できます')
            ->assertSee(route('help.recovery', 'google_oauth'))
            ->assertDontSee(route('google-sheets.connect'));
    }

    public function test_operator_can_start_google_oauth_with_state(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        config(['services.google.client_id' => 'client-id', 'services.google.redirect_uri' => 'https://opsdeck.test/integrations/google-sheets/callback']);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('google-sheets.connect'));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', (string) $response->headers->get('Location'));
        $this->assertNotEmpty(session('google_sheets_oauth_state.value'));
    }

    public function test_callback_validates_state_and_encrypts_tokens(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret', 'services.google.redirect_uri' => 'https://opsdeck.test/integrations/google-sheets/callback']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_in' => 3600], 200)]);
        $state = Str::random(64);

        $this->actingAs($user)->withSession([
            'current_organization_id' => $organization->id,
            'google_sheets_oauth_state' => ['value' => $state, 'organization_id' => $organization->id],
        ])->get(route('google-sheets.callback', ['state' => $state, 'code' => 'authorization-code']))->assertRedirect(route('google-sheets.connection'));

        $connection = GoogleSheetConnection::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('access-token', $connection->access_token);
        $this->assertSame('refresh-token', $connection->refresh_token);
        $this->assertNotSame('access-token', $connection->getRawOriginal('access_token'));
    }

    public function test_operator_can_select_a_spreadsheet_for_preview(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => [['id' => 'sheet-id', 'name' => '台帳']]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id*' => Http::response(['sheets' => [['properties' => ['title' => '社員一覧']]]], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('imports.google-sheets'))->assertOk()->assertSee('台帳')->assertSee('1回の取込は最大20,000行');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.google-sheets.spreadsheet'), ['spreadsheet_id' => 'sheet-id', 'source_name' => 'Google社員'])->assertRedirect();

        $this->assertDatabaseHas('import_batches', ['organization_id' => $organization->id, 'source_type' => 'google_sheets', 'source_external_id' => 'sheet-id', 'status' => 'sheet_selection']);
        $this->assertSame(1, ImportBatch::query()->where('organization_id', $organization->id)->count());
    }

    public function test_selecting_google_sheet_queues_preview_without_fetching_values_in_request(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member('premium');
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => 'Google社員',
            'source_external_id' => 'sheet-id',
            'available_sheets_json' => ['社員一覧'],
            'status' => 'sheet_selection',
        ]);
        Queue::fake([PrepareImportPreview::class]);
        Http::fake();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.google-sheet.store', $batch), ['sheet_name' => '社員一覧'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame('previewing', $batch->refresh()->status);
        Queue::assertPushed(PrepareImportPreview::class, fn (PrepareImportPreview $job): bool => $job->importBatchId === $batch->id);
        Http::assertNothingSent();
    }

    public function test_google_sheet_preview_streams_source_rows_into_preview_records(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [, $organization] = $this->member('premium');
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => 'Google社員',
            'source_external_id' => 'sheet-id',
            'selected_sheet_name' => '社員一覧',
            'status' => 'previewing',
        ]);
        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id?*' => Http::response(['sheets' => [['properties' => ['title' => '社員一覧', 'gridProperties' => ['rowCount' => 4]]]]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::response(['values' => [
                ['名前', 'メール'],
                ['Alice', 'alice@example.test'],
                ['', ''],
                ['Bob', 'bob@example.test'],
            ]], 200),
        ]);

        app(ImportPreviewPreparationService::class)->prepare($batch->id);

        $this->assertSame('preview', $batch->refresh()->status);
        $this->assertSame(['名前', 'メール'], $batch->headers_json);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame([2, 4], ImportRowResult::query()->where('import_batch_id', $batch->id)->orderBy('source_row_number')->pluck('source_row_number')->all());
    }

    public function test_google_sheet_preview_reports_when_the_import_row_limit_is_exceeded(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.max_rows' => 1]);
        [$user, $organization] = $this->member('premium');
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => 'Google社員',
            'source_external_id' => 'sheet-id',
            'selected_sheet_name' => '社員一覧',
            'status' => 'previewing',
        ]);
        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id?*' => Http::response(['sheets' => [['properties' => ['title' => '社員一覧', 'gridProperties' => ['rowCount' => 3]]]]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::response(['values' => [
                ['名前', 'メール'],
                ['Alice', 'alice@example.test'],
                ['Bob', 'bob@example.test'],
            ]], 200),
        ]);
        $expectedMessage = 'Google Sheetsの取込上限（1行）を超えています。シートを分割して再度お試しください。';

        try {
            app(ImportPreviewPreparationService::class)->prepare($batch->id);
            $this->fail('Google Sheets imports above the configured row limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame([$expectedMessage], $exception->errors()['file']);
            (new PrepareImportPreview($batch->id))->failed($exception);
        }

        $this->assertSame('failed', $batch->refresh()->status);
        $this->assertSame($expectedMessage, $batch->failure_message);
        $this->assertSame(0, $batch->rowResults()->count());
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee($expectedMessage);

        Queue::fake([PrepareImportPreview::class]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));
        $this->assertSame('previewing', $batch->refresh()->status);
        $this->assertNull($batch->failure_message);
        Queue::assertPushed(PrepareImportPreview::class, fn (PrepareImportPreview $job): bool => $job->importBatchId === $batch->id);
    }

    public function test_operator_can_create_a_google_sheet_diff_preview_without_deleting_rows(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'google_sheets', 'source_name' => 'Google社員', 'source_external_id' => 'sheet-id', 'selected_sheet_name' => '社員一覧', 'headers_json' => ['名前', 'メール'], 'status' => 'completed']);
        $mapping = $batch->mappings()->create(['target_type' => 'employees', 'column_map' => ['名前' => 'name', 'メール' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);
        $batch->update(['mapping_id' => (string) $mapping->id]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'sheet_name' => '社員一覧', 'source_row_number' => 2, 'target_type' => 'employees', 'target_id' => 99, 'action' => 'inserted', 'after_json' => ['name' => 'Alice', 'email' => 'alice@example.test']]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'sheet_name' => '社員一覧', 'source_row_number' => 3, 'target_type' => 'employees', 'target_id' => 100, 'action' => 'inserted', 'after_json' => ['name' => 'Removed', 'email' => 'removed@example.test']]);
        config()->set('opsdeck.import.google_sheets_read_chunk_rows', 2);
        Http::preventStrayRequests();
        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id?*' => Http::response(['sheets' => [['properties' => ['title' => '社員一覧', 'gridProperties' => ['rowCount' => 4]]]]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::sequence()
                ->push(['values' => [['名前', 'メール'], ['Alice', 'alice@example.test']]], 200)
                ->push(['values' => [[''], ['Bob', 'bob@example.test']]], 200),
        ]);
        Queue::fake([PrepareGoogleSheetDiffPreview::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.google-sheet.sync', $batch))->assertRedirect();

        $preview = ImportBatch::query()->where('organization_id', $organization->id)->where('id', '!=', $batch->id)->firstOrFail();
        $this->assertSame('previewing', $preview->status);
        $this->assertSame($batch->id, $preview->diff_source_batch_id);
        Queue::assertPushed(PrepareGoogleSheetDiffPreview::class, fn (PrepareGoogleSheetDiffPreview $job): bool => $job->sourceBatchId === $batch->id && $job->diffBatchId === $preview->id);
        Http::assertNothingSent();
        (new PrepareGoogleSheetDiffPreview($batch->id, $preview->id))->handle(app(GoogleSheetDiffPreviewPreparationService::class));

        $preview->refresh();
        $this->assertSame('preview', $preview->status);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $preview->id, 'action' => 'unchanged']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $preview->id, 'action' => 'new_candidate']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $preview->id, 'action' => 'deleted_candidate']);
        Http::assertSentCount(3);
        $this->assertSame('completed', $batch->refresh()->status);
    }

    public function test_google_sheet_diff_normalizes_formatted_evidence_dates_before_comparison(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $headers = ['title', 'evidence_type', 'valid_from', 'valid_until'];
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => 'Google証跡',
            'source_external_id' => 'sheet-id',
            'selected_sheet_name' => '証跡一覧',
            'headers_json' => $headers,
            'status' => 'completed',
        ]);
        $mapping = $batch->mappings()->create([
            'target_type' => 'evidence',
            'column_map' => array_combine($headers, $headers),
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id]);
        ImportRowResult::query()->create([
            'import_batch_id' => $batch->id,
            'sheet_name' => '証跡一覧',
            'source_row_number' => 2,
            'target_type' => 'evidence',
            'target_id' => 99,
            'action' => 'inserted',
            'after_json' => ['title' => 'Access policy', 'evidence_type' => 'policy', 'valid_from' => '2026-01-01', 'valid_until' => '2027-01-01'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id?*' => Http::response(['sheets' => [['properties' => ['title' => '証跡一覧', 'gridProperties' => ['rowCount' => 2]]]]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::response([
                'values' => [$headers, ['Access policy', 'policy', 'Jan 1, 2026', 'Jan 1, 2027']],
            ], 200),
        ]);
        Queue::fake([PrepareGoogleSheetDiffPreview::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.google-sheet.sync', $batch))
            ->assertRedirect();

        $preview = ImportBatch::query()->where('organization_id', $organization->id)->where('id', '!=', $batch->id)->firstOrFail();
        (new PrepareGoogleSheetDiffPreview($batch->id, $preview->id))->handle(app(GoogleSheetDiffPreviewPreparationService::class));
        $preview->refresh();
        $result = $preview->rowResults()->firstOrFail();
        $this->assertSame('unchanged', $result->action);
        $this->assertSame('2026-01-01', $result->after_json['valid_from']);
        $this->assertSame('2027-01-01', $result->after_json['valid_until']);
    }

    public function test_failed_google_sheet_diff_preview_can_be_retried_without_import_execution(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $sourceBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'google_sheets', 'source_name' => 'Google社員', 'source_external_id' => 'sheet-id', 'selected_sheet_name' => '社員一覧', 'status' => 'completed']);
        $mapping = $sourceBatch->mappings()->create(['target_type' => 'employees', 'column_map' => ['名前' => 'name', 'メール' => 'email'], 'normalization_rules' => [], 'status' => 'ready', 'created_by' => $user->id]);
        $sourceBatch->update(['mapping_id' => (string) $mapping->id]);
        Queue::fake([PrepareGoogleSheetDiffPreview::class]);
        Http::fake();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.google-sheet.sync', $sourceBatch))
            ->assertRedirect();

        $diffBatch = ImportBatch::query()->where('diff_source_batch_id', $sourceBatch->id)->firstOrFail();
        (new PrepareGoogleSheetDiffPreview($sourceBatch->id, $diffBatch->id))->failed(new \RuntimeException('Google Sheets request failed'));
        $this->assertSame('failed', $diffBatch->refresh()->status);
        Http::assertNothingSent();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $diffBatch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $diffBatch));

        $this->assertSame('previewing', $diffBatch->refresh()->status);
        Queue::assertPushed(PrepareGoogleSheetDiffPreview::class, fn (PrepareGoogleSheetDiffPreview $job): bool => $job->sourceBatchId === $sourceBatch->id && $job->diffBatchId === $diffBatch->id);
    }

    public function test_saving_google_sheet_mapping_queues_analysis_without_fetching_rows_in_request(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member('premium');
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => 'Google社員',
            'source_external_id' => 'sheet-id',
            'selected_sheet_name' => '社員一覧',
            'headers_json' => ['名前', 'メール'],
            'status' => 'preview',
        ]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'sheet_name' => '社員一覧', 'source_row_number' => 2, 'target_type' => 'unmapped', 'action' => 'preview']);
        Http::preventStrayRequests();
        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id?*' => Http::response(['sheets' => [['properties' => ['title' => '社員一覧', 'gridProperties' => ['rowCount' => 2]]]]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::response(['values' => [['名前', 'メール'], ['Alice', 'alice@example.test']]], 200),
        ]);
        Queue::fake([PrepareImportMappingAnalysis::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => ['名前' => 'name', 'メール' => 'email'],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->latest('id')->firstOrFail();
        $this->assertSame('analyzing', $batch->refresh()->status);
        $this->assertSame('analyzing', $mapping->status);
        Queue::assertPushed(PrepareImportMappingAnalysis::class, fn (PrepareImportMappingAnalysis $job): bool => $job->importBatchId === $batch->id && $job->mappingId === $mapping->id);
        Http::assertNothingSent();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('列マッピング後の重複候補と件数を分析しています');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertStatus(422);
        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->handle(app(ImportMappingAnalysisService::class));

        Http::assertSentCount(2);
        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame('ready', $mapping->refresh()->status);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 2, 'action' => 'new_candidate']);
    }

    public function test_failed_google_sheet_mapping_read_does_not_commit_partial_analysis(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => 'Google社員',
            'source_external_id' => 'sheet-id',
            'selected_sheet_name' => '社員一覧',
            'headers_json' => ['名前', 'メール'],
            'status' => 'analyzing',
        ]);
        $mapping = $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['名前' => 'name', 'メール' => 'email'],
            'normalization_rules' => [],
            'status' => 'analyzing',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id]);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'sheet_name' => '社員一覧', 'source_row_number' => 2, 'target_type' => 'unmapped', 'action' => 'preview']);
        ImportRowResult::query()->create(['import_batch_id' => $batch->id, 'sheet_name' => '社員一覧', 'source_row_number' => 3, 'target_type' => 'unmapped', 'action' => 'preview']);
        config()->set('opsdeck.import.preview_insert_chunk_size', 1);
        config()->set('opsdeck.import.google_sheets_read_chunk_rows', 1);
        Http::preventStrayRequests();
        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id?*' => Http::response(['sheets' => [['properties' => ['title' => '社員一覧', 'gridProperties' => ['rowCount' => 3]]]]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::sequence()
                ->push(['values' => [['名前', 'メール']]], 200)
                ->push(['values' => [['Alice', 'alice@example.test']]], 200)
                ->push([], 500),
        ]);

        try {
            app(ImportMappingAnalysisService::class)->analyze($batch->id, $mapping->id);
            $this->fail('A failed Google Sheets request should stop mapping analysis.');
        } catch (\RuntimeException) {
            $this->assertSame('analyzing', $batch->refresh()->status);
            $this->assertSame('analyzing', $mapping->refresh()->status);
            $this->assertSame(['preview', 'preview'], $batch->rowResults()->orderBy('source_row_number')->pluck('action')->all());
            $this->assertSame(0, $batch->reviewCandidates()->count());
        }
    }

    public function test_google_sheet_contract_numbers_and_dates_use_locale_independent_values_for_preview_and_execution(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token']);
        $headers = ['vendor_name', 'contract_name', 'billing_cycle', 'amount', 'currency', 'status', 'renewal_date'];
        $values = [
            $headers,
            ['Acme', 'Valid decimal', 'annual', 120000.25, 'JPY', 'active', 46023],
            ['Acme', 'Negative amount', 'annual', -0.01, 'JPY', 'active', 46023],
            ['Acme', 'Excess precision', 'annual', 1.234, 'JPY', 'active', 46023],
            ['Acme', 'Amount over database range', 'annual', 1000000000000, 'JPY', 'active', 46023],
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id?*' => Http::response(['sheets' => [['properties' => ['title' => 'Contracts', 'gridProperties' => ['rowCount' => 5]]]]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::response(['values' => $values], 200),
        ]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => 'Google contracts',
            'source_external_id' => 'sheet-id',
            'selected_sheet_name' => 'Contracts',
            'headers_json' => $headers,
            'total_rows' => count($values) - 1,
            'status' => 'preview',
            'executed_by' => $user->id,
        ]);
        foreach (range(2, 5) as $sourceRowNumber) {
            ImportRowResult::query()->create([
                'import_batch_id' => $batch->id,
                'sheet_name' => 'Contracts',
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
        $this->assertSame('2026-01-01', $validRow->after_json['renewal_date']);

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
        $contract = Contract::query()->where('organization_id', $organization->id)->where('contract_name', 'Valid decimal')->firstOrFail();
        $this->assertSame('2026-01-01', $contract->renewal_date->toDateString());
        foreach (['Negative amount', 'Excess precision', 'Amount over database range'] as $invalidContractName) {
            $this->assertDatabaseMissing('contracts', [
                'organization_id' => $organization->id,
                'contract_name' => $invalidContractName,
            ]);
        }

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'valueRenderOption=UNFORMATTED_VALUE')
            && str_contains($request->url(), 'dateTimeRenderOption=SERIAL_NUMBER'));
    }

    /** @return array{0: User, 1: Organization} */
    private function member(string $planCode = 'premium'): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'google-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Google '.$suffix, 'slug' => 'google-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => $planCode, 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Operator, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
