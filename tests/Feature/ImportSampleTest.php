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
            foreach ($sample['headers'] as $header) {
                $this->assertSame($header, $aliases[$header] ?? null, $targetType.' sample column must map automatically: '.$header);
            }
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
                ->assertSee('サンプルプレビュー（取込なし）')
                ->assertSee('業務データの保存、AI処理、メール送信は行いません');
            foreach ($sample['headers'] as $index => $header) {
                $response->assertSee($header)
                    ->assertSee(config('opsdeck.import_column_aliases.'.$targetType.'.'.$header))
                    ->assertSee($sample['row'][$index]);
            }
        }

        foreach ($tableCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' must remain unchanged');
        }
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_free_member_can_preview_sample_import_classifications_but_cannot_execute_them(): void
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
            ->assertSee('Previewを準備しています');

        $this->app->make(ImportPreviewPreparationService::class)->prepare($batch->id);
        $this->assertSame('preview', $batch->refresh()->status);
        $this->assertSame(6, $batch->total_rows);

        $this->actingAs($user)->withSession($session)
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => [
                    'employee_number' => 'employee_number',
                    'name' => 'name',
                    'email' => 'email',
                    'employment_status' => 'employment_status',
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
            ->assertSee('Importの実行はPremiumプランで利用できます。')
            ->assertSee(route('billing.index'));

        $this->actingAs($user)->withSession($session)
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => 'new-candidate@example.test']);
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
