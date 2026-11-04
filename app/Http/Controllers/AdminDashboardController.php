<?php

namespace App\Http\Controllers;

use App\Models\AiUsageLog;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use App\Services\BackupHealthService;
use App\Services\BackupRestoreHealthService;
use App\Services\ContractMonthlyCostCalculator;
use App\Services\DatabaseMigrationHealthService;
use App\Services\DatabaseStorageUsageService;
use App\Services\ImportFailureMetrics;
use App\Services\OrganizationStorageQuotaService;
use App\Services\QueueHealthService;
use App\Services\SchedulerHealthService;
use App\Services\StripeWebhookHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminDashboardController extends Controller
{
    public function __invoke(ImportFailureMetrics $importFailureMetrics): View
    {
        $importMetrics = $importFailureMetrics->summarize();
        $monthlyCalculator = app(ContractMonthlyCostCalculator::class);
        $activeJpyContracts = Contract::query()
            ->where('status', 'active')
            ->where('currency', 'JPY')
            ->get(['amount', 'billing_cycle', 'currency']);

        return view('admin.dashboard', [
            'metrics' => [
                'organizations' => Organization::query()->count(),
                'activeOrganizations' => Organization::query()->whereIn('billing_status', ['active', 'trialing'])->count(),
                'mrrJpy' => $monthlyCalculator->currencyUnitsFromCents($monthlyCalculator->monthlyTotalInCents($activeJpyContracts, 'JPY')),
                'employees' => Employee::query()->count(),
                'assets' => Asset::query()->count(),
                'contracts' => Contract::query()->count(),
                'aiRequests' => AiUsageLog::query()->count(),
                'aiCostUsd' => AiUsageLog::query()->sum('estimated_cost_usd'),
                'aiCostJpy' => AiUsageLog::query()->sum('estimated_cost_jpy'),
                'aiPricingUnavailableRequests' => AiUsageLog::query()->where('error_code', 'pricing_unavailable')->count(),
                'aiUsdToJpyRateConfigured' => is_numeric(config('opsdeck.ai.usd_to_jpy_rate')) && (float) config('opsdeck.ai.usd_to_jpy_rate') > 0,
                'imports' => $importMetrics['total'],
                'importFailureRate' => $importMetrics['rate'],
                'googleSheetsConnections' => GoogleSheetConnection::query()->whereNull('revoked_at')->count(),
                'webhookErrors' => StripeWebhookEvent::query()->whereNotNull('processing_error')->count(),
                'queueErrors' => Schema::hasTable('failed_jobs') ? (int) Schema::getConnection()->table('failed_jobs')->count() : null,
            ],
        ]);
    }

    public function aiCost(): View
    {
        $monthStart = now(config('app.timezone'))->startOfMonth();
        $monthlyUsage = AiUsageLog::query()
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('COUNT(*) as request_count, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, COALESCE(SUM(estimated_cost_usd), 0) as cost_usd, COALESCE(SUM(estimated_cost_jpy), 0) as cost_jpy, SUM(CASE WHEN error_code = ? THEN 1 ELSE 0 END) as unpriced_request_count', ['pricing_unavailable'])
            ->first();

        $monthlyOrganizationUsage = DB::table('ai_usage_logs')
            ->join('organizations', 'organizations.id', '=', 'ai_usage_logs.organization_id')
            ->where('ai_usage_logs.created_at', '>=', $monthStart)
            ->select('organizations.id as organization_id', 'organizations.name as organization_name')
            ->selectRaw('COUNT(ai_usage_logs.id) as request_count, SUM(ai_usage_logs.input_tokens) as input_tokens, SUM(ai_usage_logs.output_tokens) as output_tokens, SUM(ai_usage_logs.estimated_cost_usd) as cost_usd, SUM(ai_usage_logs.estimated_cost_jpy) as cost_jpy, SUM(CASE WHEN ai_usage_logs.error_code = ? THEN 1 ELSE 0 END) as unpriced_request_count', ['pricing_unavailable'])
            ->groupBy('organizations.id', 'organizations.name')
            ->orderByDesc('cost_usd')
            ->orderBy('organizations.id')
            ->paginate((int) config('opsdeck.pagination.per_page'));

        $usage = AiUsageLog::query()
            ->selectRaw('provider, model, COUNT(*) as request_count, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(estimated_cost_usd) as cost_usd, SUM(estimated_cost_jpy) as cost_jpy, SUM(CASE WHEN error_code = ? THEN 1 ELSE 0 END) as unpriced_request_count', ['pricing_unavailable'])
            ->groupBy('provider', 'model')
            ->orderByDesc('cost_usd')
            ->get();

        $configuredRate = config('opsdeck.ai.usd_to_jpy_rate');
        $usdToJpyRate = is_numeric($configuredRate) && (float) $configuredRate > 0 ? (float) $configuredRate : null;
        $usdToJpyRateConfigured = $usdToJpyRate !== null;
        $aiMonthlyBudgetJpy = (int) config('opsdeck.ai.monthly_budget_jpy.free');
        $aiMonthlyBudgetUsd = $usdToJpyRate === null ? null : $aiMonthlyBudgetJpy / $usdToJpyRate;

        return view('admin.ai-cost', compact('usage', 'monthlyUsage', 'monthlyOrganizationUsage', 'monthStart', 'usdToJpyRate', 'usdToJpyRateConfigured', 'aiMonthlyBudgetJpy', 'aiMonthlyBudgetUsd'));
    }

    public function queueErrors(): View
    {
        $validated = request()->validate([
            'import_reference' => ['nullable', 'regex:/\AIMP-[1-9][0-9]*\z/i'],
            'stripe_reference' => ['nullable', 'regex:/\ASTRIPE-evt_[A-Za-z0-9_-]+\z/i'],
        ]);
        $importReference = isset($validated['import_reference']) ? Str::upper($validated['import_reference']) : null;
        $importId = $importReference === null ? null : (int) Str::after($importReference, 'IMP-');
        $stripeReference = $validated['stripe_reference'] ?? null;
        $stripeEventId = $stripeReference === null ? null : substr($stripeReference, strlen('STRIPE-'));
        $failedJobs = Schema::hasTable('failed_jobs')
            ? Schema::getConnection()->table('failed_jobs')->select(['uuid', 'queue', 'failed_at'])->latest('failed_at')->limit(100)->get()
            : collect();

        $failedImports = ImportBatch::query()
            ->where('status', 'failed')
            ->when($importId !== null, fn (EloquentBuilder $query) => $query->whereKey($importId))
            ->latest()
            ->limit(100)
            ->get(['id', 'source_type', 'created_at']);

        $staleCutoff = now()->subMinutes(max(1, (int) config('opsdeck.import.stale_after_minutes')));
        $staleImports = ImportBatch::query()
            ->stale($staleCutoff)
            ->orderBy('updated_at')
            ->limit((int) config('opsdeck.pagination.per_page'))
            ->get(['id', 'source_type', 'status', 'updated_at', 'started_at']);

        $webhookHealth = app(StripeWebhookHealthService::class);
        $webhookErrors = StripeWebhookEvent::query()
            ->whereNotNull('processing_error')
            ->latest()
            ->limit((int) config('opsdeck.pagination.per_page'))
            ->get(['stripe_event_id', 'event_type', 'created_at']);
        $staleWebhooks = $webhookHealth->staleUnprocessedEvents();
        $stripeReferenceEvent = $stripeEventId === null
            ? null
            : StripeWebhookEvent::query()
                ->where('stripe_event_id', $stripeEventId)
                ->first(['stripe_event_id', 'event_type', 'created_at', 'processed_at', 'processing_error']);

        return view('admin.queue-errors', compact('failedJobs', 'failedImports', 'staleImports', 'importReference', 'stripeReference', 'stripeReferenceEvent', 'webhookErrors', 'staleWebhooks'));
    }

    public function health(BackupHealthService $backupHealth, BackupRestoreHealthService $backupRestoreHealth, QueueHealthService $queueHealth, SchedulerHealthService $schedulerHealth, StripeWebhookHealthService $webhookHealth, DatabaseMigrationHealthService $migrationHealth, OrganizationStorageQuotaService $storageQuotaService, DatabaseStorageUsageService $databaseStorageUsage): View
    {
        $backupReport = $backupHealth->report();
        $platformStorage = $storageQuotaService->platformUsageSummary();
        $platformStorageNeedsAttention = $storageQuotaService->platformCapacityNeedsAttention($platformStorage);
        $databaseStorage = $databaseStorageUsage->summary();
        $databaseStorageNeedsAttention = app()->environment('production') && $databaseStorage['status'] !== 'measured';
        $backupRestoreReport = $backupRestoreHealth->report();
        $backupRestoreStatus = match ($backupRestoreReport['status']) {
            'ok' => '正常',
            'failed' => '失敗',
            'stale' => '期限超過',
            'invalid' => '記録異常',
            'not_applicable' => '対象外（非本番）',
            default => '未確認',
        };
        $backupStatus = match ($backupReport['status']) {
            'ok' => '利用可能',
            'stale' => '期限超過',
            'invalid' => '検証失敗',
            default => '未確認',
        };
        $latestBackupAt = $backupReport['latest_backup_at'];
        $pendingMigrationCount = $migrationHealth->pendingCount();
        $migrationStatus = $pendingMigrationCount === null ? 'unknown' : ($pendingMigrationCount === 0 ? 'ok' : 'pending');
        $schedulerHeartbeatStatus = $schedulerHealth->status();
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) Schema::getConnection()->table('failed_jobs')->count() : null;
        $staleQueuedJobs = $queueHealth->staleQueuedJobs();
        $queueBacklogStatus = $queueHealth->backlogStatus($staleQueuedJobs);
        $queueTimeoutStatus = $queueHealth->importTimeoutStatus();
        $queueWorkerStatus = $queueHealth->workerStatus();
        $webhookErrors = $webhookHealth->processingErrorCount();
        $staleWebhooks = $webhookHealth->staleUnprocessedCount();
        $storageAddonReconciliation = Schema::hasTable('storage_addon_reconciliation_runs')
            ? DB::table('storage_addon_reconciliation_runs')->latest('checked_at')->first()
            : null;
        $storageAddonReconciliationStatus = match ($storageAddonReconciliation->status ?? null) {
            'verified' => '正常',
            'mismatch' => '要確認（契約と容量に差異）',
            'unavailable' => '未確認（Stripeまたは設定を確認）',
            'not_configured' => '未設定（追加容量販売は無効）',
            default => '未実施',
        };
        $storageAddonReconciliationCheckedAt = is_string($storageAddonReconciliation->checked_at ?? null)
            ? CarbonImmutable::parse($storageAddonReconciliation->checked_at)->setTimezone(config('app.timezone'))->format('Y/m/d H:i')
            : '未実施';
        $storageAddonMismatchOrganizationIds = json_decode((string) ($storageAddonReconciliation->mismatch_organization_ids ?? '[]'), true);
        if (! is_array($storageAddonMismatchOrganizationIds)) {
            $storageAddonMismatchOrganizationIds = [];
        }
        $storageAddonUnavailableOrganizationIds = json_decode((string) ($storageAddonReconciliation->unavailable_organization_ids ?? '[]'), true);
        if (! is_array($storageAddonUnavailableOrganizationIds)) {
            $storageAddonUnavailableOrganizationIds = [];
        }
        $staleCutoff = now()->subMinutes(max(1, (int) config('opsdeck.import.stale_after_minutes')));
        $staleImportBatches = ImportBatch::query()
            ->stale($staleCutoff)
            ->count();
        $storageAddonReconciliationNeedsAttention = in_array($storageAddonReconciliation->status ?? null, ['mismatch', 'unavailable'], true);
        $healthStatus = $platformStorageNeedsAttention || $databaseStorageNeedsAttention || $migrationStatus !== 'ok' || $schedulerHeartbeatStatus !== 'ok' || $failedJobs === null || $failedJobs > 0 || in_array($queueBacklogStatus, ['stale', 'unknown', 'invalid'], true) || in_array($queueTimeoutStatus, ['invalid', 'unknown'], true) || in_array($queueWorkerStatus, ['stale', 'unknown', 'invalid'], true) || $webhookErrors > 0 || $staleWebhooks > 0 || $staleImportBatches > 0 || $storageAddonReconciliationNeedsAttention || $backupReport['status'] !== 'ok' || ! in_array($backupRestoreReport['status'], ['ok', 'not_applicable'], true) ? '要確認' : '正常';

        return view('admin.health', compact('healthStatus', 'backupReport', 'platformStorage', 'databaseStorage', 'backupStatus', 'latestBackupAt', 'backupRestoreStatus', 'backupRestoreReport', 'migrationStatus', 'pendingMigrationCount', 'schedulerHeartbeatStatus', 'failedJobs', 'staleQueuedJobs', 'queueBacklogStatus', 'queueTimeoutStatus', 'queueWorkerStatus', 'webhookErrors', 'staleWebhooks', 'staleImportBatches', 'storageAddonReconciliationStatus', 'storageAddonReconciliationCheckedAt', 'storageAddonReconciliation', 'storageAddonMismatchOrganizationIds', 'storageAddonUnavailableOrganizationIds'));
    }
}
