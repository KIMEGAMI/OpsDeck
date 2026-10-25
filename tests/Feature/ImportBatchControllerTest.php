<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\ExecuteImportBatch;
use App\Jobs\PrepareImportFileDiffPreview;
use App\Jobs\PrepareImportMappingAnalysis;
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
use App\Services\Import\ImportFileDiffPreviewPreparationService;
use App\Services\Import\ImportMappingAnalysisService;
use App\Services\Import\ImportPreviewPreparationService;
use App\Services\Import\ImportSourceIntegrityService;
use App\Services\Import\XlsxImportSource;
use App\Services\ImportBatchExecutionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write_input' => 0.125, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'cache_write_input' => 2.50, 'output' => 10.00],
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

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'ai' => 1, 'ai_target_type' => 'employees']))
            ->assertOk()
            ->assertDontSee('AIが提案した列の対応</h2>');
        Http::assertNothingSent();

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees']);

        $response->assertOk()
            ->assertSee('AIが提案した列の対応')
            ->assertSee('自動で選ばれた項目も、必ず元の表と見比べてください。')
            ->assertSee('会社のデータはまだ変わりません。')
            ->assertSee('ボタンを押すと、取込元の列名・対象データ種別・候補項目だけを設定済みのAIサービスへ送信します。')
            ->assertSee('列名にメールアドレス・電話番号らしき値があれば伏せます')
            ->assertSee('氏名などが含まれる場合は、先に列名を匿名化してください。')
            ->assertSee('送信せず、手動で列を設定することもできます。')
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
                'target_fields' => array_values(array_unique(array_filter(array_merge(
                    config('opsdeck.import_mapping_fields.employees', []),
                    array_values(config('opsdeck.import_column_aliases.employees', [])),
                ), 'is_string'))),
            ];
        });
    }

    public function test_manual_mapping_can_be_saved_after_ai_provider_timeout(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Queue::fake([PrepareImportMappingAnalysis::class]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'headers_json' => ['氏名'],
            'status' => 'preview',
        ]);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        $providerWasContacted = false;
        Http::fake(static function () use (&$providerWasContacted): never {
            $providerWasContacted = true;
            throw new ConnectionException('provider request timed out');
        });

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees']);

        $response->assertOk()
            ->assertSee('AIサービスから候補を取得できませんでした。')
            ->assertSee('列名を確認して手動で設定してください。')
            ->assertSee('id="column_0" name="column_map[氏名]"', false)
            ->assertSee('name="target_type"', false)
            ->assertSee(route('imports.mapping.store', $batch), false);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => ['氏名' => 'name'],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->latest('id')->firstOrFail();
        $this->assertSame(['氏名' => 'name'], $mapping->column_map);
        $this->assertSame('analyzing', $batch->refresh()->status);
        Queue::assertPushedTimes(PrepareImportMappingAnalysis::class, 1);
        $this->assertTrue($providerWasContacted);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'purpose' => 'import_column_mapping',
            'success' => false,
            'error_code' => 'provider_unavailable',
        ]);
    }

    public function test_ai_mapping_is_reviewed_corrected_and_imported_without_duplicate_rows_on_job_retry(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        Queue::fake();
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $email = 'ai-reviewed-'.Str::uuid().'@example.test';
        $path = 'imports/'.$organization->id.'/ai-reviewed-'.Str::uuid().'.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "氏名,メール\n人が確認した社員,$email\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員一覧.csv',
            'source_file_path' => $path,
            'headers_json' => ['氏名', 'メール'],
            'status' => 'preview',
            'total_rows' => 1,
        ]);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '氏名', 'field' => 'employee_number', 'confidence' => 0.99, 'reason' => '氏名列と誤って判断した候補', 'requires_review' => false, 'question' => null],
                ['source_column' => 'メール', 'field' => 'email', 'confidence' => 0.99, 'reason' => 'メール列の候補', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $suggestionResponse = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees']);

        $suggestionResponse->assertOk()
            ->assertSee('氏名列と誤って判断した候補')
            ->assertSee('候補です。保存前に確認してください。')
            ->assertSee('社員番号')
            ->assertSee('会社のデータはまだ変わりません。');
        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(0, ImportRowResult::query()->where('import_batch_id', $batch->id)->count());
        $this->assertSame(1, Http::recorded()->count());

        $tokenMatches = preg_match('/name="ai_mapping_feedback_token" value="([a-f0-9]{64})"/', $suggestionResponse->getContent(), $tokenCapture);
        $this->assertSame(1, $tokenMatches);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.store', $batch), [
                'target_type' => 'employees',
                'column_map' => ['氏名' => 'name', 'メール' => 'email'],
                'ai_mapping_feedback_token' => $tokenCapture[1],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->latest('id')->firstOrFail();
        $this->assertSame(['氏名' => 'name', 'メール' => 'email'], $mapping->column_map);
        $this->assertSame(2, $mapping->ai_suggestion_count);
        $this->assertSame(1, $mapping->ai_suggestion_accepted_count);
        $this->assertSame('analyzing', $batch->refresh()->status);

        (new PrepareImportMappingAnalysis($batch->id, $mapping->id))->handle(app(ImportMappingAnalysisService::class));
        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame(1, $batch->preview_new_rows);
        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());

        config(['opsdeck.import.async_threshold_rows' => 1]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));
        $this->assertSame('queued', $batch->refresh()->status);
        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());

        $job = new ExecuteImportBatch($batch->id, $user->id);
        $executionService = app(ImportBatchExecutionService::class);
        $job->handle($executionService);
        $job->handle($executionService);

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertDatabaseHas('employees', [
            'organization_id' => $organization->id,
            'name' => '人が確認した社員',
            'email' => $email,
            'employee_number' => null,
        ]);
        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(1, ImportRowResult::query()->where('import_batch_id', $batch->id)->count());
    }

    public function test_ai_mapping_supports_every_import_type_and_all_allowed_fields(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'assets.csv',
            'headers_json' => ['製造番号'],
            'status' => 'preview',
        ]);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '製造番号', 'field' => 'serial_number', 'confidence' => 0.95, 'reason' => '製造番号を示す列です', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'assets']);

        $response->assertOk()
            ->assertSee('id="ai_target_type" name="ai_target_type"', false)
            ->assertSee('端末・資産')
            ->assertSee('value="questionnaire_answers"', false)
            ->assertSee('選んだ一覧の列名をAIへ送って候補を作る')
            ->assertSee('製造番号を示す列です');
        Http::assertSent(function (Request $request): bool {
            $userMessage = $request->data()['input'] ?? null;
            $sentMappingContext = is_string($userMessage) ? json_decode($userMessage, true) : null;
            $targetFields = array_values(array_unique(array_merge(
                config('opsdeck.import_mapping_fields.assets'),
                array_values(config('opsdeck.import_column_aliases.assets')),
            )));

            return is_array($sentMappingContext)
                && $sentMappingContext['target_type'] === 'assets'
                && $sentMappingContext['target_fields'] === $targetFields
                && in_array('serial_number', $sentMappingContext['target_fields'], true);
        });
    }

    public function test_ai_mapping_review_counts_only_exactly_adopted_candidates(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Queue::fake([PrepareImportMappingAnalysis::class]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $headers = ['氏名', '社員番号'];
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'headers_json' => $headers,
            'status' => 'preview',
        ]);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '氏名', 'field' => 'name', 'confidence' => 0.96, 'reason' => '氏名列', 'requires_review' => false, 'question' => null],
                ['source_column' => '社員番号', 'field' => 'employee_number', 'confidence' => 0.90, 'reason' => '番号列', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ], 200)]);

        $aiResponse = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees'])
            ->assertOk();
        $tokenMatches = preg_match('/name="ai_mapping_feedback_token" value="([a-f0-9]{64})"/', $aiResponse->getContent(), $tokenCapture);
        $this->assertSame(1, $tokenMatches);

        $this->actingAs($user)->post(route('imports.mapping.store', $batch), [
            'target_type' => 'employees',
            'column_map' => ['氏名' => 'name', '社員番号' => 'department_name'],
            'ai_mapping_feedback_token' => $tokenCapture[1],
        ])->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->latest('id')->firstOrFail();
        $this->assertSame(2, $mapping->ai_suggestion_count);
        $this->assertSame(1, $mapping->ai_suggestion_accepted_count);
        Http::assertSentCount(1);
    }

    public function test_ai_mapping_feedback_tokens_keep_concurrent_suggestion_runs_separate(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Queue::fake([PrepareImportMappingAnalysis::class]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['ai_enabled' => true, 'plan_code' => config('opsdeck.premium_plan_code')]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'headers_json' => ['社員番号'],
            'status' => 'preview',
        ]);
        config([
            'opsdeck.ai.enabled' => true,
            'opsdeck.ai.provider' => 'openai',
            'opsdeck.ai.api_key' => 'test-key',
            'opsdeck.ai.escalation_enabled' => false,
        ]);
        $responseFor = static fn (string $field): array => [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['suggestions' => [
                ['source_column' => '社員番号', 'field' => $field, 'confidence' => 0.96, 'reason' => '候補', 'requires_review' => false, 'question' => null],
            ]])]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ];
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push($responseFor('employee_number'), 200)->push($responseFor('department_name'), 200)]);

        $firstSuggestionResponse = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees'])
            ->assertOk();
        $firstTokenMatches = preg_match('/name="ai_mapping_feedback_token" value="([a-f0-9]{64})"/', $firstSuggestionResponse->getContent(), $firstTokenCapture);
        $this->assertSame(1, $firstTokenMatches);

        $secondSuggestionResponse = $this->actingAs($user)
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees'])
            ->assertOk();
        $secondTokenMatches = preg_match('/name="ai_mapping_feedback_token" value="([a-f0-9]{64})"/', $secondSuggestionResponse->getContent(), $secondTokenCapture);
        $this->assertSame(1, $secondTokenMatches);
        $this->assertNotSame($firstTokenCapture[1], $secondTokenCapture[1]);

        $this->actingAs($user)->post(route('imports.mapping.store', $batch), [
            'target_type' => 'employees',
            'column_map' => ['社員番号' => 'employee_number'],
            'ai_mapping_feedback_token' => $firstTokenCapture[1],
        ])->assertRedirect(route('imports.show', $batch));

        $mapping = $batch->mappings()->latest('id')->firstOrFail();
        $this->assertSame(1, $mapping->ai_suggestion_count);
        $this->assertSame(1, $mapping->ai_suggestion_accepted_count);
        Http::assertSentCount(2);
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
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees'])
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
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees'])
            ->assertOk();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees'])
            ->assertOk()
            ->assertSee('AI候補の取得回数が上限に達しました。')
            ->assertSee('列名を確認して手動で設定してください。')
            ->assertSee('id="column_0" name="column_map[氏名]"', false);

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
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees'])
            ->assertOk();
        $this->actingAs($secondUser)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.mapping.ai', $batch), ['ai' => '1', 'ai_target_type' => 'employees'])
            ->assertOk()
            ->assertSee('AI候補の取得回数が上限に達しました。')
            ->assertSee('列名を確認して手動で設定してください。')
            ->assertSee('id="column_0" name="column_map[氏名]"', false);

        Http::assertSentCount(1);
    }

    public function test_ai_mapping_action_is_only_shown_when_the_plan_organization_and_service_are_ready(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'headers_json' => ['氏名'],
            'status' => 'preview',
        ]);
        config(['opsdeck.ai.enabled' => true, 'opsdeck.ai.provider' => 'openai', 'opsdeck.ai.api_key' => 'test-key']);
        Http::fake();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', $batch))
            ->assertOk()
            ->assertSee('AI候補はPremiumプランで利用できます。')
            ->assertDontSee('選んだ一覧の列名をAIへ送って候補を作る');

        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active', 'ai_enabled' => false]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', $batch))
            ->assertOk()
            ->assertSee('この組織ではAIの提案を使えません。')
            ->assertDontSee('選んだ一覧の列名をAIへ送って候補を作る');

        $organization->update(['ai_enabled' => true]);
        config(['opsdeck.ai.enabled' => false]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', $batch))
            ->assertOk()
            ->assertSee('AIサービスの接続設定がないため、今は候補を取得できません。')
            ->assertDontSee('選んだ一覧の列名をAIへ送って候補を作る');

        config(['opsdeck.ai.enabled' => true]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', $batch))
            ->assertOk()
            ->assertSee(route('imports.mapping.ai', $batch), false)
            ->assertSee('選んだ一覧の列名をAIへ送って候補を作る');

        Http::assertNothingSent();
    }

    public function test_import_row_errors_are_actionable_and_do_not_expose_internal_english_messages(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '契約一覧',
            'headers_json' => ['契約名', '金額'],
            'status' => 'preview',
        ]);
        $englishErrors = [
            'amount must be non-negative, have up to two decimal places and fit the supported maximum',
            'service_name, employee_email and mfa_status must resolve to valid organization data',
            'ended_at must be on or after assigned_at',
            'control_code, title, category, description and a valid status are required',
            'next_review_at must be on or after last_reviewed_at',
            'unexpected_internal_import_error',
            '日付の形式を確認してください。',
        ];
        foreach ($englishErrors as $index => $error) {
            $batch->rowResults()->create([
                'source_row_number' => $index + 2,
                'target_type' => 'contracts',
                'action' => 'error',
                'errors_json' => ['row' => $error],
            ]);
        }
        $batch->rowResults()->create([
            'source_row_number' => 20,
            'target_type' => 'contracts',
            'action' => 'new_candidate',
            'after_json' => ['contract_name' => '確認候補'],
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('金額は0以上、小数点以下2桁までで入力してください。上限も確認してください。')
            ->assertSee('クラウドサービス名・社員メールアドレス・多要素認証の状態を確認してください。')
            ->assertSee('終了日は割当日以降の日付にしてください。')
            ->assertSee('管理項目コード・タイトル・分類・説明を入力し、有効な対応状況を選んでください。')
            ->assertSee('次回確認日は最終確認日以降の日付にしてください。')
            ->assertSee('この行を取り込めないため、項目と入力形式を確認してください。')
            ->assertSee('日付の形式を確認してください。')
            ->assertSee(route('imports.errors.download', $batch), false)
            ->assertSee('氏名・メールアドレスなどの業務データは含みません。')
            ->assertSee('このページのエラー以外の行をすべて選択')
            ->assertSee('一括選択・解除は表示中ページのエラー以外の行に反映され、エラー行のチェック状態は変わりません。')
            ->assertSee('data-selectable-import-row="false"', false)
            ->assertSee('data-selectable-import-row="true"', false)
            ->assertDontSee('amount must be non-negative')
            ->assertDontSee('unexpected_internal_import_error');
    }

    public function test_operator_can_download_minimal_import_error_csv_with_spreadsheet_formula_protection(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'employees.csv',
            'status' => 'completed',
            'error_rows' => 3,
        ]);
        $batch->rowResults()->create([
            'source_row_number' => 2,
            'target_type' => 'employees',
            'action' => 'error',
            'errors_json' => ['row' => 'spreadsheet_formula_test'],
        ]);
        $batch->rowResults()->create([
            'source_row_number' => 3,
            'target_type' => 'employees',
            'action' => 'error',
            'errors_json' => ['row' => '日付の形式を確認してください。'],
            'after_json' => ['email' => 'private-row@example.test'],
        ]);
        $batch->rowResults()->create([
            'source_row_number' => 4,
            'target_type' => 'employees',
            'action' => 'error',
            'errors_json' => ['row' => 'unicode_spreadsheet_formula_test'],
        ]);
        $batch->rowResults()->create([
            'source_row_number' => 5,
            'target_type' => 'employees',
            'action' => 'new_candidate',
            'after_json' => ['email' => 'successful-row@example.test'],
        ]);
        config([
            'opsdeck.import_error_labels.spreadsheet_formula_test' => '=HYPERLINK("cmd","悪意のある数式")',
            'opsdeck.import_error_labels.unicode_spreadsheet_formula_test' => '　＝HYPERLINK("cmd","全角数式")',
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.errors.download', $batch));

        $response->assertOk()
            ->assertDownload('opsdeck-import-errors-'.$batch->id.'.csv')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("\t=HYPERLINK", $csv);
        $this->assertStringContainsString("\t　＝HYPERLINK", $csv);
        $this->assertStringContainsString('日付の形式を確認してください。', $csv);
        $this->assertStringContainsString('悪意のある数式', $csv);
        $this->assertStringContainsString('全角数式', $csv);
        $this->assertStringNotContainsString('private-row@example.test', $csv);
        $this->assertStringNotContainsString('successful-row@example.test', $csv);
    }

    public function test_import_error_csv_is_scoped_to_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $otherOrganization->id,
            'source_type' => 'csv',
            'source_name' => 'other-organization.csv',
            'status' => 'completed',
            'error_rows' => 1,
        ]);
        $batch->rowResults()->create([
            'source_row_number' => 2,
            'target_type' => 'employees',
            'action' => 'error',
            'errors_json' => ['row' => '日付の形式を確認してください。'],
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.errors.download', $batch))
            ->assertNotFound();
    }

    public function test_free_plan_import_page_explains_csv_registration_limits(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create'))
            ->assertOk()
            ->assertSee('aria-label="Excelなどの取込手順"', false)
            ->assertSee('aria-current="step"', false)
            ->assertSee('まずは3つだけ')
            ->assertSee('href="#import-upload-form"', false)
            ->assertSee('id="import-upload-form"', false)
            ->assertSee('見本を登録せずに操作を試せます。')
            ->assertSee('<a href="'.route('imports.samples.preview', 'employees').'">社員のサンプルを登録せずに確認する</a>', false)
            ->assertSee('<summary>Excel・社内管理表を取り込む詳しい手順とサンプル</summary>', false)
            ->assertSee('取込履歴・元ファイル・行ごとの確認結果は、あとで見直したり取込を取り消したりできるよう、期限を設けず保存します。')
            ->assertSee('業務に不要な個人情報は含めないでください。')
            ->assertSeeInOrder([
                '元のExcelは残したままにします。',
                '取込なしでプレビュー',
                'あとで履歴から探すための名前',
                '内容を確認する（まだ登録しません）',
            ])
            ->assertSee('1. 表を選ぶ')
            ->assertSee('CSVファイル')
            ->assertSee('列の名前が書かれた最初の行')
            ->assertSee('列の名前が書かれた最後の行')
            ->assertSee('データが始まる行（空欄でOK）')
            ->assertSee('このプランで選べる形式はCSV / TXTです。')
            ->assertSee('Excel（.xlsx）を直接選ぶにはPremiumが必要です。')
            ->assertSee('古い形式のExcel（.xls）も使えません。')
            ->assertSee('FreeでもCSV/TXTから実データを登録できます。')
            ->assertSee('社員20名、資産50件、SaaSサービス10件まで登録できます。')
            ->assertSee('<details>', false)
            ->assertDontSee('<details open', false)
            ->assertSee('<summary>表の上にタイトルなどがある場合（通常は設定不要）</summary>', false)
            ->assertDontSee('XLSXファイル')
            ->assertSee('最後に取込を実行します。結果はいつでも履歴から確認できます。')
            ->assertSee('1回に確認できるのは最大20,000行')
            ->assertSee('列名が1行目にあり、そのすぐ下からデータが始まる表なら、ここは変更しなくて大丈夫です。')
            ->assertSee('内容を確認する（まだ登録しません）')
            ->assertSee('今使っている社員名簿。後で履歴を見たとき、何の表か分かる名前にしてください。')
            ->assertDontSee('2026年10月')
            ->assertSee('Excelファイルを使う場合')
            ->assertSee('CSV UTF-8')
            ->assertSee('この時点では会社のデータは登録・変更されません。')
            ->assertSee('どの列を「社員名」「部署」などとして扱うか選びます。')
            ->assertSee('実際の会社データは入っていません。');

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
            ->assertSee('導入チェックリスト：社員の表を事前確認します。')
            ->assertSee('<a href="'.route('imports.samples.preview', 'employees').'">社員のサンプルを登録せずに確認する</a>', false)
            ->assertSee('name="target_type" value="employees"', false)
            ->assertSee('value="社員管理表"', false);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create', ['target_type' => 'assets']))
            ->assertOk()
            ->assertSee('<a href="'.route('imports.samples.preview', 'assets').'">端末・資産のサンプルを登録せずに確認する</a>', false);

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
            ->assertSee('2. 列の意味を合わせる')
            ->assertSee('aria-current="step"', false)
            ->assertSee(route('imports.mapping', [$batch, 'target_type' => 'employees']), false)
            ->assertSee('社員の列マッピングを設定する');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.mapping', [$batch, 'target_type' => 'employees']))
            ->assertOk()
            ->assertSee('2. 列の意味を合わせる')
            ->assertSee('aria-current="step"', false)
            ->assertSee('<option value="employees" data-profile-layout-mismatch="false" selected>', false)
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

    public function test_upload_validation_errors_are_actionable_japanese_and_do_not_store_files(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), ['source_name' => '社員名簿'])
            ->assertSessionHasErrors('file')
            ->assertSessionHas('errors', function ($errors): bool {
                return collect($errors->get('file'))->contains('取り込むCSVファイルまたはExcelファイルを選んでください。');
            });

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'source_name' => '社員名簿',
                'file' => UploadedFile::fake()->createWithContent('社員名簿.pdf', 'not a supported import file'),
            ])
            ->assertSessionHasErrors('file')
            ->assertSessionHas('errors', function ($errors): bool {
                return collect($errors->get('file'))->contains('CSV形式、または対応するExcel（XLSX）形式のファイルを選んでください。');
            });

        $this->assertDatabaseMissing('import_batches', ['organization_id' => $organization->id]);
        $this->assertSame([], Storage::disk(config('opsdeck.import.disk'))->allFiles());
    }

    public function test_import_storage_failure_returns_a_safe_file_field_error_without_creating_a_batch(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $diskName = 'opsdeck_objects';
        config(['opsdeck.import.disk' => $diskName]);
        Storage::fake($diskName);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $disk = \Mockery::mock(Storage::disk($diskName))->makePartial();
        Storage::set($diskName, $disk);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new \RuntimeException('Sensitive storage endpoint details.'));
        $disk->shouldReceive('delete')
            ->once()
            ->withArgs(static fn (string $path): bool => str_starts_with($path, 'imports/'.$organization->id.'/'))
            ->andReturnTrue();

        $response = $this->from(route('imports.create'))
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'target_type' => 'employees',
                'source_name' => '社員名簿',
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "name,email\nSample,sample@example.test\n"),
            ]);

        $response->assertRedirect(route('imports.create'))
            ->assertSessionHasErrors([
                'file' => 'ファイルを保存できませんでした。通信状態を確認し、時間をおいてもう一度お試しください。',
            ]);
        $this->assertDatabaseMissing('import_batches', [
            'organization_id' => $organization->id,
            'source_name' => '社員名簿',
        ]);
        $this->assertStringNotContainsString('Sensitive storage endpoint details.', json_encode(session('errors')?->all()));
    }

    public function test_invalid_header_range_opens_advanced_options_and_highlights_the_field(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->from(route('imports.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'target_type' => 'employees',
                'source_name' => '社員名簿',
                'header_row_number' => 3,
                'header_end_row_number' => 2,
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "employee_number,name\nEMP-001,Sample\n"),
            ])
            ->assertOk()
            ->assertSee('<details open>', false)
            ->assertSee('id="header_end_row_number-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="header_end_row_number-error"', false)
            ->assertSee('value="3"', false)
            ->assertSee('value="2"', false);

        $this->assertDatabaseMissing('import_batches', [
            'organization_id' => $organization->id,
            'source_name' => '社員名簿',
        ]);
        $this->assertSame([], Storage::disk(config('opsdeck.import.disk'))->allFiles());
    }

    public function test_import_upload_rate_limit_stops_repeated_uploads_before_storage(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Cache::flush();
        Storage::fake(config('opsdeck.import.disk'));
        Queue::fake();
        config([
            'opsdeck.file_uploads.per_member_attempts_per_minute' => 1,
            'opsdeck.file_uploads.per_organization_attempts_per_minute' => 10,
        ]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $upload = static fn (): array => [
            'target_type' => 'employees',
            'source_name' => '社員管理表',
            'file' => UploadedFile::fake()->createWithContent('employees.csv', "employee_number,name\nEMP-001,山田太郎\n"),
        ];

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), $upload())
            ->assertRedirect();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), $upload())
            ->assertStatus(429)
            ->assertSee('アップロードを少しお待ちください')
            ->assertSee('ファイルはOpsDeckに保存されていません。');

        $this->assertSame(1, ImportBatch::query()->where('organization_id', $organization->id)->count());
        $this->assertCount(1, Storage::disk(config('opsdeck.import.disk'))->allFiles());
        Queue::assertPushed(PrepareImportPreview::class, 1);
    }

    public function test_import_upload_rate_limit_is_shared_by_members_of_the_same_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Cache::flush();
        Storage::fake(config('opsdeck.import.disk'));
        Queue::fake();
        config([
            'opsdeck.file_uploads.per_member_attempts_per_minute' => 5,
            'opsdeck.file_uploads.per_organization_attempts_per_minute' => 2,
        ]);
        [$firstUser, $organization] = $this->member(MembershipRole::Operator);
        $secondUser = User::factory()->create(['email' => 'import-colleague-'.Str::lower(Str::random(12)).'@example.test']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $secondUser->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $upload = static fn (): array => [
            'target_type' => 'employees',
            'source_name' => '社員管理表',
            'file' => UploadedFile::fake()->createWithContent('employees.csv', "employee_number,name\nEMP-001,山田太郎\n"),
        ];

        $this->actingAs($firstUser)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), $upload())
            ->assertRedirect();
        $this->actingAs($secondUser)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), $upload())
            ->assertRedirect();
        $this->actingAs($firstUser)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), $upload())
            ->assertStatus(429);

        $this->assertSame(2, ImportBatch::query()->where('organization_id', $organization->id)->count());
        $this->assertCount(2, Storage::disk(config('opsdeck.import.disk'))->allFiles());
        Queue::assertPushed(PrepareImportPreview::class, 2);
    }

    public function test_free_import_storage_quota_warns_before_the_limit_and_rejects_an_oversized_upload(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Cache::flush();
        Storage::fake(config('opsdeck.import.disk'));
        config(['opsdeck.storage_quotas.plan_limit_megabytes.free' => 1]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '保存済みの表',
            'source_file_path' => 'imports/'.$organization->id.'/existing.csv',
            'source_storage_disk' => config('opsdeck.import.disk'),
            'source_file_size_bytes' => 850000,
            'status' => 'preview',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create'))
            ->assertOk()
            ->assertSee('現在 0.8 MiB ／ 上限 1 MiB（82%）')
            ->assertSee('あと約 0.2 MiB 保存できます。')
            ->assertSee('取込元ファイルと証跡ファイルの合計です。登録した社員や資産などの件数は、ここには含まれません。')
            ->assertSee('保存容量が警告目安の80%に達しています。')
            ->assertSee('上限に達すると新しいファイルをアップロードできなくなります。');

        $this->followingRedirects()->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('imports.create'))
            ->post(route('imports.store'), [
                'target_type' => 'employees',
                'source_name' => '新しい社員名簿',
                'file' => UploadedFile::fake()->create('employees.csv', 300, 'text/csv'),
            ])
            ->assertOk()
            ->assertSee('すでに保存したファイルや登録済みの情報は削除されません。');

        $this->assertSame(1, ImportBatch::query()->where('organization_id', $organization->id)->count());
        $this->assertSame([], Storage::disk(config('opsdeck.import.disk'))->allFiles());
    }

    public function test_import_header_row_number_is_bounded_before_the_upload_is_stored(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->followingRedirects()->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('imports.create'))
            ->post(route('imports.store'), [
                'source_name' => 'invalid header row',
                'header_row_number' => config('opsdeck.import.max_header_row_number') + 1,
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "name,email\nAlice,alice@example.test\n"),
            ])
            ->assertOk()
            ->assertSee('<details open>', false)
            ->assertSee('name="header_row_number" value="'.(config('opsdeck.import.max_header_row_number') + 1).'"', false);

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
            ->assertSee('CSVファイル / Excelファイル（XLSX）')
            ->assertSee('使える形式：CSV / TXT / Excel（.xlsx）。')
            ->assertSee('古い形式のExcel（.xls）は使えません。')
            ->assertSee('次の画面で取り込むシートを選べます。')
            ->assertSee('Excelファイル（XLSX）の日付は「年-月-日」の形に整えます。')
            ->assertSee('「¥1,000」のような金額は、記号を外した数値として扱います。')
            ->assertDontSee('Excelファイルを使う場合');
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

    public function test_free_plan_cannot_retry_an_xlsx_import_after_downgrade(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $failedBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'xlsx', 'source_name' => 'failed-import', 'source_file_path' => 'imports/'.$organization->id.'/failed.xlsx', 'status' => 'failed']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $failedBatch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertSame(1, ImportBatch::query()->where('organization_id', $organization->id)->count());
        $this->assertSame('failed', $failedBatch->refresh()->status);
    }

    public function test_free_plan_can_import_csv_up_to_its_employee_limit_and_reports_excess_rows(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.disk' => 'import_archive']);
        Storage::fake('import_archive');
        Storage::fake('import_active');
        config(['opsdeck.plans.free.limits.employees' => 1]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $csv = "name,email\nFirst Employee,first-".Str::uuid()."@example.test\nSecond Employee,second-".Str::uuid()."@example.test\n";
        $sourcePath = 'imports/'.$organization->id.'/free-employees.csv';
        Storage::disk('import_archive')->put($sourcePath, $csv);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'Free社員名簿',
            'source_file_path' => $sourcePath,
            'source_storage_disk' => 'import_archive',
            'source_file_size_bytes' => strlen($csv),
            'source_file_sha256' => hash('sha256', $csv),
            'headers_json' => ['name', 'email'],
            'status' => 'ready',
            'total_rows' => 2,
        ]);
        $mapping = $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id]);
        config(['opsdeck.import.disk' => 'import_active']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
        $this->assertSame(1, $batch->error_rows);
        $this->assertSame(1, Employee::query()->where('organization_id', $organization->id)->count());
        $this->assertSame('error', $batch->rowResults()->where('source_row_number', 3)->value('action'));
        $limitError = $batch->rowResults()->where('source_row_number', 3)->firstOrFail()->errors_json['row'];
        $this->assertStringContainsString('上限（1件）', $limitError);
    }

    public function test_free_plan_cannot_execute_a_premium_only_import_target(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'free-security-controls.csv',
            'source_file_path' => 'imports/'.$organization->id.'/security-controls.csv',
            'status' => 'ready',
            'total_rows' => 1,
        ]);
        $mapping = $batch->mappings()->create([
            'target_type' => 'security_controls',
            'column_map' => ['title' => 'title'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertDatabaseMissing('security_controls', ['organization_id' => $organization->id]);
    }

    public function test_free_plan_cannot_execute_xlsx_batch_created_before_downgrade(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'premium-employees.xlsx',
            'source_file_path' => 'imports/'.$organization->id.'/premium-employees.xlsx',
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

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame(0, Employee::query()->where('organization_id', $organization->id)->count());
    }

    public function test_import_history_can_filter_by_status(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'history-preview', 'status' => 'preview']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.index', ['status' => 'preview']))
            ->assertOk()
            ->assertSee('データの取込履歴')
            ->assertSee('管理表を取り込む前に確認する')
            ->assertSee('取り込みに困ったとき')
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
            ->assertSee('確認済みにする')
            ->assertSee('「確認済み」は確認した記録です。エラーや会社のデータが修正されるわけではありません。');

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
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('エラーや会社のデータは修正されていません。');
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
            ->assertSee('この取込は完了していません。今回の取込によるデータ変更は保存されていません。')
            ->assertSee('参照番号 IMP-'.$batch->id)
            ->assertSee(route('support.create', ['category' => 'import', 'error_reference' => 'IMP-'.$batch->id]))
            ->assertSee(route('help.recovery', 'import'))
            ->assertSee('action="'.route('imports.retry', $batch).'"', false);
    }

    public function test_viewer_sees_recovery_guidance_but_not_the_import_retry_form(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $batch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'viewer-failed-import', 'status' => 'failed']);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('今回の取込によるデータ変更は保存されていません。')
            ->assertSee('再試行は、会社のデータを管理する担当者に依頼してください。')
            ->assertSee(route('help.recovery', 'import'))
            ->assertDontSee('action="'.route('imports.retry', $batch).'"', false);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.retry', $batch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertSame('failed', $batch->refresh()->status);
    }

    public function test_viewer_sees_completed_import_but_not_sync_or_rollback_actions(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => '閲覧者向け完了取込',
            'status' => 'completed',
        ]);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('取込内容の再取得や取消などの変更操作は、会社のデータを管理する担当者に依頼してください。')
            ->assertDontSee('action="'.route('imports.google-sheet.sync', $batch).'"', false)
            ->assertDontSee('action="'.route('imports.rollback', $batch).'"', false);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.google-sheet.sync', $batch))
            ->assertForbidden();

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.rollback', $batch), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertSame('completed', $batch->refresh()->status);
    }

    public function test_viewer_sees_ready_import_but_not_execution_or_review_actions(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '閲覧者向け実行前取込',
            'status' => 'ready',
        ]);
        $candidate = $batch->reviewCandidates()->create([
            'candidate_type' => 'duplicate',
            'source_row_numbers_json' => [2, 3],
            'confidence' => 0.9,
            'details_json' => ['target_type' => 'employees'],
            'status' => 'pending',
        ]);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('取込の実行は、会社のデータを管理する担当者に依頼してください。')
            ->assertSee('重複候補の判断は、会社のデータを管理する担当者に依頼してください。')
            ->assertDontSee('action="'.route('imports.execute', $batch).'"', false)
            ->assertDontSee('action="'.route('imports.candidates.review', [$batch, $candidate]).'"', false);

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertForbidden();

        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.candidates.review', [$batch, $candidate]), ['status' => 'accepted'])
            ->assertForbidden();

        $this->assertSame('ready', $batch->refresh()->status);
        $this->assertSame('pending', $candidate->refresh()->status);
    }

    public function test_operator_can_create_a_csv_preview_without_importing_business_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.disk' => 'opsdeck_objects']);
        Storage::fake('opsdeck_objects');
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
        $this->assertSame(config('opsdeck.import.disk'), $batch->source_storage_disk);
        $this->assertStringStartsWith('imports/'.$organization->id.'/', $batch->source_file_path);
        $this->assertTrue(Str::isUuid(pathinfo($batch->source_file_path, PATHINFO_FILENAME)));
        $this->assertSame('csv', pathinfo($batch->source_file_path, PATHINFO_EXTENSION));
        $this->assertSame(strlen($sourceContents), $batch->source_file_size_bytes);
        $this->assertDatabaseCount('employees', $employeeCountBefore);
        Storage::disk(config('opsdeck.import.disk'))->assertExists($batch->source_file_path);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('元ファイル SHA-256')
            ->assertSee($batch->source_file_sha256);
    }

    public function test_import_integrity_uses_the_storage_disk_recorded_on_the_batch(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake('import_archive');
        Storage::fake('import_active');
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $sourceContents = "name,email\nArchived Employee,archived@example.test\n";
        $sourcePath = 'imports/'.$organization->id.'/archived-employees.csv';
        Storage::disk('import_archive')->put($sourcePath, $sourceContents);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '過去の社員CSV',
            'source_file_path' => $sourcePath,
            'source_storage_disk' => 'import_archive',
            'source_file_size_bytes' => strlen($sourceContents),
            'source_file_sha256' => hash('sha256', $sourceContents),
            'status' => 'preview',
            'executed_by' => $user->id,
        ]);
        config(['opsdeck.import.disk' => 'import_active']);

        app(ImportSourceIntegrityService::class)->assertUnchanged($batch);

        $this->assertSame('import_archive', $batch->sourceStorageDiskName());
        $this->assertSame(strlen($sourceContents), $batch->source_file_size_bytes);
    }

    public function test_import_preview_reads_the_storage_disk_recorded_on_the_batch_after_configuration_changes(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake('import_archive');
        Storage::fake('import_active');
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $sourceContents = "name,email\nArchived Employee,archived@example.test\n";
        $sourcePath = 'imports/'.$organization->id.'/archived-employees.csv';
        Storage::disk('import_archive')->put($sourcePath, $sourceContents);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '過去の社員CSV',
            'source_file_path' => $sourcePath,
            'source_storage_disk' => 'import_archive',
            'source_file_size_bytes' => strlen($sourceContents),
            'source_file_sha256' => hash('sha256', $sourceContents),
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);
        config(['opsdeck.import.disk' => 'import_active']);

        app(ImportPreviewPreparationService::class)->prepare($batch->id);

        $this->assertSame('preview', $batch->refresh()->status);
        $this->assertSame(['name', 'email'], $batch->headers_json);
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame('import_archive', $batch->source_storage_disk);
        $this->assertSame([2], $batch->rowResults()->orderBy('source_row_number')->pluck('source_row_number')->all());
        Storage::disk('import_archive')->assertExists($sourcePath);
        Storage::disk('import_active')->assertMissing($sourcePath);
    }

    public function test_completed_csv_import_can_be_compared_and_executed_from_its_approved_diff_preview(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $updatedEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Before Update',
            'email' => 'diff-update@example.test',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $removedEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Still Exists',
            'email' => 'diff-removed@example.test',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $unchangedEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Unchanged Employee',
            'email' => 'diff-unchanged@example.test',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $concurrentlyEditedEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Before Concurrent Edit',
            'email' => 'diff-concurrent@example.test',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $untrackedExistingEmployee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Untracked Existing Employee',
            'email' => 'diff-untracked-existing@example.test',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $sourceBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員台帳 前回',
            'selected_sheet_name' => null,
            'header_row_number' => 1,
            'header_end_row_number' => 1,
            'data_start_row_number' => 2,
            'headers_json' => ['name', 'email'],
            'status' => 'completed',
            'executed_by' => $user->id,
        ]);
        $mapping = $sourceBatch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $sourceBatch->update(['mapping_id' => (string) $mapping->id]);
        ImportRowResult::query()->create([
            'import_batch_id' => $sourceBatch->id,
            'source_row_number' => 2,
            'target_type' => 'employees',
            'target_id' => $updatedEmployee->id,
            'action' => 'inserted',
            'after_json' => ['name' => 'Before Update', 'email' => $updatedEmployee->email, 'employment_status' => config('opsdeck.employee_status.active')],
        ]);
        ImportRowResult::query()->create([
            'import_batch_id' => $sourceBatch->id,
            'source_row_number' => 3,
            'target_type' => 'employees',
            'target_id' => $removedEmployee->id,
            'action' => 'inserted',
            'after_json' => ['name' => 'Still Exists', 'email' => $removedEmployee->email, 'employment_status' => config('opsdeck.employee_status.active')],
        ]);
        ImportRowResult::query()->create([
            'import_batch_id' => $sourceBatch->id,
            'source_row_number' => 4,
            'target_type' => 'employees',
            'target_id' => $unchangedEmployee->id,
            'action' => 'inserted',
            'after_json' => ['name' => 'Unchanged Employee', 'email' => $unchangedEmployee->email, 'employment_status' => config('opsdeck.employee_status.active')],
        ]);
        ImportRowResult::query()->create([
            'import_batch_id' => $sourceBatch->id,
            'source_row_number' => 5,
            'target_type' => 'employees',
            'target_id' => $concurrentlyEditedEmployee->id,
            'action' => 'inserted',
            'after_json' => ['name' => 'Before Concurrent Edit', 'email' => $concurrentlyEditedEmployee->email, 'employment_status' => config('opsdeck.employee_status.active')],
        ]);

        Queue::fake([PrepareImportFileDiffPreview::class]);
        $session = ['current_organization_id' => $organization->id];
        $this->actingAs($user)->withSession($session)
            ->get(route('imports.create', ['diff_source_batch_id' => $sourceBatch->id]))
            ->assertOk()
            ->assertSee('前回ファイルとの違いを確認')
            ->assertSee((string) $sourceBatch->id);
        $this->actingAs($user)->withSession($session)
            ->post(route('imports.store'), [
                'source_name' => '社員台帳 今回',
                'target_type' => 'employees',
                'diff_source_batch_id' => $sourceBatch->id,
                'header_row_number' => 1,
                'header_end_row_number' => 1,
                'data_start_row_number' => 2,
                'file' => UploadedFile::fake()->createWithContent('employees.csv', "name,email\nAfter Update,diff-update@example.test\nNew Employee,new-diff@example.test\nInvalid Candidate,\nUnchanged Employee,diff-unchanged@example.test\nImported Concurrent Value,diff-concurrent@example.test\nNewly Discovered,Diff-Untracked-Existing@example.test\n"),
            ])->assertRedirect();

        $diffBatch = ImportBatch::query()->where('diff_source_batch_id', $sourceBatch->id)->firstOrFail();
        Queue::assertPushed(PrepareImportFileDiffPreview::class, fn (PrepareImportFileDiffPreview $job): bool => $job->sourceBatchId === $sourceBatch->id && $job->diffBatchId === $diffBatch->id);
        app(ImportFileDiffPreviewPreparationService::class)->prepare($sourceBatch->id, $diffBatch->id);
        $diffBatch->refresh();
        $this->assertSame('ready', $diffBatch->status);
        $this->assertSame(2, $diffBatch->preview_new_rows);
        $this->assertSame(2, $diffBatch->preview_updated_rows);
        $this->assertSame(1, $diffBatch->preview_error_rows);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $diffBatch->id, 'source_row_number' => 2, 'action' => 'update_candidate', 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $diffBatch->id, 'source_row_number' => 3, 'action' => 'new_candidate', 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $diffBatch->id, 'source_row_number' => 7, 'action' => 'new_candidate', 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $diffBatch->id, 'action' => 'deleted_candidate', 'is_selected' => false]);
        $diffBatch->rowResults()->where('action', 'error')->firstOrFail()->update(['is_selected' => true]);
        $concurrentlyEditedEmployee->update(['name' => 'Manual Edit After Preview']);

        Storage::disk(config('opsdeck.import.disk'))->put($diffBatch->source_file_path, "name,email\nChanged After Preview,diff-update@example.test\n");
        app(ImportBatchExecutionService::class)->execute($diffBatch->id, $user->id, 'ready');

        $this->assertSame('After Update', $updatedEmployee->refresh()->name);
        $this->assertSame('Manual Edit After Preview', $concurrentlyEditedEmployee->refresh()->name);
        $this->assertSame('Untracked Existing Employee', $untrackedExistingEmployee->refresh()->name);
        $this->assertDatabaseHas('employees', ['organization_id' => $organization->id, 'email' => 'new-diff@example.test', 'name' => 'New Employee']);
        $this->assertDatabaseHas('employees', ['id' => $removedEmployee->id, 'name' => 'Still Exists']);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $diffBatch->id,
            'target_id' => $unchangedEmployee->id,
            'action' => 'unchanged',
        ]);
        $this->assertSame('completed', $diffBatch->refresh()->status);
        $this->assertSame(3, $diffBatch->error_rows);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $diffBatch->id,
            'target_id' => $concurrentlyEditedEmployee->id,
            'action' => 'stale_conflict',
        ]);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $diffBatch->id,
            'target_id' => $untrackedExistingEmployee->id,
            'action' => 'stale_conflict',
        ]);

        $nextSourcePath = 'imports/'.$organization->id.'/employees-next.csv';
        $nextSourceContents = "name,email\nAfter Update,diff-update@example.test\nNew Employee,new-diff@example.test\nUnchanged Employee,diff-unchanged@example.test\nImported Concurrent Value,diff-concurrent@example.test\nNewly Discovered,diff-untracked-existing@example.test\n";
        Storage::disk(config('opsdeck.import.disk'))->put($nextSourcePath, $nextSourceContents);
        $nextDiffBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '社員台帳 次回',
            'source_file_path' => $nextSourcePath,
            'source_file_sha256' => hash('sha256', $nextSourceContents),
            'header_row_number' => 1,
            'header_end_row_number' => 1,
            'data_start_row_number' => 2,
            'status' => 'previewing',
            'executed_by' => $user->id,
            'diff_source_batch_id' => $diffBatch->id,
        ]);
        app(ImportFileDiffPreviewPreparationService::class)->prepare($diffBatch->id, $nextDiffBatch->id);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $nextDiffBatch->id,
            'target_id' => $unchangedEmployee->id,
            'action' => 'unchanged',
        ]);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $nextDiffBatch->id,
            'target_id' => $concurrentlyEditedEmployee->id,
            'action' => 'update_candidate',
        ]);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $nextDiffBatch->id,
            'target_id' => $untrackedExistingEmployee->id,
            'action' => 'update_candidate',
        ]);
    }

    public function test_completed_xlsx_import_can_be_compared_with_a_new_workbook(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $employee = Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Old Workbook Name',
            'email' => 'xlsx-diff@example.test',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $sourceBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => '社員台帳 Excel 前回',
            'selected_sheet_name' => '社員一覧',
            'header_row_number' => 1,
            'header_end_row_number' => 1,
            'data_start_row_number' => 2,
            'headers_json' => ['name', 'email'],
            'status' => 'completed',
            'executed_by' => $user->id,
        ]);
        $mapping = $sourceBatch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $sourceBatch->update(['mapping_id' => (string) $mapping->id]);
        ImportRowResult::query()->create([
            'import_batch_id' => $sourceBatch->id,
            'sheet_name' => '社員一覧',
            'source_row_number' => 2,
            'target_type' => 'employees',
            'target_id' => $employee->id,
            'action' => 'inserted',
            'after_json' => ['name' => 'Old Workbook Name', 'email' => $employee->email],
        ]);

        $directory = storage_path('framework/testing-xlsx');
        File::ensureDirectoryExists($directory);
        $workbookPath = tempnam($directory, 'opsdeck-diff-xlsx-');
        $archive = new ZipArchive;
        $this->assertSame(true, $archive->open($workbookPath, ZipArchive::CREATE) === true);
        $archive->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="社員一覧" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>email</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>New Workbook Name</t></is></c><c r="B2" t="inlineStr"><is><t>xlsx-diff@example.test</t></is></c></row></sheetData></worksheet>');
        $archive->close();
        $workbookContents = file_get_contents($workbookPath);
        unlink($workbookPath);

        Queue::fake([PrepareImportFileDiffPreview::class]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.store'), [
                'source_name' => '社員台帳 Excel 今回',
                'target_type' => 'employees',
                'diff_source_batch_id' => $sourceBatch->id,
                'header_row_number' => 1,
                'header_end_row_number' => 1,
                'data_start_row_number' => 2,
                'file' => UploadedFile::fake()->createWithContent('employees.xlsx', $workbookContents),
            ])->assertRedirect();

        $diffBatch = ImportBatch::query()->where('diff_source_batch_id', $sourceBatch->id)->firstOrFail();
        $this->assertSame('社員一覧', $diffBatch->selected_sheet_name);
        Queue::assertPushed(PrepareImportFileDiffPreview::class, fn (PrepareImportFileDiffPreview $job): bool => $job->sourceBatchId === $sourceBatch->id && $job->diffBatchId === $diffBatch->id);
        app(ImportFileDiffPreviewPreparationService::class)->prepare($sourceBatch->id, $diffBatch->id);

        $diffBatch->refresh();
        $this->assertSame('ready', $diffBatch->status);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $diffBatch->id,
            'source_row_number' => 2,
            'action' => 'update_candidate',
            'is_selected' => true,
        ]);
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
            'column_map' => ['社員番号' => 'employee_number', '氏名' => 'name', 'メール' => 'email', '在籍状況' => 'employment_status'],
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

        ImportRowResult::query()->where('import_batch_id', $batch->id)->update([
            'after_json' => ['employee_number' => 'EMP-SAMPLE-001', 'name' => '実データ風の名称', 'email' => 'changed@example.test', 'employment_status' => 'active'],
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('imports.show', $batch))
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHasErrors('sample');

        $this->assertDatabaseHas('import_batches', ['id' => $batch->id, 'status' => 'ready']);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => 'changed@example.test']);

        config(['opsdeck.import_samples.employees.identity_fields' => []]);
        ImportRowResult::query()->where('import_batch_id', $batch->id)->update([
            'after_json' => ['employee_number' => 'EMP-SAMPLE-001', 'name' => 'サンプル太郎', 'email' => 'sample@example.test', 'employment_status' => 'active'],
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('imports.show', $batch))
            ->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHasErrors('sample');

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
            ->assertDontSee('導入効果レポートで取込前後を確認する')
            ->assertSee('取込処理は完了しましたが、エラーが1行あります。エラー行は登録・変更されていません。')
            ->assertSee('取込エラーの対処方法を見る')
            ->assertSee(route('help.recovery', 'import'))
            ->assertSee('修正したファイルの内容を確認する')
            ->assertSee(route('imports.create', ['target_type' => 'employees']));

        $successfulBatch = $runImport('valid-employees', "name,email\nValid,valid-".Str::uuid().'@example.test'."\n");
        $this->assertSame(1, $successfulBatch->inserted_rows);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $successfulBatch))
            ->assertSee('導入効果レポートで取込前後を確認する')
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

    public function test_database_failure_after_a_row_insert_rolls_back_the_entire_import(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $email = 'database-failure-'.Str::uuid().'@example.test';
        $sourceContents = "name,email\nDatabase Failure,{$email}\n";
        $sourcePath = 'imports/'.$organization->id.'/database-failure.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($sourcePath, $sourceContents);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'database-failure.csv',
            'source_file_path' => $sourcePath,
            'source_file_sha256' => hash('sha256', $sourceContents),
            'headers_json' => ['name', 'email'],
            'status' => 'ready',
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
        Employee::created(static function (): void {
            throw new QueryException(config('database.default'), 'insert into audit_logs', [], new \RuntimeException('simulated database failure'));
        });

        try {
            app(ImportBatchExecutionService::class)->execute((int) $batch->id, (int) $user->id, 'ready');
            $this->fail('A database failure during row processing must fail the batch.');
        } catch (QueryException) {
            $this->assertSame('failed', $batch->refresh()->status);
        }

        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => $email]);
        $this->assertSame(0, $batch->rowResults()->count());
    }

    public function test_invalid_import_date_is_recorded_as_a_row_error_without_failing_the_batch(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $email = 'invalid-date-'.Str::uuid().'@example.test';
        $sourceContents = "name,email,joined_at\nInvalid Date,{$email},2026-99-99\n";
        $sourcePath = 'imports/'.$organization->id.'/invalid-date.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($sourcePath, $sourceContents);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'invalid-date.csv',
            'source_file_path' => $sourcePath,
            'source_file_sha256' => hash('sha256', $sourceContents),
            'headers_json' => ['name', 'email', 'joined_at'],
            'status' => 'ready',
            'total_rows' => 1,
            'executed_by' => $user->id,
        ]);
        $batch->mappings()->create([
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email', 'joined_at' => 'joined_at'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        app(ImportBatchExecutionService::class)->execute((int) $batch->id, (int) $user->id, 'ready');

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->error_rows);
        $this->assertDatabaseHas('import_row_results', [
            'import_batch_id' => $batch->id,
            'source_row_number' => 2,
            'action' => 'error',
        ]);
        $rowResult = $batch->rowResults()->firstOrFail();
        $this->assertSame('日付の形式を確認してください。', $rowResult->errors_json['row']);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => $email]);
    }

    public function test_xlsx_cleanup_failure_does_not_replace_the_original_preparation_exception(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $diskName = config('opsdeck.import.disk');
        Storage::fake($diskName);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);

        $preparationFailure = new \RuntimeException('Workbook preparation failed.');
        $cleanupFailure = new \RuntimeException('Storage cleanup failed.');
        $disk = \Mockery::mock(Storage::disk($diskName))->makePartial();
        $disk->shouldReceive('delete')->once()->andThrow($cleanupFailure);
        Storage::shouldReceive('disk')->with($diskName)->andReturn($disk);
        $xlsxSource = \Mockery::mock(XlsxImportSource::class);
        $xlsxSource->shouldReceive('sheets')->once()->andThrow($preparationFailure);
        $this->app->instance(XlsxImportSource::class, $xlsxSource);
        Log::shouldReceive('warning')
            ->once()
            ->with('Import upload cleanup failed after batch preparation failed.');

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
                ->post(route('imports.store'), [
                    'target_type' => 'employees',
                    'source_name' => '社員台帳',
                    'file' => UploadedFile::fake()->createWithContent('employees.xlsx', 'invalid workbook'),
                ]);

            $this->fail('The original workbook preparation exception should be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($preparationFailure, $exception);
        }
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
            ->assertSee('ファイルを確認しています')
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
            'source_storage_disk' => config('opsdeck.import.disk'),
            'source_file_size_bytes' => strlen('the workbook stays in private storage'),
            'available_sheets_json' => ['社員一覧', '資産一覧'],
            'selected_sheet_name' => '社員一覧',
            'status' => 'preview',
        ]);
        Queue::fake([PrepareImportPreview::class]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $sourceBatch))
            ->assertOk()
            ->assertSee('このXLSXの別シートを取り込む')
            ->assertSee('別の一覧や社員・資産の関係は自動で結びつきません。');

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
        $this->assertSame(config('opsdeck.import.disk'), $additionalBatch->source_storage_disk);
        $this->assertSame(strlen('the workbook stays in private storage'), $additionalBatch->source_file_size_bytes);
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

    public function test_xlsx_import_page_lists_related_sheets_without_exposing_other_organizations(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $sourcePath = 'imports/'.$organization->id.'/company-register.xlsx';
        $sourceBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => '会社台帳',
            'source_file_path' => $sourcePath,
            'available_sheets_json' => ['社員一覧', '資産一覧'],
            'selected_sheet_name' => '社員一覧',
            'status' => 'preview',
        ]);
        $relatedBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => '会社台帳',
            'source_file_path' => $sourcePath,
            'available_sheets_json' => ['社員一覧', '資産一覧'],
            'selected_sheet_name' => '資産一覧',
            'status' => 'previewing',
        ]);
        [, $otherOrganization] = $this->member(MembershipRole::Viewer);
        ImportBatch::query()->create([
            'organization_id' => $otherOrganization->id,
            'source_type' => 'xlsx',
            'source_name' => '別組織の台帳',
            'source_file_path' => $sourcePath,
            'available_sheets_json' => ['別組織専用'],
            'selected_sheet_name' => '別組織専用',
            'status' => 'preview',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $sourceBatch))
            ->assertSee('同じExcelファイルから取り込んだシート')
            ->assertSee('社員一覧')
            ->assertSee('資産一覧')
            ->assertSee(route('imports.show', $relatedBatch))
            ->assertDontSee('別組織専用');
    }

    public function test_assignment_import_shows_the_required_order_and_prefilled_links(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => '端末割当表',
            'selected_sheet_name' => '端末割当',
            'headers_json' => ['asset_number', 'employee_email', 'assigned_at'],
            'status' => 'preview',
        ]);
        $mapping = $batch->mappings()->create([
            'target_type' => 'asset_assignments',
            'column_map' => ['asset_number' => 'asset_number', 'employee_email' => 'employee_email', 'assigned_at' => 'assigned_at'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $batch->update(['mapping_id' => (string) $mapping->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('端末の割当を取り込む順番')
            ->assertSee(route('imports.create', ['target_type' => 'employees']), false)
            ->assertSee(route('imports.create', ['target_type' => 'assets']), false)
            ->assertSee('社員メールアドレスと資産番号は、登録済みの社員・端末の情報と一致している必要があります。');

        $saasBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'SaaSアカウント割当表',
            'selected_sheet_name' => 'SaaS割当',
            'headers_json' => ['service_name', 'employee_email', 'mfa_status'],
            'status' => 'preview',
        ]);
        $saasMapping = $saasBatch->mappings()->create([
            'target_type' => 'saas_assignments',
            'column_map' => ['service_name' => 'service_name', 'employee_email' => 'employee_email', 'mfa_status' => 'mfa_status'],
            'normalization_rules' => [],
            'status' => 'ready',
            'created_by' => $user->id,
        ]);
        $saasBatch->update(['mapping_id' => (string) $saasMapping->id]);
        $saasBatch->rowResults()->create([
            'source_row_number' => 2,
            'target_type' => 'saas_assignments',
            'action' => 'inserted',
            'after_json' => ['service_name' => '勤怠サービス', 'status' => 'active', 'mfa_status' => 'enabled'],
            'errors_json' => [],
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $saasBatch))
            ->assertOk()
            ->assertSee('クラウドサービスの利用者を登録する順番')
            ->assertSee(route('imports.create', ['target_type' => 'employees']), false)
            ->assertSee(route('imports.create', ['target_type' => 'saas_services']), false)
            ->assertSee('社員メールアドレスとサービス名は、登録済みの情報と一致している必要があります。')
            ->assertSee('利用中')
            ->assertSee('有効')
            ->assertDontSee('（active）')
            ->assertDontSee('（enabled）');
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

    public function test_duplicate_csv_preview_job_does_not_duplicate_rows_or_first_preview_event(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $path = 'imports/'.$organization->id.'/duplicate-preview.csv';
        Storage::disk(config('opsdeck.import.disk'))->put($path, "name,email\nAlice,alice@example.test\nBob,bob@example.test\n");
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'duplicate-preview.csv',
            'source_file_path' => $path,
            'status' => 'previewing',
            'executed_by' => $user->id,
        ]);
        $previewJob = new PrepareImportPreview($batch->id);

        $previewJob->handle(app(ImportPreviewPreparationService::class));
        $previewJob->handle(app(ImportPreviewPreparationService::class));

        $this->assertSame('preview', $batch->refresh()->status);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame([2, 3], ImportRowResult::query()->where('import_batch_id', $batch->id)->orderBy('source_row_number')->pluck('source_row_number')->all());
        $this->assertSame(1, DB::table('product_events')->where('organization_id', $organization->id)->where('event_key', 'first_import_previewed')->count());
        Storage::disk(config('opsdeck.import.disk'))->assertExists($path);
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
            ->assertSessionHas('status', '取込を受け付けました。処理が始まるまで順番待ちです。状態が変わらない場合は管理者にお問い合わせください。重ねて実行しないでください。');

        $this->assertDatabaseHas('import_batches', ['id' => $batch->id, 'status' => 'queued', 'executed_by' => $user->id]);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => $email]);
        Queue::assertPushed(ExecuteImportBatch::class, fn (ExecuteImportBatch $job): bool => $job->importBatchId === $batch->id && $job->userId === $user->id);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('取込を受け付けました。処理が始まるまで順番待ちです。')
            ->assertSee('重ねて実行しないでください。')
            ->assertSee('<a href="'.route('imports.show', $batch).'">状態を更新</a>', false);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('imports.execute', $batch->refresh()), ['confirm' => '1'])
            ->assertStatus(422);

        $this->assertSame('queued', $batch->refresh()->status);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => $email]);
        Queue::assertPushedTimes(ExecuteImportBatch::class, 1);

        $batch->update(['status' => 'executing']);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('取込を実行中です。画面を更新すると状態を確認できます。')
            ->assertSee('完了するまで、もう一度実行しないでください。')
            ->assertSee('<a href="'.route('imports.show', $batch).'">状態を更新</a>', false);
    }

    public function test_stale_import_status_shows_safe_refresh_and_support_guidance(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.stale_after_minutes' => 10]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'stale-rollback',
            'status' => 'rolling_back',
        ]);
        DB::table('import_batches')->where('id', $batch->id)->update(['updated_at' => now()->subMinutes(11)]);
        $this->assertTrue(ImportBatch::query()->whereKey($batch->id)->stale(now()->subMinutes(10))->exists());

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('設定時間（10分）を超えて状態が変わっていません。')
            ->assertSee('何度も実行・取消ボタンを押さず')
            ->assertSee('参照番号 IMP-'.$batch->id)
            ->assertSee(route('support.create', ['category' => 'import', 'error_reference' => 'IMP-'.$batch->id]))
            ->assertSee('取込の取消処理中です。');

        $this->assertSame('rolling_back', $batch->refresh()->status);
    }

    public function test_executing_import_is_not_reported_stale_before_its_job_timeout(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $originalStaleAfterMinutes = config('opsdeck.import.stale_after_minutes');
        $originalJobTimeoutSeconds = config('opsdeck.import.job_timeout_seconds');

        try {
            config([
                'opsdeck.import.stale_after_minutes' => 10,
                'opsdeck.import.job_timeout_seconds' => 1200,
            ]);
            [$user, $organization] = $this->member(MembershipRole::Operator);
            $batch = ImportBatch::query()->create([
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'long-running-import',
                'status' => 'executing',
                'started_at' => now()->subMinutes(15),
            ]);

            $this->assertFalse(ImportBatch::query()->whereKey($batch->id)->stale(now()->subMinutes(10))->exists());

            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
                ->get(route('imports.show', $batch))
                ->assertOk()
                ->assertSee('取込を実行中です。')
                ->assertDontSee('設定時間（10分）を超えて状態が変わっていません。');
        } finally {
            config([
                'opsdeck.import.stale_after_minutes' => $originalStaleAfterMinutes,
                'opsdeck.import.job_timeout_seconds' => $originalJobTimeoutSeconds,
            ]);
        }
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
                'visible_row_numbers' => [2, 3, 4],
                'selected_row_numbers' => [3],
            ])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 2, 'is_selected' => false]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 3, 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 4, 'is_selected' => true]);
        $this->assertDatabaseHas('import_row_results', ['import_batch_id' => $batch->id, 'source_row_number' => 5, 'is_selected' => true]);
    }

    public function test_import_results_show_only_the_latest_result_when_sheet_metadata_differs(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'xlsx',
            'source_name' => 'workbook.xlsx',
            'selected_sheet_name' => '社員一覧',
            'status' => 'completed',
        ]);
        $batch->rowResults()->create([
            'sheet_name' => '社員一覧',
            'source_row_number' => 2,
            'target_type' => 'employees',
            'action' => 'new_candidate',
        ]);
        $batch->rowResults()->create([
            'source_row_number' => 2,
            'target_type' => 'employees',
            'action' => 'inserted',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', $batch))
            ->assertOk()
            ->assertViewHas('rowResults', static fn ($rows): bool => $rows->total() === 1 && $rows->first()?->action === 'inserted');
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
                'after_json' => ['email' => 'repeated@example.test'],
            ]);
            $batch->reviewCandidates()->create([
                'candidate_type' => 'duplicate',
                'source_row_numbers_json' => [$sourceRowNumber - 1, $sourceRowNumber],
                'confidence' => 0.5,
                'details_json' => ['target_type' => 'employees'],
                'status' => 'pending',
            ]);
        }
        $otherBatch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'another-import-details-batch',
            'status' => 'preview',
        ]);
        ImportRowResult::query()->create([
            'import_batch_id' => $otherBatch->id,
            'source_row_number' => $perPage + 1,
            'target_type' => 'employees',
            'action' => 'preview',
            'after_json' => ['email' => 'must-not-appear@example.test'],
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.show', [
                'importBatch' => $batch,
                'row_results_page' => 2,
                'review_candidates_page' => 2,
            ]));

        $response->assertOk()
            ->assertSee('取込の状態: プレビュー確認待ち / ファイル形式: CSVファイル')
            ->assertSee('今回の処理対象にする行にチェックを付けて、このページの選択を保存してください。')
            ->assertSee('このページのエラー以外の行をすべて選択')
            ->assertSee('一括選択・解除は表示中ページのエラー以外の行に反映され、エラー行のチェック状態は変わりません。')
            ->assertSee('チェックを外した行は今回の対象外として残ります。')
            ->assertSee('エラー行はチェックを付けても登録・変更されません。')
            ->assertSee('未判断または保留中の候補が残っている間は、取込を実行できません。')
            ->assertSee('取り込み前の確認')
            ->assertSee('重複候補')
            ->assertSee('下に行番号と識別情報を並べています。内容を比べてから判断してください。')
            ->assertSee('同じ識別情報が重複：1行だけ取り込む')
            ->assertSee('別データと判断：重複行はエラーとして残る')
            ->assertSee('あとで確認：決めるまで取込できません')
            ->assertSee('行 '.($perPage + 1))
            ->assertSee('行 '.($perPage + 2))
            ->assertSee('メールアドレス：repeated@example.test')
            ->assertDontSee('must-not-appear@example.test')
            ->assertDontSee('信頼度')
            ->assertDontSee('50%')
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

    public function test_csv_preview_persists_physical_row_numbers_after_quoted_newlines(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.import.disk'));
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('imports.store'), [
            'source_name' => 'Multiline CSV',
            'file' => UploadedFile::fake()->createWithContent('employees.csv', "name,notes\r\nAlice,\"first line\r\nsecond line\"\r\nBob,ready\r\n"),
        ])->assertRedirect();

        $batch = ImportBatch::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame([2, 4], $batch->rowResults()->orderBy('source_row_number')->pluck('source_row_number')->all());
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
