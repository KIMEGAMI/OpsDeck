<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\ExecuteImportBatch;
use App\Jobs\PrepareImportMappingAnalysis;
use App\Jobs\PrepareImportPreview;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Import\ImportMappingAnalysisService;
use App\Services\Import\ImportPreviewPreparationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportSampleTest extends TestCase
{
    public function test_member_can_download_employee_sample_csv(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('imports.samples.download', 'employees'));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertHeader('content-disposition');
        $this->assertStringContainsString('EMP-SAMPLE-001', $response->streamedContent());
    }

    public function test_sample_csv_headers_are_automatically_mappable_to_their_import_fields(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(AiProvider::class)->shouldNotReceive('suggestColumnMapping');
        $tableCounts = [];
        foreach (['import_batches', 'product_events', 'employees', 'assets', 'contracts', 'saas_services', 'ai_usage_logs'] as $table) {
            $tableCounts[$table] = DB::table($table)->count();
        }
        $expectedJapaneseHeaders = [
            'employees' => ['社員番号', '氏名', 'メール', '在籍状況'],
            'assets' => ['資産番号', '種別', 'メーカー', '機種', '状態'],
            'contracts' => ['ベンダー', '契約名', '請求サイクル', '金額', '通貨', '状態'],
            'saas_services' => ['サービス名', 'ベンダー', '状態'],
        ];
        $expectedMappedFields = [
            'employees' => ['employee_number', 'name', 'email', 'employment_status'],
            'assets' => ['asset_number', 'asset_type', 'manufacturer', 'model', 'status'],
            'contracts' => ['vendor_name', 'contract_name', 'billing_cycle', 'amount', 'currency', 'status'],
            'saas_services' => ['name', 'vendor', 'status'],
        ];

        foreach (config('opsdeck.import_samples', []) as $targetType => $sample) {
            $aliases = config('opsdeck.import_column_aliases.'.$targetType, []);
            $response = $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->get(route('imports.samples.download', $targetType));
            $response->assertOk();

            $csv = fopen('php://temp', 'w+b');
            fwrite($csv, substr($response->streamedContent(), 3));
            rewind($csv);
            $headers = fgetcsv($csv, null, ',', '"', '');
            $row = fgetcsv($csv, null, ',', '"', '');
            fclose($csv);

            $this->assertSame($sample['headers'], $headers, $targetType);
            $this->assertSame($sample['row'], $row, $targetType);
            $this->assertSame($expectedJapaneseHeaders[$targetType], $headers, $targetType.' sample headers should be understandable in Japanese');
            $this->assertSame($expectedMappedFields[$targetType], array_map(static fn (string $header): ?string => $aliases[$header] ?? null, $headers));
        }

        foreach ($tableCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' must remain unchanged');
        }
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_unknown_sample_target_is_not_available(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('imports.samples.download', 'unknown'))->assertNotFound();
    }

    public function test_sample_preview_shows_mappings_without_creating_import_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(AiProvider::class)->shouldNotReceive('suggestColumnMapping');
        $tableCounts = [];
        foreach (['import_batches', 'product_events', 'employees', 'assets', 'contracts', 'saas_services', 'ai_usage_logs'] as $table) {
            $tableCounts[$table] = DB::table($table)->count();
        }

        foreach (config('opsdeck.import_samples', []) as $targetType => $sample) {
            $response = $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->get(route('imports.samples.preview', $targetType));

            $response->assertOk()
                ->assertSee('のサンプル確認（登録されません）')
                ->assertSee('会社のデータは登録・変更されません。AIへの送信やメール送信も行いません。');
            foreach ($sample['headers'] as $index => $header) {
                $field = config('opsdeck.import_column_aliases.'.$targetType.'.'.$header);
                $expectedLabel = $field === null
                    ? '自動候補なし'
                    : config('opsdeck.import_field_labels_by_target.'.$targetType.'.'.$field, config('opsdeck.import_field_labels.'.$field, $field))
                        .(in_array($field, config('opsdeck.import_required_mapping_fields.'.$targetType, []), true) ? '（必須）' : '');
                $sampleValue = $sample['row'][$index];
                $valueLabels = $field === null
                    ? []
                    : config('opsdeck.import_value_labels_by_target.'.$targetType.'.'.$field, []);
                $expectedValue = is_string($sampleValue) ? ($valueLabels[$sampleValue] ?? $sampleValue) : $sampleValue;
                $response->assertSee($header)
                    ->assertSee($expectedLabel)
                    ->assertSee($expectedValue);
            }
        }

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.samples.preview', 'employees'))
            ->assertSee('在籍中')
            ->assertDontSee('（active）');

        foreach ($tableCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' must remain unchanged');
        }
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_import_preview_explains_known_status_values_without_changing_saved_values(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員名簿',
            'status' => 'ready',
        ]);
        $mapping = $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['在籍状況' => 'employment_status'],
            'status' => 'ready',
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id]);
        $rowResult = ImportRowResult::query()->create([
            'import_batch_id' => $batch->id,
            'source_row_number' => 2,
            'target_type' => 'employees',
            'action' => 'new_candidate',
            'after_json' => ['employment_status' => 'active'],
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertSee('在籍中')
            ->assertDontSee('（active）');

        $this->assertSame(['employment_status' => 'active'], $rowResult->refresh()->after_json);
    }

    public function test_free_member_cannot_import_sample_csv_as_business_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        Queue::fake();
        [$user, $organization] = $this->member();
        $organization->update(['plan_code' => config('opsdeck.free_plan_code')]);
        Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => '既存社員',
            'email' => 'update-target@example.test',
            'employment_status' => 'active',
        ]);
        $session = ['current_organization_id' => $organization->id];

        $sampleResponse = $this->actingAs($user)->withSession($session)
            ->get(route('imports.samples.download', 'employees'));
        $sampleResponse->assertOk();
        $csv = rtrim($sampleResponse->streamedContent(), "\r\n")."\r\n"
            ."EMP-NEW-001,新規候補,new-candidate@example.test,active\r\n"
            ."EMP-UPDATE-001,更新候補,update-target@example.test,active\r\n"
            ."EMP-DUP-001,重複候補A,duplicate-candidate@example.test,active\r\n"
            ."EMP-DUP-002,重複候補B,duplicate-candidate@example.test,active\r\n"
            ."EMP-ERROR-001,,invalid-row@example.test,active\r\n";

        $this->actingAs($user)->withSession($session)
            ->post(route('imports.store'), [
                'source_name' => 'サンプルCSV導入確認',
                'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
            ])
            ->assertRedirect();

        $batch = ImportBatch::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
        $this->assertSame('previewing', $batch->status);
        Queue::assertPushed(PrepareImportPreview::class, fn (PrepareImportPreview $job): bool => $job->importBatchId === $batch->id);
        $this->actingAs($user)->withSession($session)
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('ファイルを確認しています');

        $this->app->make(ImportPreviewPreparationService::class)->prepare($batch->id);
        $this->assertSame('preview', $batch->refresh()->status);
        $this->assertSame(6, $batch->total_rows);
        $this->assertSame(1, DB::table('product_events')
            ->where('organization_id', $organization->id)
            ->where('event_key', 'first_import_previewed')
            ->count());

        $this->actingAs($user)->withSession($session)
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => [
                    '社員番号' => 'employee_number',
                    '氏名' => 'name',
                    'メール' => 'email',
                    '在籍状況' => 'employment_status',
                ],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->latest('id')->firstOrFail();
        Queue::assertPushed(PrepareImportMappingAnalysis::class, fn (PrepareImportMappingAnalysis $job): bool => $job->importBatchId === $batch->id && $job->mappingId === $mapping->id);
        $this->app->make(ImportMappingAnalysisService::class)->analyze($batch->id, $mapping->id);

        $batch->refresh();
        $this->assertSame('ready', $batch->status);
        $this->assertSame(3, $batch->preview_new_rows);
        $this->assertSame(1, $batch->preview_updated_rows);
        $this->assertSame(1, $batch->preview_confirmation_rows);
        $this->assertSame(1, $batch->preview_error_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 4, 'action' => 'update_candidate']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 6, 'action' => 'confirmation_required']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 7, 'action' => 'error']);
        $this->actingAs($user)->withSession($session)
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('新規登録候補')
            ->assertSee('更新候補')
            ->assertSee('重複確認が必要')
            ->assertSee('エラー')
            ->assertSee('この内容で取り込む')
            ->assertDontSee('会社の情報への反映はPremiumプランで利用できます。');

        $candidate = $batch->reviewCandidates()->firstOrFail();
        $this->actingAs($user)->withSession($session)
            ->post(route('imports.candidates.review', [$batch, $candidate]), ['status' => 'rejected'])
            ->assertRedirect();

        $this->actingAs($user)->withSession($session)
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHasErrors('sample');

        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => 'new-candidate@example.test']);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_import_completed',
        ]);
        Queue::assertNotPushed(ExecuteImportBatch::class);
    }

    public function test_unknown_sample_preview_is_not_available(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.samples.preview', 'unknown'))
            ->assertNotFound();
    }

    /** @return array{0: User, 1: Organization} */
    private function member(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'sample-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Sample '.$suffix, 'slug' => 'sample-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Operator, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
