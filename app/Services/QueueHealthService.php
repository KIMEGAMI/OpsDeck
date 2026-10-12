<?php

namespace App\Services;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class QueueHealthService
{
    public function staleQueuedJobs(): ?int
    {
        $connectionName = (string) config('queue.default');
        $driver = (string) config('queue.connections.'.$connectionName.'.driver', $connectionName);
        if ($driver === 'sync' && ! app()->environment('production')) {
            return 0;
        }
        if ($driver !== 'database') {
            return null;
        }

        $queueConnection = DB::connection(config('queue.connections.'.$connectionName.'.connection'));
        $queueTable = (string) config('queue.connections.'.$connectionName.'.table', 'jobs');
        if (! $queueConnection->getSchemaBuilder()->hasTable($queueTable)) {
            return null;
        }

        $staleQueueCutoff = now()->subMinutes((int) config('opsdeck.queue.stale_after_minutes'))->timestamp;
        $availableAt = now()->timestamp;

        return (int) $queueConnection->table($queueTable)
            ->where('available_at', '<=', $availableAt)
            ->where(function (QueryBuilder $query) use ($staleQueueCutoff): void {
                $query->where(function (QueryBuilder $queuedQuery) use ($staleQueueCutoff): void {
                    $queuedQuery->whereNull('reserved_at')->where('available_at', '<=', $staleQueueCutoff);
                })->orWhere('reserved_at', '<=', $staleQueueCutoff);
            })
            ->count();
    }

    public function backlogStatus(?int $knownStaleQueuedJobs = null): string
    {
        $connectionName = (string) config('queue.default');
        $driver = (string) config('queue.connections.'.$connectionName.'.driver', $connectionName);
        if ($driver === 'sync' && ! app()->environment('production')) {
            return 'not_applicable';
        }
        if ($driver === 'sync') {
            return 'invalid';
        }

        if ($driver === 'database') {
            $staleQueuedJobs = $knownStaleQueuedJobs ?? $this->staleQueuedJobs();

            return $staleQueuedJobs === null ? 'unknown' : ($staleQueuedJobs > 0 ? 'stale' : 'ok');
        }

        if ($driver !== 'redis') {
            return 'unknown';
        }

        $queueName = (string) config('queue.connections.'.$connectionName.'.queue', 'default');
        try {
            $queue = Queue::connection($connectionName);
            if (! method_exists($queue, 'creationTimeOfOldestPendingJob')) {
                return 'unknown';
            }

            $oldestPendingJobTimestamp = $queue->creationTimeOfOldestPendingJob($queueName);
        } catch (Throwable) {
            return 'unknown';
        }

        if ($oldestPendingJobTimestamp === null) {
            return 'ok';
        }

        if (! is_numeric($oldestPendingJobTimestamp)) {
            return 'unknown';
        }

        $staleQueueCutoff = now()->subMinutes((int) config('opsdeck.queue.stale_after_minutes'))->timestamp;

        return (int) $oldestPendingJobTimestamp <= $staleQueueCutoff ? 'stale' : 'ok';
    }

    public function importTimeoutStatus(): string
    {
        $driver = (string) config('queue.default');
        if ($driver === 'sync') {
            return app()->environment('production') ? 'invalid' : 'not_applicable';
        }
        if (! in_array($driver, ['database', 'redis', 'beanstalkd'], true)) {
            return 'unknown';
        }

        $retryAfter = config('queue.connections.'.$driver.'.retry_after');
        $jobTimeouts = [
            (int) config('opsdeck.import.job_timeout_seconds'),
            (int) config('services.stripe.webhook_job_timeout_seconds'),
        ];

        return is_numeric($retryAfter)
            && min($jobTimeouts) > 0
            && (int) $retryAfter > max($jobTimeouts)
                ? 'ok'
                : 'invalid';
    }

    public function workerStatus(): string
    {
        $driver = (string) config('queue.default');
        if ($driver === 'sync') {
            return app()->environment('production') ? 'invalid' : 'not_applicable';
        }
        if (! in_array($driver, ['database', 'redis', 'beanstalkd', 'sqs'], true) || ! DB::getSchemaBuilder()->hasTable('queue_worker_heartbeats')) {
            return 'unknown';
        }

        $queueName = (string) config('queue.connections.'.$driver.'.queue', 'default');
        $heartbeatCutoff = now()->subMinutes((int) config('opsdeck.queue.stale_after_minutes'));
        $isFresh = DB::table('queue_worker_heartbeats')
            ->where('connection_name', $driver)
            ->where('queue_name', $queueName)
            ->where('last_seen_at', '>', $heartbeatCutoff)
            ->exists();

        return $isFresh ? 'ok' : 'stale';
    }
}
