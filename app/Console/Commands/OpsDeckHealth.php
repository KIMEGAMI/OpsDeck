<?php

namespace App\Console\Commands;

use App\Services\BackupHealthService;
use App\Services\BackupRestoreHealthService;
use App\Services\QueueHealthService;
use App\Services\StripeWebhookHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('opsdeck:health {--json : Output machine-readable JSON} {--strict : Fail when operational errors exist}')]
#[Description('Check database, backup destination, scheduler heartbeat, and operational queues/imports')]
class OpsDeckHealth extends Command
{
    public function handle(BackupHealthService $backupHealth, BackupRestoreHealthService $backupRestoreHealth, QueueHealthService $queueHealth, StripeWebhookHealthService $webhookHealth): int
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
            $schedulerHeartbeatStatus = 'unknown';
            $failedCheck = 'scheduler_heartbeat';
            if (Schema::hasTable('scheduler_heartbeats')) {
                $schedulerHeartbeat = DB::table('scheduler_heartbeats')->where('name', 'scheduler');
                if ($schedulerHeartbeat->exists()) {
                    $heartbeatCutoff = now()->subMinutes((int) config('opsdeck.scheduler.heartbeat_max_age_minutes'));
                    $schedulerHeartbeatStatus = $schedulerHeartbeat->where('last_seen_at', '>', $heartbeatCutoff)->exists() ? 'ok' : 'stale';
                } else {
                    $schedulerHeartbeatStatus = 'missing';
                }
            }
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
                $staleImports = (int) DB::table('import_batches')
                    ->where(function (QueryBuilder $query) use ($staleCutoff): void {
                        $query->where(function (QueryBuilder $queuedQuery) use ($staleCutoff): void {
                            $queuedQuery->whereIn('status', ['queued', 'previewing', 'analyzing'])->where(function (QueryBuilder $queuedTimeQuery) use ($staleCutoff): void {
                                $queuedTimeQuery->whereNull('updated_at')->orWhere('updated_at', '<=', $staleCutoff);
                            });
                        })->orWhere(function (QueryBuilder $executingQuery) use ($staleCutoff): void {
                            $executingQuery->where('status', 'executing')->where(function (QueryBuilder $startedQuery) use ($staleCutoff): void {
                                $startedQuery->whereNull('started_at')->orWhere('started_at', '<=', $staleCutoff);
                            });
                        });
                    })
                    ->count();
            }
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
        $hasOperationalErrors = $backupStatus !== 'ok' || ! in_array($backupRestoreStatus, ['ok', 'not_applicable'], true) || $schedulerHeartbeatStatus !== 'ok' || $failedJobs === null || $failedJobs > 0 || in_array($queueBacklogStatus, ['stale', 'unknown', 'invalid'], true) || in_array($queueTimeoutStatus, ['invalid', 'unknown'], true) || in_array($queueWorkerStatus, ['stale', 'unknown', 'invalid'], true) || $webhookErrors > 0 || $staleWebhooks > 0 || $staleImports > 0;
        $status = $hasOperationalErrors ? 'warning' : 'ok';
        if ($asJson) {
            $this->line(json_encode(['status' => $status, 'database' => 'ok', 'backup' => $backupStatus, 'scheduler_heartbeat' => $schedulerHeartbeatStatus, 'queue_failed_jobs' => $failedJobs, 'stale_queue_jobs' => $staleQueuedJobs, ...($queueBacklogStatus === 'not_applicable' ? [] : ['queue_backlog_status' => $queueBacklogStatus]), 'queue_timeout_status' => $queueTimeoutStatus, 'queue_worker_status' => $queueWorkerStatus, 'webhook_errors' => $webhookErrors, 'stale_webhooks' => $staleWebhooks, 'stale_import_batches' => $staleImports, 'backup_restore_test' => $backupRestoreStatus], JSON_THROW_ON_ERROR));
        } else {
            $hasOperationalErrors ? $this->warn('OpsDeck health check warning.') : $this->info('OpsDeck health check passed.');
            $this->line('Scheduler heartbeat: '.$schedulerHeartbeatStatus);
            $this->line('Backup: '.$backupStatus.' ('.$backupReport['latest_backup_at'].')');
            $this->line('Backup restore test: '.$backupRestoreStatus.' ('.$backupRestoreReport['completed_at'].')');
            $this->line('Queue failed jobs: '.($failedJobs ?? 'unknown (failed_jobs table missing)'));
            $this->line('Stale queue jobs: '.($staleQueuedJobs === null ? 'unknown (unsupported queue driver or missing jobs table)' : $staleQueuedJobs));
            $this->line('Queue backlog status: '.$queueBacklogStatus);
            $this->line('Queue job timeout configuration: '.$queueTimeoutStatus);
            $this->line('Queue worker: '.$queueWorkerStatus);
            $this->line('Webhook errors: '.$webhookErrors);
            $this->line('Stale unprocessed webhooks: '.$staleWebhooks);
            $this->line('Stale import batches: '.$staleImports);
        }

        return $strict && $hasOperationalErrors ? self::FAILURE : self::SUCCESS;
    }
}
