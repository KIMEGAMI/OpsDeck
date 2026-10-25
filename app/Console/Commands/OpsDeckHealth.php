<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\BackupHealthService;
use App\Services\BackupRestoreHealthService;
use App\Services\OrganizationStorageQuotaService;
use App\Services\QueueHealthService;
use App\Services\SchedulerHealthService;
use App\Services\StripeWebhookHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('opsdeck:health {--json : Output machine-readable JSON} {--strict : Fail when operational errors exist}')]
#[Description('Check database, backup destination, scheduler heartbeat, and operational queues/imports')]
class OpsDeckHealth extends Command
{
    public function handle(BackupHealthService $backupHealth, BackupRestoreHealthService $backupRestoreHealth, QueueHealthService $queueHealth, SchedulerHealthService $schedulerHealth, StripeWebhookHealthService $webhookHealth, OrganizationStorageQuotaService $storageQuotaService): int
    {
        $asJson = (bool) $this->option('json');
        $strict = (bool) $this->option('strict');
        $failedCheck = 'database_connection';

        try {
            DB::connection()->getPdo();
            $failedCheck = 'backup_destination';
            $backupPath = (string) config('opsdeck.backup.path');
            File::ensureDirectoryExists($backupPath);
            if (! is_writable($backupPath)) {
                throw new \RuntimeException('Backup destination is not writable.');
            }
            $failedCheck = 'backup_health';
            $backupReport = $backupHealth->report();
            $failedCheck = 'backup_restore_test';
            $backupRestoreReport = $backupRestoreHealth->report();
            $failedCheck = 'scheduler_heartbeat';
            $schedulerHeartbeatStatus = $schedulerHealth->status();
            $failedCheck = 'queue';
            $failedJobsTableAvailable = Schema::hasTable('failed_jobs');
            $failedJobs = $failedJobsTableAvailable ? (int) DB::table('failed_jobs')->count() : null;
            $staleQueuedJobs = $queueHealth->staleQueuedJobs();
            $queueBacklogStatus = $queueHealth->backlogStatus($staleQueuedJobs);
            $queueTimeoutStatus = $queueHealth->importTimeoutStatus();
            $queueWorkerStatus = $queueHealth->workerStatus();
            $failedCheck = 'webhooks';
            $webhookErrors = $webhookHealth->processingErrorCount();
            $staleWebhooks = $webhookHealth->staleUnprocessedCount();
            $staleImports = 0;
            $failedCheck = 'imports';
            if (Schema::hasTable('import_batches')) {
                $staleCutoff = now()->subMinutes(max(1, (int) config('opsdeck.import.stale_after_minutes')));
                $staleImports = (int) ImportBatch::query()->stale($staleCutoff)->count();
            }
            $failedCheck = 'storage_addon_reconciliation';
            $storageAddonReconciliation = config('opsdeck.release_features.storage_addon')
                && Schema::hasTable('storage_addon_reconciliation_runs')
                ? DB::table('storage_addon_reconciliation_runs')->latest('checked_at')->first()
                : null;
        } catch (Throwable) {
            if ($asJson) {
                $this->line(json_encode(['status' => 'failed', 'failed_check' => $failedCheck], JSON_THROW_ON_ERROR));
            } else {
                $this->error('OpsDeck health check failed at '.$failedCheck.'.');
            }

            return self::FAILURE;
        }

        $backupStatus = $backupReport['status'];
        $backupRestoreStatus = $backupRestoreReport['status'];
        $platformStorage = null;
        if (Schema::hasTable('import_batches') && Schema::hasTable('evidence')) {
            $platformStorage = $storageQuotaService->platformUsageSummary();
        }
        $platformStorageNeedsAttention = $platformStorage === null
            ? app()->environment('production')
            : $storageQuotaService->platformCapacityNeedsAttention($platformStorage);
        $storageAddonReconciliationNeedsAttention = in_array($storageAddonReconciliation->status ?? null, ['mismatch', 'unavailable'], true);
        $hasOperationalErrors = $backupStatus !== 'ok' || ! in_array($backupRestoreStatus, ['ok', 'not_applicable'], true) || $schedulerHeartbeatStatus !== 'ok' || $failedJobs === null || $failedJobs > 0 || in_array($queueBacklogStatus, ['stale', 'unknown', 'invalid'], true) || in_array($queueTimeoutStatus, ['invalid', 'unknown'], true) || in_array($queueWorkerStatus, ['stale', 'unknown', 'invalid'], true) || $webhookErrors > 0 || $staleWebhooks > 0 || $staleImports > 0 || $platformStorageNeedsAttention || $storageAddonReconciliationNeedsAttention;
        $status = $hasOperationalErrors ? 'warning' : 'ok';
        if ($asJson) {
            $platformStorageFields = app()->environment('production') || ($platformStorage['limit_bytes'] ?? null) !== null
                ? ['platform_storage' => $platformStorage, 'platform_storage_needs_attention' => $platformStorageNeedsAttention]
                : [];
            $storageAddonReconciliationFields = $storageAddonReconciliation === null
                ? []
                : ['storage_addon_reconciliation' => [
                    'status' => $storageAddonReconciliation->status,
                    'checked_at' => $storageAddonReconciliation->checked_at,
                    'organizations_checked' => (int) $storageAddonReconciliation->organizations_checked,
                    'mismatch_count' => (int) $storageAddonReconciliation->mismatch_count,
                    'unavailable_count' => (int) $storageAddonReconciliation->unavailable_count,
                ]];
            $this->line(json_encode(['status' => $status, 'database' => 'ok', 'backup' => $backupStatus, 'scheduler_heartbeat' => $schedulerHeartbeatStatus, 'queue_failed_jobs' => $failedJobs, 'stale_queue_jobs' => $staleQueuedJobs, ...($queueBacklogStatus === 'not_applicable' ? [] : ['queue_backlog_status' => $queueBacklogStatus]), 'queue_timeout_status' => $queueTimeoutStatus, 'queue_worker_status' => $queueWorkerStatus, 'webhook_errors' => $webhookErrors, 'stale_webhooks' => $staleWebhooks, 'stale_import_batches' => $staleImports, 'backup_restore_test' => $backupRestoreStatus, ...$platformStorageFields, ...$storageAddonReconciliationFields], JSON_THROW_ON_ERROR));
        } else {
            $hasOperationalErrors ? $this->warn('OpsDeck health check warning.') : $this->info('OpsDeck health check passed.');
            $this->line('Scheduler heartbeat: '.$schedulerHeartbeatStatus);
            $this->line('Backup: '.$backupStatus.' ('.$backupReport['latest_backup_at'].')');
            $this->line('Backup archives: '.$backupReport['backup_count'].' ('.$backupReport['measured_backup_count'].' size-verified, '.$backupReport['unmeasured_backup_count'].' legacy/unmeasured)');
            $this->line('Backup payload size: '.$backupReport['backup_payload_size_display']);
            $this->line('Backup destination disk: '.$backupReport['disk_free_display'].' free / '.$backupReport['disk_total_display'].' total');
            $this->line('OpsDeck customer files (import sources + evidence): '.($platformStorage['used_display'] ?? 'unknown (required tables are missing)'));
            if ($platformStorage !== null) {
                $this->line('OpsDeck platform file capacity: '.$platformStorage['limit_display'].' limit / '.$platformStorage['remaining_display'].' remaining');
            }
            if ($platformStorageNeedsAttention) {
                $this->warn('OpsDeck platform file capacity needs attention.');
            }
            $this->line('Backup restore test: '.$backupRestoreStatus.' ('.$backupRestoreReport['completed_at'].')');
            $this->line('Queue failed jobs: '.($failedJobs ?? 'unknown (failed_jobs table missing)'));
            $this->line('Stale queue jobs: '.($staleQueuedJobs === null ? 'unknown (unsupported queue driver or missing jobs table)' : $staleQueuedJobs));
            $this->line('Queue backlog status: '.$queueBacklogStatus);
            $this->line('Queue job timeout configuration: '.$queueTimeoutStatus);
            $this->line('Queue worker: '.$queueWorkerStatus);
            $this->line('Webhook errors: '.$webhookErrors);
            $this->line('Stale unprocessed webhooks: '.$staleWebhooks);
            $this->line('Stale import batches: '.$staleImports);
            if ($storageAddonReconciliation !== null) {
                $this->line('Storage add-on reconciliation: '.$storageAddonReconciliation->status.' ('.$storageAddonReconciliation->checked_at.')');
                $this->line('Storage add-on mismatches: '.(int) $storageAddonReconciliation->mismatch_count.'; unavailable: '.(int) $storageAddonReconciliation->unavailable_count);
            } elseif (Schema::hasTable('storage_addon_reconciliation_runs')) {
                $this->line('Storage add-on reconciliation: not run');
            }
        }

        return $strict && $hasOperationalErrors ? self::FAILURE : self::SUCCESS;
    }
}
