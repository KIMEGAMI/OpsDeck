<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\ExecuteImportBatch;
use App\Jobs\PrepareImportPreview;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ImpactReportMetricsService;
use App\Services\Import\ImportPreviewPreparationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ImportBatchControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'opsdeck.ai.usd_to_jpy_rate' => 150,
            'opsdeck.ai.model_prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'output' => 10.00],
            ],
        ]);
    }

    public function test_operator_sees_ai_mapping_candidate_reason_and_confidence_before_saving(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'headers_json' => ['氏名'],
            'status' => 'preview',
        ]);
        $reason = '列名と対象項目の意味が近いため <script>alert(1)</script>';
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '氏名', 'field' => 'name', 'confidence' => 0.96, 'reason' => $reason, 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai' => 1, 'ai_target_type' => 'employees']));

        $response->assertOk()
            ->assertSee('AI列マッピング候補')
            ->assertSee('保存後、行ごとの新規・更新・確認待ち・エラーを分析します。分析中はImportできません。業務データはこの操作では変更されません。')
            ->assertSee('取込元の列名・対象データ種別・OpsDeckの候補項目を設定済みのAIサービスへ送信します')
            ->assertSee('メールアドレス・電話番号らしき値は送信前に伏せます')
            ->assertSee('氏名などを含む場合は先に匿名化してください')
            ->assertSee('取込元の行データやファイル／シート全体は送信しません')
            ->assertSee('96%')
            ->assertSee('列名と対象項目の意味が近いため')
            ->assertSee('確認')
            ->assertSee('候補です。保存前に確認してください。')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $userMessage = $request->data()['input'] ?? null;
            $sentMappingContext = is_string($userMessage) ? json_decode($userMessage, true) : null;

            return $sentMappingContext === [
                'headers' => ['氏名'],
                'target_type' => 'employees',
                'target_fields' => array_values(array_unique(array_filter(config('opsdeck.import_column_aliases.employees', [])))),
            ];
        });
    }

    public function test_viewer_cannot_trigger_ai_mapping_suggestions(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'headers_json' => ['氏名'],
            'status' => 'preview',
        ]);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([], 200)]);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai' => 1, 'ai_target_type' => 'employees']))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_ai_mapping_suggestions_are_rate_limited_per_user_and_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'headers_json' => ['氏名'],
            'status' => 'preview',
        ]);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.mapping_suggestions_max_attempts' => 1,
        ]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '氏名', 'field' => 'name', 'confidence' => 0.96, 'reason' => '氏名を示す列名のため', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai' => 1, 'ai_target_type' => 'employees']))
            ->assertOk();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai' => 1, 'ai_target_type' => 'employees']))
            ->assertTooManyRequests();

        Http::assertSentCount(1);
    }

    public function test_ai_mapping_suggestions_share_an_organization_limit_across_members(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$firstUser, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $secondUser = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $secondUser->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'headers_json' => ['氏名'],
            'status' => 'preview',
        ]);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.mapping_suggestions_max_attempts' => 1,
        ]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '氏名', 'field' => 'name', 'confidence' => 0.96, 'reason' => '氏名を示す列名のため', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $this->actingAs($firstUser)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai' => 1, 'ai_target_type' => 'employees']))
            ->assertOk();
        $this->actingAs($secondUser)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai' => 1, 'ai_target_type' => 'employees']))
            ->assertTooManyRequests();

        Http::assertSentCount(1);
    }

    public function test_free_plan_import_page_shows_csv_preview_only(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create'))
            ->assertOk()
            ->assertSee('CSVファイル')
            ->assertSee('見出し範囲の開始行')
            ->assertSee('見出し範囲の終了行')
            ->assertSee('データ開始行（任意）')
            ->assertDontSee('XLSXファイル')
            ->assertDontSee('Importを実行します')
            ->assertSee('1回の取込は最大20,000行')
            ->assertSee('取込名・見出し範囲・必要ならデータ開始行・ファイルを選び、「プレビューを作成」を押します。')
            ->assertSee('Previewの準備後、Import詳細画面の「列マッピングを設定する」で対象データ種別と列の対応を設定します。')
            ->assertSee('マッピング分析後、新規・更新・確認待ち・エラーを確認し、必要な行だけを選択します。')
            ->assertSee('サンプル行をImportすると組織の業務データとして登録される');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.index'))
            ->assertOk()
            ->assertDontSee(route('imports.google-sheets'));
    }

    public function test_onboarding_import_target_is_prefilled_through_preview_to_column_mapping(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create', ['target_type' => 'employees']))
            ->assertOk()
            ->assertSee('導入チェックリスト：社員の管理表をプレビューします。')
            ->assertSee('name="target_type" value="employees"', false)
            ->assertSee('value="社員管理表"', false);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'target_type' => 'employees',
                'source_name' => '社員管理表',
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "employee_number,name,email,employment_status\nEMP-001,山田太郎,taro@example.test,active\n"),
            ]);

        $batch = ImportBatch::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
        $response->assertRedirect(route('imports.show', [$batch, 'target_type' => 'employees']));
        $this->assertSame('preview', $batch->refresh()->status);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', [$batch, 'target_type' => 'employees']))
            ->assertOk()
            ->assertSee(route('imports.mapping', [$batch, 'target_type' => 'employees']), false)
            ->assertSee('社員の列マッピングを設定する');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'target_type' => 'employees']))
            ->assertOk()
            ->assertSee('<option value="employees" selected>', false)
            ->assertSee('employee_number');
    }

    public function test_import_target_type_query_parameter_is_validated(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $previousImportBatchCount = ImportBatch::query()->count();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'target_type' => 'users;DROP TABLE employees',
                'source_name' => 'invalid target',
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "employee_number,name\nEMP-001,Test\n"),
            ])
            ->assertSessionHasErrors('target_type');

        $this->assertDatabaseCount('import_batches', $previousImportBatchCount);
    }

    public function test_import_header_row_number_is_bounded_before_the_upload_is_stored(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'source_name' => 'invalid header row',
                'header_row_number' => config('opsdeck.import.max_header_row_number') + 1,
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "name,email\nAlice,alice@example.test\n"),
            ])
            ->assertSessionHasErrors('header_row_number');

        $this->assertDatabaseMissing('import_batches', ['organization_id' => $organization->id]);
        $this->assertSame([], Storage::disk(config('opsdeck.import.disk'))->allFiles());
    }

    public function test_import_data_start_row_must_be_after_the_header_and_within_the_source_limit(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'source_name' => 'invalid data row',
                'header_row_number' => 3,
                'data_start_row_number' => 3,
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "title\nnotes\nname\nAlice\n"),
            ])
            ->assertSessionHasErrors('data_start_row_number');

        $this->assertDatabaseMissing('import_batches', ['organization_id' => $organization->id]);
        $this->assertSame([], Storage::disk(config('opsdeck.import.disk'))->allFiles());
    }

    public function test_import_data_start_row_must_be_after_the_complete_header_range(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'source_name' => 'invalid header range',
                'header_row_number' => 2,
                'header_end_row_number' => 4,
                'data_start_row_number' => 4,
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "group\nsubgroup\nname\nAlice\n"),
            ])
            ->assertSessionHasErrors('data_start_row_number');

        $this->assertDatabaseMissing('import_batches', ['organization_id' => $organization->id]);
        $this->assertSame([], Storage::disk(config('opsdeck.import.disk'))->allFiles());
    }

    public function test_premium_import_page_explains_xlsx_date_and_amount_handling(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create'))
            ->assertOk()
            ->assertSee('XLSXはセルの表示書式を見て日付をYYYY-MM-DDに変換します。')
            ->assertSee('通貨表示の金額は表示記号を外した数値で扱います。');
    }

    public function test_free_plan_cannot_upload_xlsx_preview(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'source_name' => 'XLSX preview',
                'file' => UploadedFile::fake()->create('employees.xlsx', 1, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('import_batches', ['organization_id' => $organization->id]);
    }

    public function test_free_plan_cannot_create_a_retry_batch_for_premium_import_execution(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $failedBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed-import', 'status' => 'failed']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $failedBatch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertSame(1, ImportBatch::query()->where('organization_id', $organization->id)->count());
        $this->assertSame('failed', $failedBatch->refresh()->status);
    }

    public function test_import_history_can_filter_by_status(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'history-preview', 'status' => 'preview']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.index', ['status' => 'preview']))
            ->assertOk()
            ->assertSee('history-preview')
            ->assertSee('CSVファイル')
            ->assertSee('プレビュー確認待ち')
            ->assertSee('>プレビュー確認待ち</option>', false)
            ->assertDontSee('>preview</option>', false);
    }

    public function test_import_history_pages_use_a_stable_order_when_creation_times_match(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => 1]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $this->travelTo('2030-10-01 12:00:00');
        $firstBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => '同時刻の取込1', 'status' => 'preview']);
        $secondBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => '同時刻の取込2', 'status' => 'preview']);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('imports.index', ['page' => 1]))
            ->assertOk()
            ->assertSee('同時刻の取込2')
            ->assertDontSee('同時刻の取込1');

        $this->actingAs($user)->withSession($session)
            ->get(route('imports.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('同時刻の取込1')
            ->assertDontSee('同時刻の取込2');
        $this->assertNotSame($firstBatch->id, $secondBatch->id);
    }

    public function test_import_history_error_filter_includes_partial_errors_and_failed_batches(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'completed-with-errors', 'status' => 'completed', 'error_rows' => 2]);
        $reviewedBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'reviewed-errors', 'status' => 'completed', 'error_rows' => 4]);
        $reviewedBatch->update(['errors_reviewed_at' => now()]);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed-batch', 'status' => 'failed']);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'clean-preview', 'status' => 'preview']);
        $olderBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'older-errors', 'status' => 'completed', 'error_rows' => 3]);
        $olderBatch->forceFill(['created_at' => now()->subDays(10)])->save();
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        ImportBatch::query()->create(['organization_id' => $otherOrganization->id, 'source_type' => 'csv', 'source_name' => 'other-organization-errors', 'status' => 'failed', 'error_rows' => 5]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.index', ['has_errors' => 1, 'created_after' => now()->subDays(config('opsdeck.weekly_summary_period_days'))->toDateString()]))
            ->assertOk()
            ->assertSee('completed-with-errors')
            ->assertSee('failed-batch')
            ->assertDontSee('clean-preview')
            ->assertDontSee('older-errors')
            ->assertDontSee('other-organization-errors');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.index', ['has_errors' => 1, 'unreviewed' => 1]))
            ->assertOk()
            ->assertSee('completed-with-errors')
            ->assertSee('failed-batch')
            ->assertDontSee('reviewed-errors');
    }

    public function test_operator_can_record_import_errors_as_reviewed_and_remove_them_from_open_metrics(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'review-me', 'status' => 'completed', 'error_rows' => 3]);

        $this->assertSame(3, app(ImpactReportMetricsService::class)->current($organization)['import_errors']);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('確認済みにする');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.review-errors', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $batch->refresh();
        $this->assertNotNull($batch->errors_reviewed_at);
        $this->assertSame($user->id, $batch->errors_reviewed_by);
        $this->assertSame(0, app(ImpactReportMetricsService::class)->current($organization)['import_errors']);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'entity_type' => ImportBatch::class,
            'entity_id' => $batch->id,
            'action' => AuditLog::ImportErrorsReviewed,
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.review-errors', $batch), ['confirm' => '1'])
            ->assertConflict();
    }

    public function test_viewer_cannot_mark_import_errors_as_reviewed(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'review-forbidden', 'status' => 'completed', 'error_rows' => 2]);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.review-errors', $batch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertNull($batch->refresh()->errors_reviewed_at);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $batch->id, 'action' => AuditLog::ImportErrorsReviewed]);
    }

    public function test_failed_batch_without_row_errors_is_visible_and_can_be_reviewed(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed-without-row-errors', 'status' => 'failed']);
        $metricsService = app(ImpactReportMetricsService::class);

        $this->assertSame(1, $metricsService->current($organization)['import_failed_batches']);
        $this->assertSame(1, $metricsService->current($organization)['unresolved_items']);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('エラーまたは実行失敗を確認');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.review-errors', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame(0, $metricsService->current($organization)['import_failed_batches']);
        $this->assertSame(0, $metricsService->current($organization)['unresolved_items']);
        $this->assertNotNull($batch->refresh()->errors_reviewed_at);
    }

    public function test_failed_import_detail_links_to_its_recovery_guide(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed-import', 'status' => 'failed']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('参照番号 IMP-'.$batch->id)
            ->assertSee(route('support.create', ['category' => 'import', 'error_reference' => 'IMP-'.$batch->id]))
            ->assertSee(route('help.recovery', 'import'));
    }

    public function test_operator_can_create_a_csv_preview_without_importing_business_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $employeeCountBefore = Employee::query()->count();
        $sourceContents = "社員管理台帳\n2026年度\nname,email\nnote,集計対象外\nAlice,a@example.test\nBob,b@example.test\n";

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.store'), [
            'source_name' => '社員CSV',
            'header_row_number' => 3,
            'data_start_row_number' => 5,
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $sourceContents),
        ]);

        $response->assertRedirect();
        $batch = ImportBatch::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('preview', $batch->status);
        $this->assertSame(3, $batch->header_row_number);
        $this->assertSame(5, $batch->data_start_row_number);
        $this->assertSame(['name', 'email'], $batch->headers_json);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, ImportRowResult::query()->where('import_batch_id', $batch->id)->count());
        $this->assertSame([5, 6], $batch->rowResults()->orderBy('source_row_number')->pluck('source_row_number')->all());
        $this->assertSame(hash('sha256', $sourceContents), $batch->source_file_sha256);
        $this->assertDatabaseCount('employees', $employeeCountBefore);
        Storage::disk(config('opsdeck.import.disk'))->assertExists($batch->source_file_path);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('元ファイル SHA-256')
            ->assertSee($batch->source_file_sha256);
    }

    public function test_unchanged_sample_csv_row_cannot_be_imported_as_business_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        Queue::fake([ExecuteImportBatch::class]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $sample = config('opsdeck.import_samples.employees');
        $samplePath = 'imports/'.$organization->id.'/opsdeck-sample-employees.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($samplePath, implode(',', $sample['headers'])."\n".implode(',', $sample['row'])."\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員サンプル',
            'source_file_path' => $samplePath,
            'headers_json' => $sample['headers'],
            'status' => 'ready',
            'total_rows' => 1,
        ]);
        $mapping = $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['employee_number' => 'employee_number', 'name' => 'name', 'email' => 'email', 'employment_status' => 'employment_status'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id]);
        ImportRowResult::query()->create([
            'import_batch_id' => $batch->id,
            'source_row_number' => 2,
            'target_type' => 'employees',
            'action' => 'new_candidate',
            'after_json' => ['employee_number' => 'EMP-SAMPLE-001', 'name' => 'サンプル太郎', 'email' => 'sample@example.test', 'employment_status' => 'active'],
            'is_selected' => true,
        ]);

        $response = $this->followingRedirects()->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('imports.show', $batch))
            ->post(route('imports.execute', $batch), ['confirm' => '1']);

        $response->assertOk()->assertSee('サンプルCSVはPreview専用です。');
        $this->assertDatabaseHas('import_batches', ['id' => $batch->id, 'status' => 'ready']);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => 'sample@example.test']);

        DB::table('import_row_results')->where('import_batch_id', $batch->id)->update(['is_selected' => false]);
        config(['opsdeck.import.async_threshold_rows' => 1]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('imports.show', $batch))
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHasErrors('sample');

        $this->assertDatabaseHas('import_batches', ['id' => $batch->id, 'status' => 'ready']);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => 'sample@example.test']);
        Queue::assertNotPushed(ExecuteImportBatch::class);
    }

    public function test_activation_requires_an_import_to_insert_or_update_business_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $runImport = function (string $sourceName, string $csv) use ($user, $organization): ImportBatch {
            $path = 'imports/'.$organization->id.'/'.$sourceName.'.csv';
            Storage::disk(config('opsdeck.import.disk'))->put($path, $csv);
            $batch = ImportBatch::query()->create([
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => $sourceName,
                'source_file_path' => $path,
                'headers_json' => ['name', 'email'],
                'status' => 'ready',
                'total_rows' => 1,
            ]);
            $mapping = $batch->mappings()->create([
                'target_type' => 'employees',
                'column_map' => ['name' => 'name', 'email' => 'email'],
                'normalization_rules' => [],
                'status' => 'ready',
                'created_by' => $user->id,
            ]);
            $batch->update(['mapping_id' => (string) $mapping->id]);
            $batch->rowResults()->create([
                'source_row_number' => 2,
                'target_type' => 'employees',
                'action' => 'new_candidate',
                'is_selected' => true,
            ]);

            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
                ->post(route('imports.execute', $batch), ['confirm' => '1'])
                ->assertRedirect(route('imports.show', $batch));

            return $batch->refresh();
        };

        $failedRowsBatch = $runImport('invalid-employees', "name,email\nBroken,not-an-email\n");
        $this->assertSame('completed', $failedRowsBatch->status);
        $this->assertSame(1, $failedRowsBatch->error_rows);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_import_completed',
        ]);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $failedRowsBatch))
            ->assertDontSee('導入効果レポートでImport前後を確認する');

        $successfulBatch = $runImport('valid-employees', "name,email\nValid,valid-".Str::uuid().'@example.test'."\n");
        $this->assertSame(1, $successfulBatch->inserted_rows);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $successfulBatch))
            ->assertSee('導入効果レポートでImport前後を確認する')
            ->assertSee(route('impact-report.show'));
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_import_completed',
        ]);
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_csv_upload_returns_while_preview_is_queued(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        Queue::fake([PrepareImportPreview::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.store'), [
            'source_name' => 'queued-preview.csv',
            'file' => UploadedFile::fake()->createWithContent('employees.csv', "name,email\nAlice,alice@example.test\n"),
        ])->assertRedirect();

        $batch = ImportBatch::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('previewing', $batch->status);
        $this->assertSame(0, $batch->total_rows);
        $this->assertSame(0, ImportRowResult::query()->where('import_batch_id', $batch->id)->count());
        Queue::assertPushed(PrepareImportPreview::class, fn (PrepareImportPreview $job): bool => $job->importBatchId === $batch->id);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('Previewを準備しています')
            ->assertSee('状態を更新')
            ->assertDontSee('列マッピングを設定する');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', $batch))
            ->assertStatus(422);
    }

    public function test_xlsx_sheet_selection_queues_preview_without_parsing_in_request(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $path = 'imports/'.$organization->id.'/employees.xlsx';
        Storage::disk(config('opsdeck.import.disk'))->put($path, 'preview parsing is deferred to the job');
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'employee-workbook',
            'source_file_path' => $path,
            'available_sheets_json' => ['社員一覧'],
            'status' => 'sheet_selection',
        ]);
        Queue::fake([PrepareImportPreview::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.sheet.store', $batch), ['sheet_name' => '社員一覧'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame('previewing', $batch->refresh()->status);
        Queue::assertPushed(PrepareImportPreview::class, fn (PrepareImportPreview $job): bool => $job->importBatchId === $batch->id);
    }

    public function test_google_sheet_selection_saves_the_selected_header_and_data_row_range(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'test-access-token', 'refresh_token' => 'test-refresh-token']);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => 'employee-sheet',
            'source_external_id' => 'spreadsheet-id',
            'available_sheets_json' => ['社員一覧'],
            'status' => 'sheet_selection',
        ]);
        Queue::fake([PrepareImportPreview::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.google-sheet.store', $batch), [
                'sheet_name' => '社員一覧',
                'header_row_number' => 2,
                'header_end_row_number' => 3,
                'data_start_row_number' => 5,
            ])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame('previewing', $batch->refresh()->status);
        $this->assertSame(2, $batch->header_row_number);
        $this->assertSame(3, $batch->header_end_row_number);
        $this->assertSame(5, $batch->data_start_row_number);
        Queue::assertPushed(PrepareImportPreview::class, fn (PrepareImportPreview $job): bool => $job->importBatchId === $batch->id);
    }

    public function test_operator_can_create_a_separate_preview_for_another_sheet_without_reuploading(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $sourcePath = 'imports/'.$organization->id.'/company-register.xlsx';
        Storage::disk(config('opsdeck.import.disk'))->put($sourcePath, 'the workbook stays in private storage');
        $sourceBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => '会社台帳',
            'source_file_path' => $sourcePath,
            'available_sheets_json' => ['社員一覧', '資産一覧'],
            'selected_sheet_name' => '社員一覧',
            'status' => 'preview',
        ]);
        Queue::fake([PrepareImportPreview::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $sourceBatch))
            ->assertOk()
            ->assertSee('このXLSXの別シートを取り込む')
            ->assertSee('社員・資産などの関係を自動で結合しません。');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.sheet.additional', $sourceBatch), [
                'sheet_name' => '資産一覧',
                'header_row_number' => 3,
                'data_start_row_number' => 5,
            ])
            ->assertRedirect();

        $additionalBatch = ImportBatch::query()
            ->where('organization_id', $organization->id)
            ->where('id', '!=', $sourceBatch->id)
            ->firstOrFail();
        $this->assertSame($sourcePath, $additionalBatch->source_file_path);
        $this->assertSame('資産一覧', $additionalBatch->selected_sheet_name);
        $this->assertSame(3, $additionalBatch->header_row_number);
        $this->assertSame(5, $additionalBatch->data_start_row_number);
        $this->assertSame('previewing', $additionalBatch->status);
        Queue::assertPushed(PrepareImportPreview::class, fn (PrepareImportPreview $job): bool => $job->importBatchId === $additionalBatch->id);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $sourceBatch))
            ->assertOk()
            ->assertDontSee('このXLSXの別シートを取り込む');
    }

    public function test_additional_sheet_import_rejects_sheets_already_used_from_the_same_workbook(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $sourcePath = 'imports/'.$organization->id.'/company-register.xlsx';
        Storage::disk(config('opsdeck.import.disk'))->put($sourcePath, 'the workbook stays in private storage');
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => '会社台帳',
            'source_file_path' => $sourcePath,
            'available_sheets_json' => ['社員一覧', '資産一覧'],
            'selected_sheet_name' => '社員一覧',
            'status' => 'preview',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.sheet.additional', $batch), ['sheet_name' => '社員一覧'])
            ->assertStatus(409);

        $this->assertSame(1, ImportBatch::query()->where('source_file_path', $sourcePath)->count());
    }

    public function test_viewer_cannot_create_an_additional_sheet_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $sourcePath = 'imports/'.$organization->id.'/company-register.xlsx';
        Storage::disk(config('opsdeck.import.disk'))->put($sourcePath, 'the workbook stays in private storage');
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => '会社台帳',
            'source_file_path' => $sourcePath,
            'available_sheets_json' => ['社員一覧', '資産一覧'],
            'selected_sheet_name' => '社員一覧',
            'status' => 'preview',
        ]);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.sheet.additional', $batch), ['sheet_name' => '資産一覧'])
            ->assertForbidden();

        $this->assertSame(1, ImportBatch::query()->where('source_file_path', $sourcePath)->count());
    }

    public function test_xlsx_preview_streams_rows_into_preview_records(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $sourcePath = 'imports/'.$organization->id.'/preview.xlsx';
        $disk = Storage::disk(config('opsdeck.import.disk'));
        $disk->makeDirectory(dirname($sourcePath));
        $archive = new ZipArchive;
        $this->assertSame(true, $archive->open($disk->path($sourcePath), ZipArchive::CREATE) === true);
        $archive->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="社員一覧" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>名前</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Alice</t></is></c></row><row r="4"/><row r="5"><c r="A5" t="inlineStr"><is><t>Bob</t></is></c></row></sheetData></worksheet>');
        $archive->close();
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'employee-preview.xlsx',
            'source_file_path' => $sourcePath,
            'selected_sheet_name' => '社員一覧',
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);

        app(ImportPreviewPreparationService::class)->prepare($batch->id);

        $this->assertSame('preview', $batch->refresh()->status);
        $this->assertSame(['名前'], $batch->headers_json);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame([2, 5], ImportRowResult::query()->where('import_batch_id', $batch->id)->orderBy('source_row_number')->pluck('source_row_number')->all());
    }

    public function test_failed_csv_preview_can_be_retried_once_on_the_free_plan(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/retry-preview.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'failed-preview.csv',
            'source_file_path' => $path,
            'status' => 'previewing',
        ]);
        $previewJob = new PrepareImportPreview($batch->id);
        $processingFailure = null;
        try {
            $previewJob->handle(app(ImportPreviewPreparationService::class));
        } catch (\Throwable $exception) {
            $processingFailure = $exception;
        }
        $this->assertNotNull($processingFailure);
        $previewJob->failed($processingFailure);
        $this->assertSame('failed', $batch->refresh()->status);
        Storage::disk(config('opsdeck.import.disk'))->assertExists($path);
        Queue::fake([PrepareImportPreview::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame('previewing', $batch->refresh()->status);
        Queue::assertPushedTimes(PrepareImportPreview::class, 1);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $batch), ['confirm' => '1'])
            ->assertStatus(422);
        Queue::assertPushedTimes(PrepareImportPreview::class, 1);
    }

    public function test_large_csv_preview_inserts_rows_in_configured_chunks(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.preview_insert_chunk_size' => 200]);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $insertStatements = 0;
        DB::listen(static function (QueryExecuted $query) use (&$insertStatements): void {
            $normalizedSql = strtolower(ltrim($query->sql));
            if (str_starts_with($normalizedSql, 'insert into') && str_contains($normalizedSql, 'import_row_results')) {
                $insertStatements++;
            }
        });
        $rows = array_map(static fn (int $row): string => 'Employee '.$row.',employee'.$row.'@example.test', range(1, 501));
        $csv = "name,email\n".implode("\n", $rows)."\n";

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.store'), [
            'source_name' => 'large-employees-csv',
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ])->assertRedirect();

        $batch = ImportBatch::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(501, $batch->total_rows);
        $this->assertSame(501, ImportRowResult::query()->where('import_batch_id', $batch->id)->count());
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 201, 'target_type' => 'unmapped', 'action' => 'preview']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 401, 'target_type' => 'unmapped', 'action' => 'preview']);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 502, 'target_type' => 'unmapped', 'action' => 'preview']);
        $this->assertSame(3, $insertStatements);
    }

    public function test_import_at_the_async_threshold_is_queued_once_without_importing_in_the_request(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $threshold = max(1, (int) config('opsdeck.import.async_threshold_rows'));
        $email = 'async-import-'.Str::lower(Str::random(config('opsdeck.slug_suffix_length'))).'@example.test';
        $path = 'imports/'.$organization->id.'/async-employees.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nAsync Employee,$email\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'async-employees',
            'source_file_path' => $path,
            'headers_json' => ['name', 'email'],
            'status' => 'ready',
            'total_rows' => $threshold,
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        Queue::fake([ExecuteImportBatch::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHas('status', 'Importを受け付けました。処理状況はこの画面で確認できます。');

        $this->assertDatabaseHas('import_batches', ['id' => $batch->id, 'status' => 'queued', 'executed_by' => $user->id]);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => $email]);
        Queue::assertPushed(ExecuteImportBatch::class, fn (ExecuteImportBatch $job): bool => $job->importBatchId === $batch->id && $job->userId === $user->id);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch->refresh()), ['confirm' => '1'])
            ->assertStatus(422);

        $this->assertSame('queued', $batch->refresh()->status);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => $email]);
        Queue::assertPushedTimes(ExecuteImportBatch::class, 1);
    }

    public function test_row_selection_updates_only_visible_selectable_rows_for_a_ready_batch(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => 2]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'selection-test',
            'status' => 'ready',
        ]);
        foreach ([
            ['source_row_number' => 2, 'action' => 'new_candidate', 'is_selected' => true],
            ['source_row_number' => 3, 'action' => 'confirmation_required', 'is_selected' => false],
            ['source_row_number' => 4, 'action' => 'error', 'is_selected' => true],
            ['source_row_number' => 5, 'action' => 'inserted', 'is_selected' => true],
        ] as $row) {
            ImportRowResult::query()->create($row + [
                'import_batch_id' => $batch->id,
                'target_type' => 'employees',
            ]);
        }

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.rows.selection', $batch), [
                'visible_row_numbers' => [2, 3],
                'selected_row_numbers' => [3],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 2, 'is_selected' => false]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 3, 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 4, 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 5, 'is_selected' => true]);
    }

    public function test_row_selection_rejects_a_queued_batch_without_changing_rows(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'queued-selection-test',
            'status' => 'queued',
        ]);
        $row = ImportRowResult::query()->create([
            'import_batch_id' => $batch->id,
            'source_row_number' => 2,
            'target_type' => 'employees',
            'action' => 'new_candidate',
            'is_selected' => true,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.rows.selection', $batch), ['visible_row_numbers' => [2]])
            ->assertUnprocessable();

        $this->assertDatabaseHas('import_row_results', ['id' => $row->id, 'is_selected' => true]);
    }

    public function test_row_selection_rejects_selected_rows_outside_the_visible_page(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'invalid-selection-test',
            'status' => 'preview',
        ]);
        $row = ImportRowResult::query()->create([
            'import_batch_id' => $batch->id,
            'source_row_number' => 2,
            'target_type' => 'employees',
            'action' => 'new_candidate',
            'is_selected' => true,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.rows.selection', $batch), [
                'visible_row_numbers' => [2],
                'selected_row_numbers' => [3],
            ])
            ->assertSessionHasErrors('selected_row_numbers.0');

        $this->assertDatabaseHas('import_row_results', ['id' => $row->id, 'is_selected' => true]);
    }

    public function test_import_details_paginate_rows_and_review_candidates(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'paginated-import-details',
            'status' => 'preview',
        ]);
        $perPage = (int) config('opsdeck.pagination.per_page');
        for ($sourceRowNumber = 2; $sourceRowNumber <= $perPage + 2; $sourceRowNumber++) {
            ImportRowResult::query()->create([
                'import_batch_id' => $batch->id,
                'source_row_number' => $sourceRowNumber,
                'target_type' => 'employees',
                'action' => 'preview',
            ]);
            $batch->reviewCandidates()->create([
                'candidate_type' => 'duplicate',
                'source_row_numbers_json' => [$sourceRowNumber],
                'confidence' => 0.5,
                'details_json' => [],
                'status' => 'pending',
            ]);
        }

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', [
                'importBatch' => $batch,
                'row_results_page' => 2,
                'review_candidates_page' => 2,
            ]));

        $response->assertOk()
            ->assertSee('状態: プレビュー確認待ち / 種別: CSVファイル')
            ->assertSee('重複候補')
            ->assertSee('未確認')
            ->assertDontSee('duplicate')
            ->assertDontSee('pending')
            ->assertViewHas('rowResults', fn ($rows): bool => $rows->currentPage() === 2 && $rows->count() === 1)
            ->assertViewHas('reviewCandidates', fn ($candidates): bool => $candidates->currentPage() === 2 && $candidates->count() === 1)
            ->assertViewHas('selectableRowResults', fn ($rows): bool => $rows->count() === 1);
    }

    public function test_csv_preview_counts_a_quoted_comma_as_one_row(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.store'), [
            'source_name' => 'Quoted CSV',
            'file' => UploadedFile::fake()->createWithContent('employees.csv', "name,email\r\n\"Tokyo, \"\"HQ\"\" \\\\ branch\",quoted@example.test\r\n"),
        ]);

        $response->assertRedirect();
        $batch = ImportBatch::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(1, $batch->total_rows);
    }

    public function test_operator_can_preview_a_shift_jis_csv_without_mojibake(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $csv = mb_convert_encoding("氏名,メール\r\n山田太郎,taro@example.test\r\n", 'SJIS-win', 'UTF-8');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'source_name' => 'Windows Excel CSV',
                'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
            ])
            ->assertRedirect();

        $batch = ImportBatch::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(['氏名', 'メール'], $batch->headers_json);
        $this->assertSame(1, $batch->total_rows);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai_target_type' => 'employees']))
            ->assertOk()
            ->assertSee('氏名')
            ->assertSee('メール');
    }

    public function test_viewer_cannot_create_a_csv_preview(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('imports.create'))->assertForbidden();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('imports.samples.preview', 'employees'))->assertForbidden();
    }

    public function test_operator_role_in_another_organization_does_not_allow_import_creation(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        Queue::fake([PrepareImportPreview::class]);
        [$user] = $this->member(MembershipRole::Operator);
        [, $selectedOrganization] = $this->member(MembershipRole::Viewer);
        OrganizationMembership::query()->create(['organization_id' => $selectedOrganization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $selectedOrganization->id])
            ->get(route('imports.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('imports.create').'"', false);
        $this->actingAs($user)->withSession(['current_organization_id' => $selectedOrganization->id])
            ->get(route('imports.create'))
            ->assertForbidden();
        $this->actingAs($user)->withSession(['current_organization_id' => $selectedOrganization->id])
            ->get(route('imports.samples.preview', 'employees'))
            ->assertForbidden();

        $this->actingAs($user)->withSession(['current_organization_id' => $selectedOrganization->id])
            ->post(route('imports.store'), [
                'source_name' => 'unauthorized.csv',
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "name,email\nAlice,a@example.test\n"),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('import_batches', ['organization_id' => $selectedOrganization->id]);
        $this->assertSame([], Storage::disk(config('opsdeck.import.disk'))->allFiles());
        Queue::assertNotPushed(PrepareImportPreview::class);
    }

    public function test_import_preview_is_tenant_scoped(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create(['organization_id' => $otherOrganization->id, 'source_type' => 'csv', 'source_name' => 'other', 'status' => 'preview']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('imports.show', $batch))->assertNotFound();
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'import-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
