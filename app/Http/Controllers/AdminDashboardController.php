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
use App\Services\ImportFailureMetrics;
use App\Services\QueueHealthService;
use App\Services\StripeWebhookHealthService;
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
                'aiCostJpy' => AiUsageLog::query()->sum('estimated_cost_jpy'),
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
        $usage = AiUsageLog::query()
            ->selectRaw('provider, model, COUNT(*) as request_count, SUM(estimated_cost_jpy) as cost_jpy')
            ->groupBy('provider', 'model')
            ->orderByDesc('cost_jpy')
            ->get();

        return view('admin.ai-cost', compact('usage'));
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
            ->where(function (EloquentBuilder $query) use ($staleCutoff): void {
                $query->where(function (EloquentBuilder $queuedQuery) use ($staleCutoff): void {
                    $queuedQuery->whereIn('status', ['queued', 'previewing', 'analyzing'])
                        ->where(function (EloquentBuilder $queuedTimeQuery) use ($staleCutoff): void {
                            $queuedTimeQuery->whereNull('updated_at')->orWhere('updated_at', '<=', $staleCutoff);
                        });
                })->orWhere(function (EloquentBuilder $executingQuery) use ($staleCutoff): void {
                    $executingQuery->where('status', 'executing')
                        ->where(function (EloquentBuilder $startedQuery) use ($staleCutoff): void {
                            $startedQuery->whereNull('started_at')->orWhere('started_at', '<=', $staleCutoff);
                        });
                });
            })
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

    public function health(BackupHealthService $backupHealth, BackupRestoreHealthService $backupRestoreHealth, QueueHealthService $queueHealth, StripeWebhookHealthService $webhookHealth, DatabaseMigrationHealthService $migrationHealth): View
    {
        $backupReport = $backupHealth->report();
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
        $schedulerHeartbeatStatus = 'unknown';
        if (Schema::hasTable('scheduler_heartbeats')) {
            $schedulerHeartbeat = DB::table('scheduler_heartbeats')->where('name', 'scheduler');
            if ($schedulerHeartbeat->exists()) {
                $heartbeatCutoff = now()->subMinutes((int) config('opsdeck.scheduler.heartbeat_max_age_minutes'));
                $schedulerHeartbeatStatus = $schedulerHeartbeat->where('last_seen_at', '>', $heartbeatCutoff)->exists() ? 'ok' : 'stale';
            }
        }
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) Schema::getConnection()->table('failed_jobs')->count() : null;
        $staleQueuedJobs = $queueHealth->staleQueuedJobs();
        $queueTimeoutStatus = $queueHealth->importTimeoutStatus();
        $queueWorkerStatus = $queueHealth->workerStatus();
        $webhookErrors = $webhookHealth->processingErrorCount();
        $staleWebhooks = $webhookHealth->staleUnprocessedCount();
        $staleCutoff = now()->subMinutes(max(1, (int) config('opsdeck.import.stale_after_minutes')));
        $staleImportBatches = ImportBatch::query()
            ->where(function (EloquentBuilder $query) use ($staleCutoff): void {
                $query->where(function (EloquentBuilder $queuedQuery) use ($staleCutoff): void {
                    $queuedQuery->whereIn('status', ['queued', 'previewing', 'analyzing'])->where(function (EloquentBuilder $queuedTimeQuery) use ($staleCutoff): void {
                        $queuedTimeQuery->whereNull('updated_at')->orWhere('updated_at', '<=', $staleCutoff);
                    });
                })->orWhere(function (EloquentBuilder $executingQuery) use ($staleCutoff): void {
                    $executingQuery->where('status', 'executing')->where(function (EloquentBuilder $startedQuery) use ($staleCutoff): void {
                        $startedQuery->whereNull('started_at')->orWhere('started_at', '<=', $staleCutoff);
                    });
                });
            })
            ->count();
        $healthStatus = $migrationStatus !== 'ok' || $schedulerHeartbeatStatus !== 'ok' || $failedJobs === null || $failedJobs > 0 || $staleQueuedJobs === null || $staleQueuedJobs > 0 || in_array($queueTimeoutStatus, ['invalid', 'unknown'], true) || in_array($queueWorkerStatus, ['stale', 'unknown', 'invalid'], true) || $webhookErrors > 0 || $staleWebhooks > 0 || $staleImportBatches > 0 || $backupReport['status'] !== 'ok' || ! in_array($backupRestoreReport['status'], ['ok', 'not_applicable'], true) ? '要確認' : '正常';

        return view('admin.health', compact('healthStatus', 'backupStatus', 'latestBackupAt', 'backupRestoreStatus', 'backupRestoreReport', 'migrationStatus', 'pendingMigrationCount', 'schedulerHeartbeatStatus', 'failedJobs', 'staleQueuedJobs', 'queueTimeoutStatus', 'queueWorkerStatus', 'webhookErrors', 'staleWebhooks', 'staleImportBatches'));
    }
}
