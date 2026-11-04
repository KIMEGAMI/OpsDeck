<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Contract;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\ContractMonthlyCostCalculator;
use App\Services\DatabaseMigrationHealthService;
use App\Services\ImportFailureMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_view_aggregate_dashboard(): void
    {
        $user = User::factory()->create(['email' => 'platform-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);

        $response = $this->actingAs($user)->get(route('admin.dashboard'));
        $response->assertOk()->assertSee('OpsDeck運営管理')->assertSee('契約社数')->assertSee(route('admin.ai-cost'))->assertSee(route('admin.queue-errors'))->assertSee(route('admin.health'));
        $this->assertSame(1, substr_count($response->getContent(), '製品利用状況'));
    }

    public function test_platform_dashboard_uses_terminal_import_attempts_for_failure_rate(): void
    {
        $user = User::factory()->create(['email' => 'platform-import-rate-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $organization = Organization::query()->create(['name' => 'Dashboard Import Metrics', 'slug' => 'dashboard-import-metrics-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $batches = collect([
            ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'completed', 'status' => 'completed']),
            ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'failed', 'status' => 'failed']),
            ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'preview-only', 'status' => 'preview']),
            ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'cancelled', 'status' => 'cancelled']),
        ]);
        $metrics = app(ImportFailureMetrics::class)->summarize();

        try {
            $this->actingAs($user)->get(route('admin.dashboard'))
                ->assertOk()
                ->assertSee('Import処理失敗率（完了・失敗した処理）')
                ->assertSee($metrics['rate'].'%');
        } finally {
            ImportBatch::query()->where('organization_id', $organization->id)->delete();
            $organization->delete();
        }
    }

    public function test_non_platform_admin_cannot_view_dashboard(): void
    {
        $user = User::factory()->create(['email' => 'member-'.Str::uuid().'@example.test']);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_non_platform_admin_cannot_view_health_page(): void
    {
        $user = User::factory()->create(['email' => 'member-health-'.Str::uuid().'@example.test']);

        $this->actingAs($user)->get(route('admin.health'))->assertForbidden();
    }

    public function test_platform_admin_can_view_ai_cost_and_error_pages(): void
    {
        $user = User::factory()->create(['email' => 'platform-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);

        $this->actingAs($user)->get(route('admin.ai-cost'))->assertOk()->assertSee('AI原価');
        $this->actingAs($user)->get(route('admin.queue-errors'))
            ->assertOk()
            ->assertSee('バックグラウンド処理・Stripe通知のエラー')
            ->assertSee('php artisan queue:retry &lt;UUID&gt;', false)
            ->assertSee('全件一括の再試行は避けてください')
            ->assertSee('登録の重複やメールの二重送信が起きる場合があります')
            ->assertSee('メール配信の手動確認')
            ->assertSee(route('admin.mail-delivery-reviews.index'));
    }

    public function test_failed_queue_jobs_show_a_readable_queue_label_and_keep_the_retry_id(): void
    {
        $user = User::factory()->create(['email' => 'platform-failed-queue-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $jobId = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $jobId,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Example failure',
            'failed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('admin.queue-errors'))
            ->assertOk()
            ->assertSee('再実行ID：'.$jobId)
            ->assertSee('処理先：通常のバックグラウンド処理（default）')
            ->assertSee('失敗日時：');
    }

    public function test_ai_cost_pages_show_token_costs_and_do_not_invent_missing_fx_or_model_prices(): void
    {
        AiUsageLog::query()->delete();
        $user = User::factory()->create(['email' => 'platform-ai-cost-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.ai.usd_to_jpy_rate' => null]);
        $organization = Organization::query()->create(['name' => 'AI cost reporting', 'slug' => 'ai-cost-reporting-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'input_tokens' => 100000,
            'output_tokens' => 20000,
            'estimated_cost_usd' => 0.02,
            'estimated_cost_jpy' => 0,
            'success' => true,
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'custom-model-without-price',
            'purpose' => 'import_column_mapping',
            'input_tokens' => 100000,
            'output_tokens' => 20000,
            'estimated_cost_usd' => 0,
            'estimated_cost_jpy' => 0,
            'success' => true,
            'error_code' => 'pricing_unavailable',
        ]);

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('AI推定原価（USD）')
            ->assertSee('為替レート未設定')
            ->assertSee('価格未登録モデルの利用回数')
            ->assertSee('1');
        $this->actingAs($user)->get(route('admin.ai-cost'))
            ->assertOk()
            ->assertSee('入力tokens')
            ->assertSee('100,000')
            ->assertSee('$0.020000')
            ->assertSee('無料公開のAI運営上限（1組織・月額）：500円相当')
            ->assertSee('OpenAI API側の利用枠・レート制限は別に適用され')
            ->assertSee('換算レートが未設定です。', false)
            ->assertSee('OPSDECK_AI_USD_TO_JPY_RATE')
            ->assertSee('価格が未登録のモデル')
            ->assertSee('custom-model-without-price')
            ->assertSee('—');

        config(['opsdeck.ai.usd_to_jpy_rate' => 150.5]);
        AiUsageLog::query()->where('model', 'gpt-6-luna')->update(['estimated_cost_jpy' => 3.01]);
        $this->actingAs($user)->get(route('admin.ai-cost'))
            ->assertOk()
            ->assertSee('現在の運営上限換算：約$3.3223/月（1 USD = 150.50円）。', false)
            ->assertSee('利用可否の判定はこの換算額とモデル単価を使います。', false);
        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('3.01円');
    }

    public function test_ai_cost_page_shows_current_month_totals_and_company_breakdown_without_prior_month_usage(): void
    {
        $user = User::factory()->create(['email' => 'platform-ai-monthly-cost-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.ai.usd_to_jpy_rate' => 150]);
        $firstOrganization = Organization::query()->create([
            'name' => '月次AI費用会社A',
            'slug' => 'monthly-ai-cost-a-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $secondOrganization = Organization::query()->create([
            'name' => '月次AI費用会社B',
            'slug' => 'monthly-ai-cost-b-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $firstOrganization->id,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'input_tokens' => 1000,
            'output_tokens' => 200,
            'estimated_cost_usd' => 0.0002,
            'estimated_cost_jpy' => 0.03,
            'success' => true,
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $secondOrganization->id,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'input_tokens' => 2000,
            'output_tokens' => 400,
            'estimated_cost_usd' => 0.0004,
            'estimated_cost_jpy' => 0.06,
            'success' => true,
        ]);
        $previousMonthUsage = AiUsageLog::query()->create([
            'organization_id' => $firstOrganization->id,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'purpose' => 'import_column_mapping',
            'input_tokens' => 5000,
            'output_tokens' => 1000,
            'estimated_cost_usd' => 0.001,
            'estimated_cost_jpy' => 0.15,
            'success' => true,
        ]);
        DB::table('ai_usage_logs')->where('id', $previousMonthUsage->id)->update([
            'created_at' => now()->subMonth()->startOfMonth(),
            'updated_at' => now()->subMonth()->startOfMonth(),
        ]);

        $this->actingAs($user)->get(route('admin.ai-cost'))
            ->assertOk()
            ->assertSee('今月のOpsDeck AI利用')
            ->assertSee('利用回数：2回')
            ->assertSee('入力：3,000 tokens')
            ->assertSee('出力：600 tokens')
            ->assertSee('推定原価：$0.000600（0.09円）')
            ->assertSee('月次AI費用会社A')
            ->assertSee('月次AI費用会社B')
            ->assertSee('ほかのサービスの利用やOpenAIアカウント残高は含まず')
            ->assertDontSee($user->email);
    }

    public function test_platform_admin_can_find_an_import_failure_by_the_reference_shown_to_the_member(): void
    {
        $user = User::factory()->create(['email' => 'platform-import-reference-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $organization = Organization::query()->create(['name' => 'Import Reference Org', 'slug' => 'import-reference-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $targetBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'sensitive-source-name.csv', 'status' => 'failed']);
        $otherBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'xlsx', 'source_name' => 'other-source-name.xlsx', 'status' => 'failed']);
        $reference = 'IMP-'.$targetBatch->id;

        $this->actingAs($user)->get(route('admin.queue-errors', ['import_reference' => $reference]))
            ->assertOk()
            ->assertSee($reference)
            ->assertDontSee('<li>IMP-'.$otherBatch->id.' /', false)
            ->assertDontSee('sensitive-source-name.csv')
            ->assertDontSee('other-source-name.xlsx');
    }

    public function test_platform_admin_can_identify_stale_imports_without_exposing_source_names(): void
    {
        $user = User::factory()->create(['email' => 'platform-stale-import-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.import.stale_after_minutes' => 10]);
        $organization = Organization::query()->create(['name' => 'Stale Import Org', 'slug' => 'stale-import-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $staleBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'confidential-stale-upload.csv', 'status' => 'previewing']);
        $freshBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'xlsx', 'source_name' => 'fresh-upload.xlsx', 'status' => 'previewing']);
        $retryingBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'retrying-upload.csv', 'status' => 'retrying']);
        $rollingBackBatch = ImportBatch::query()->create(['organization_id' => $organization->id, 'source_type' => 'csv', 'source_name' => 'rolling-back-upload.csv', 'status' => 'rolling_back']);
        DB::table('import_batches')->whereIn('id', [$staleBatch->id, $retryingBatch->id, $rollingBackBatch->id])->update(['updated_at' => now()->subMinutes(11)]);

        try {
            $this->actingAs($user)->get(route('admin.queue-errors'))
                ->assertOk()
                ->assertSee('処理が滞留しているImport')
                ->assertSee('IMP-'.$staleBatch->id)
                ->assertSee('CSVファイル / 状態：プレビュー準備中')
                ->assertSee('状態：再試行の準備中')
                ->assertSee('状態：取消処理中')
                ->assertSee('処理を動かすプログラム（Worker）の稼働を確認してもらい')
                ->assertSee('再試行ボタンを押さないよう案内してください。')
                ->assertSee('実行待ちの処理（Queue）')
                ->assertSee('データベースを直接修正したり取消を再実行したりせず')
                ->assertDontSee('<li>IMP-'.$freshBatch->id.' /', false)
                ->assertDontSee('confidential-stale-upload.csv')
                ->assertDontSee('fresh-upload.xlsx')
                ->assertSee('この画面から自動で再実行することはありません。');
        } finally {
            ImportBatch::query()->whereKey([$staleBatch->id, $freshBatch->id, $retryingBatch->id, $rollingBackBatch->id])->delete();
            $organization->delete();
        }
    }

    public function test_platform_admin_import_error_filter_rejects_query_injection(): void
    {
        $user = User::factory()->create(['email' => 'platform-import-filter-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);

        $this->actingAs($user)->get(route('admin.queue-errors', ['import_reference' => 'IMP-1 OR 1=1']))
            ->assertSessionHasErrors('import_reference');
    }

    public function test_platform_admin_can_find_the_stripe_event_reference_shown_to_the_customer(): void
    {
        $user = User::factory()->create(['email' => 'platform-stripe-reference-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $eventId = 'evt_customer_reference_'.Str::uuid();
        StripeWebhookEvent::query()->create(['stripe_event_id' => $eventId, 'event_type' => 'invoice.payment_failed', 'payload_hash' => hash('sha256', $eventId), 'processed_at' => now()]);

        $this->actingAs($user)->get(route('admin.queue-errors', ['stripe_reference' => 'STRIPE-'.$eventId]))
            ->assertOk()
            ->assertSee('STRIPE-'.$eventId)
            ->assertSee('請求のお支払い失敗（invoice.payment_failed）')
            ->assertSee('処理済み');
    }

    public function test_platform_admin_can_find_a_stale_unprocessed_webhook_by_reference(): void
    {
        $user = User::factory()->create(['email' => 'platform-stale-webhook-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.stripe_webhook.stale_after_minutes' => 5]);
        $eventId = 'evt_stale_reference_'.Str::uuid();
        DB::table('stripe_webhook_events')->insert([
            'stripe_event_id' => $eventId,
            'event_type' => 'invoice.paid',
            'payload_hash' => hash('sha256', $eventId),
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(6),
        ]);

        try {
            $this->actingAs($user)->get(route('admin.queue-errors'))
                ->assertOk()
                ->assertSee('Stripe通知の処理待ち')
                ->assertSee('アプリ側の処理が完了していません')
                ->assertDontSee('Stripeからの再送状況を確認してください')
                ->assertSee('STRIPE-'.$eventId)
                ->assertSee('請求のお支払い完了（invoice.paid）')
                ->assertSee(route('admin.queue-errors', ['stripe_reference' => 'STRIPE-'.$eventId]), false);
        } finally {
            DB::table('stripe_webhook_events')->where('stripe_event_id', $eventId)->delete();
        }
    }

    public function test_platform_admin_sees_a_japanese_fallback_for_an_unknown_stripe_event_type(): void
    {
        $user = User::factory()->create(['email' => 'platform-unknown-stripe-event-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $eventId = 'evt_unknown_type_'.Str::uuid();
        StripeWebhookEvent::query()->create([
            'stripe_event_id' => $eventId,
            'event_type' => 'provider.custom_event',
            'payload_hash' => hash('sha256', $eventId),
            'processing_error' => '処理エラー',
        ]);

        $this->actingAs($user)->get(route('admin.queue-errors'))
            ->assertOk()
            ->assertSee('Stripe通知の処理エラー')
            ->assertSee('その他のStripe通知（provider.custom_event）');
    }

    public function test_platform_admin_stripe_reference_filter_rejects_query_injection(): void
    {
        $user = User::factory()->create(['email' => 'platform-stripe-filter-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);

        $this->actingAs($user)->get(route('admin.queue-errors', ['stripe_reference' => 'STRIPE-evt_1 OR 1=1']))
            ->assertSessionHasErrors('stripe_reference');
    }

    public function test_platform_admin_can_view_health_page(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $organization = Organization::factory()->create();
        $customerFilePath = 'shared/customer-data-'.Str::uuid().'.csv';
        $evidenceFilePath = 'shared/policy-'.Str::uuid().'.pdf';
        ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'health-import',
            'source_file_path' => $customerFilePath,
            'source_storage_disk' => 'local',
            'source_file_size_bytes' => 1234,
            'status' => 'completed',
        ]);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Health evidence',
            'evidence_type' => 'policy',
            'storage_disk' => 'local',
            'storage_path' => $customerFilePath,
            'mime_type' => 'text/csv',
            'file_size' => 321,
            'uploaded_by' => $user->id,
        ]);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Other health evidence',
            'evidence_type' => 'policy',
            'storage_disk' => 'local',
            'storage_path' => $evidenceFilePath,
            'mime_type' => 'application/pdf',
            'file_size' => 456,
            'uploaded_by' => $user->id,
        ]);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Large health evidence',
            'evidence_type' => 'policy',
            'storage_disk' => 'local',
            'storage_path' => 'admin-health/large-'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 850 * 1024,
            'uploaded_by' => $user->id,
        ]);
        $backupRoot = storage_path('framework/testing-admin-health-'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot, 'opsdeck.storage_quotas.platform_limit_megabytes' => 1]);
        config(['opsdeck.release_features.storage_addon' => true]);
        DB::table('storage_addon_reconciliation_runs')->insert([
            'status' => 'mismatch',
            'organizations_checked' => 1,
            'mismatch_count' => 1,
            'unavailable_count' => 0,
            'mismatch_organization_ids' => json_encode([$organization->id], JSON_THROW_ON_ERROR),
            'unavailable_organization_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('システムの稼働状況')->assertSee('総合状態')->assertSee('role="status"', false)->assertSee('aria-live="polite"', false)->assertSee(route('admin.queue-errors'))->assertSee(route('admin.product-metrics'))->assertSee('最新バックアップ')->assertSee('最新バックアップ確認日時')->assertSee('バックアップの記録件数')->assertSee('計測済みバックアップデータ容量')->assertSee('容量を計測できなかった過去バックアップ')->assertSee('バックアップ保存先の空き容量')->assertSee('OpsDeck顧客ファイル保存量（Import元・証跡）')->assertSee('872,090 B')->assertSee('OpsDeckファイル保存枠（サービス全体）')->assertSee('1.0 MiB（残り176,486 B・使用率84%）')->assertSee('DBテーブル・索引容量（概算）')->assertSee('OpsDeck以外のサービスは含みません')->assertSee('DB容量はMySQL/MariaDBの表と索引の概算です。')->assertSee('今後作成するDB・証跡・取込元のバックアップは、実行サーバー内の設定されたローカル保存先に保存し、R2へ複製しません。')->assertSee('既定では暗号化されません。')->assertSee('日を過ぎた世代のうちローカル検証に成功したものを整理します。')->assertSee('方針導入前の世代は自動削除しません。')->assertSee('データベースの更新状況')->assertSee('定期処理の稼働確認')->assertSee('要確認')->assertSee('php artisan schedule:run')->assertSee('バックグラウンド処理の失敗件数')->assertSee('Stripeからの契約通知で処理エラーになった件数')->assertSee('受信後に処理待ちとなっているStripe契約通知')->assertSee('追加容量のStripe定期照合')->assertSee('要確認（契約と容量に差異）')->assertSee('差異がある組織ID')->assertSee((string) $organization->id)->assertSee('処理エラーは、Stripeから通知を受け取った後にアプリ側の処理が失敗した状態です。')->assertSee('長時間止まっているデータ取込の件数');
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertDontSee('対象外（MySQL/MariaDBのみ計測）');
            } else {
                $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('対象外（MySQL/MariaDBのみ計測）');
            }
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('追加容量の差異が出た場合の確認手順')
                ->assertSee('php artisan opsdeck:storage-addon:reconcile')
                ->assertSee('契約や容量を変更しません')
                ->assertSee('保存済みファイルの削除や手動変更は行いません');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            ImportBatch::query()->where('organization_id', $organization->id)->delete();
            Evidence::query()->where('organization_id', $organization->id)->delete();
            $organization->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_warns_when_failed_jobs_table_is_missing(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-missing-failed-jobs-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-missing-failed-jobs-'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);
        $schemaBuilder = Schema::getFacadeRoot();
        Schema::shouldReceive('hasTable')->andReturnUsing(static function (string $table) use ($schemaBuilder): bool {
            return $table !== 'failed_jobs' && $schemaBuilder->hasTable($table);
        });

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('要確認')
                ->assertSee('未確認（失敗履歴を保存する表がありません）');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_marks_latest_backup_as_available(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-backup-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-backup/'.Str::uuid());
        $latestBackup = $backupRoot.DIRECTORY_SEPARATOR.'20260927_020000';
        File::ensureDirectoryExists($latestBackup);
        File::put($latestBackup.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($latestBackup.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('正常')->assertSee('利用可能')->assertSee('0件分')->assertSee('容量を計測できなかった過去バックアップ')->assertSee('バックアップ保存先の空き容量');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_warns_when_database_migrations_are_pending(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-pending-migrations-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $migrationHealth = $this->mock(DatabaseMigrationHealthService::class);
        $migrationHealth->shouldReceive('pendingCount')->once()->andReturn(2);
        $this->app->instance(DatabaseMigrationHealthService::class, $migrationHealth);

        $this->actingAs($user)->get(route('admin.health'))
            ->assertOk()
            ->assertSee('データベースの更新状況')
            ->assertSee('未適用 2件')
            ->assertSee('要確認');
    }

    public function test_health_page_warns_when_the_last_backup_restore_test_failed(): void
    {
        $user = User::factory()->create(['email' => 'platform-restore-health-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $this->app['env'] = 'production';
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        DB::table('backup_restore_checks')->insert(['status' => 'failed', 'completed_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-restore-health/'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('要確認')
                ->assertSee('バックアップ復元テスト')
                ->assertSee('失敗');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            DB::table('backup_restore_checks')->where('status', 'failed')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_warns_when_latest_backup_is_stale(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-stale-backup-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.backup.max_age_hours' => 26]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-stale-backup/'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'old';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->subHours(27)->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('要確認')->assertSee('期限超過');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_marks_latest_backup_invalid_when_database_file_is_missing(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-invalid-backup-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-invalid-backup/'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))->assertOk()->assertSee('要確認')->assertSee('検証失敗');
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_health_page_reports_stale_import_batches(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-import-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.import.stale_after_minutes' => 10]);
        $organization = Organization::query()->create([
            'name' => 'Admin health import',
            'slug' => 'admin-health-import-'.Str::lower(Str::random(12)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        DB::table('import_batches')->insert([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'admin-health-import',
            'status' => 'analyzing',
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);
        DB::table('import_batches')->insert([
            [
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'admin-health-retrying',
                'status' => 'retrying',
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
            ],
            [
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'admin-health-rolling-back',
                'status' => 'rolling_back',
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
            ],
        ]);

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('長時間止まっているデータ取込の件数</dt><dd>3</dd>', false);
        } finally {
            DB::table('import_batches')->where('organization_id', $organization->id)->delete();
            $organization->delete();
        }
    }

    public function test_health_page_reports_stale_database_queue_jobs(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-queue-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'opsdeck.queue.stale_after_minutes' => 10, 'queue.default' => 'database']);
        $queueName = 'admin-health-'.Str::lower(Str::random(12));
        $now = now()->timestamp;
        DB::table('jobs')->insert([
            'queue' => $queueName,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 1200,
            'created_at' => $now - 1200,
        ]);
        $queueWorkerCommand = sprintf(
            'php artisan queue:work %s --queue=%s --timeout=%d',
            config('queue.default'),
            config('queue.connections.'.config('queue.default').'.queue', 'default'),
            max((int) config('opsdeck.import.job_timeout_seconds'), (int) config('services.stripe.webhook_job_timeout_seconds')),
        );

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('長時間待っている処理の件数</dt><dd>1</dd>', false)
                ->assertSee('バックグラウンド処理を動かすプログラム')
                ->assertSee($queueWorkerCommand)
                ->assertSee('待機中のメール送信なども実行される可能性があります。')
                ->assertSee('要確認');
        } finally {
            DB::table('jobs')->where('queue', $queueName)->delete();
        }
    }

    public function test_health_page_marks_an_unmonitored_queue_driver_as_unknown(): void
    {
        $user = User::factory()->create(['email' => 'platform-health-unsupported-queue-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email], 'queue.default' => 'sqs']);
        DB::table('scheduler_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_seen_at' => now()]);
        $backupRoot = storage_path('framework/testing-admin-health-queue-'.Str::uuid());
        $backupDirectory = $backupRoot.DIRECTORY_SEPARATOR.'latest';
        File::ensureDirectoryExists($backupDirectory);
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'database.sql', 'backup');
        File::put($backupDirectory.DIRECTORY_SEPARATOR.'manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => ['driver' => 'mysql', 'file' => 'database.sql'], 'evidence_files' => []], JSON_THROW_ON_ERROR));
        config(['opsdeck.backup.path' => $backupRoot]);

        try {
            $this->actingAs($user)->get(route('admin.health'))
                ->assertOk()
                ->assertSee('要確認')
                ->assertSee('未確認（この処理方式では最古の待機時間を自動判定できません）')
                ->assertSee('取込の制限時間と再試行設定</dt><dd>未確認（バックグラウンド処理基盤の設定確認が必要）</dd>', false)
                ->assertSee('長時間待っている処理の件数</dt><dd>正確な件数は未取得</dd>', false);
        } finally {
            DB::table('scheduler_heartbeats')->where('name', 'scheduler')->delete();
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_admin_dashboard_converts_annual_contracts_to_monthly_cost(): void
    {
        $user = User::factory()->create(['email' => 'platform-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$user->email]]);
        $suffix = Str::lower(Str::random(8));
        $organization = Organization::query()->create(['name' => 'Admin Metrics '.$suffix, 'slug' => 'admin-metrics-'.$suffix, 'timezone' => 'Asia/Tokyo', 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Vendor', 'contract_name' => 'Annual', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000.50, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active']);
        $monthlyCalculator = app(ContractMonthlyCostCalculator::class);
        $expectedMonthlyJpy = $monthlyCalculator->currencyUnitsFromCents($monthlyCalculator->monthlyTotalInCents(
            Contract::query()->where('status', 'active')->where('currency', 'JPY')->get(['amount', 'billing_cycle', 'currency']),
            'JPY',
        ));

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()->assertSee(number_format($expectedMonthlyJpy));
    }
}
