<?php

namespace App\Services;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;

class QueueWorkerHeartbeatRecorder
{
    /** @var array<string, int> */
    private array $lastRecordedAt = [];

    private bool $retentionCleaned = false;

    public function record(Looping $event): void
    {
        $queues = array_values(array_unique(array_filter(array_map('trim', explode(',', $event->queue)))));
        if ($queues === []) {
            return;
        }

        $now = now();
        $interval = max(1, (int) config('opsdeck.queue.worker_heartbeat_interval_seconds', 60));
        $connectionName = substr($event->connectionName, 0, 64);
        $rows = [];
        foreach ($queues as $queueName) {
            $queueName = substr($queueName, 0, 255);
            $workerKey = hash('sha256', implode('|', [gethostname() ?: 'unknown-host', getmypid(), $connectionName, $queueName]));
            $lastRecordedAt = $this->lastRecordedAt[$workerKey] ?? null;
            if ($lastRecordedAt !== null && $now->timestamp - $lastRecordedAt < $interval) {
                continue;
            }

            $rows[] = [
                'worker_key' => $workerKey,
                'connection_name' => $connectionName,
                'queue_name' => $queueName,
                'last_seen_at' => $now,
            ];
            $this->lastRecordedAt[$workerKey] = $now->timestamp;
        }

        if ($rows === []) {
            return;
        }

        DB::table('queue_worker_heartbeats')->upsert($rows, ['worker_key'], ['connection_name', 'queue_name', 'last_seen_at']);

        if (! $this->retentionCleaned) {
            $retentionDays = max(1, (int) config('opsdeck.queue.worker_heartbeat_retention_days', 30));
            DB::table('queue_worker_heartbeats')->where('last_seen_at', '<', $now->copy()->subDays($retentionDays))->delete();
            $this->retentionCleaned = true;
        }
    }
}
