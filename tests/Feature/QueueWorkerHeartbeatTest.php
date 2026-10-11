<?php

namespace Tests\Feature;

use App\Services\QueueHealthService;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QueueWorkerHeartbeatTest extends TestCase
{
    public function test_queue_loop_records_deduplicated_heartbeats_without_persisting_host_identity(): void
    {
        config(['opsdeck.queue.worker_heartbeat_interval_seconds' => 60]);
        try {
            Event::dispatch(new Looping('database', 'default, mail,default'));

            $heartbeats = DB::table('queue_worker_heartbeats')->orderBy('queue_name')->get();

            $this->assertCount(2, $heartbeats);
            $this->assertSame(['default', 'mail'], $heartbeats->pluck('queue_name')->all());
            $this->assertSame(['database'], $heartbeats->pluck('connection_name')->unique()->all());
            $this->assertSame(64, strlen($heartbeats->first()->worker_key));
            $firstSeenAt = $heartbeats->firstWhere('queue_name', 'default')->last_seen_at;

            $this->travel(30)->seconds();
            Event::dispatch(new Looping('database', 'default,mail'));
            $this->assertSame($firstSeenAt, DB::table('queue_worker_heartbeats')->where('queue_name', 'default')->value('last_seen_at'));

            $this->travel(31)->seconds();
            Event::dispatch(new Looping('database', 'default,mail'));
            $this->assertGreaterThan(strtotime($firstSeenAt), strtotime(DB::table('queue_worker_heartbeats')->where('queue_name', 'default')->value('last_seen_at')));
        } finally {
            DB::table('queue_worker_heartbeats')->delete();
        }
    }

    public function test_worker_health_requires_a_fresh_heartbeat_for_the_configured_queue(): void
    {
        $health = app(QueueHealthService::class);
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);

        $this->assertSame('stale', $health->workerStatus());

        try {
            DB::table('queue_worker_heartbeats')->insert([
                'worker_key' => hash('sha256', 'test-worker'),
                'connection_name' => 'database',
                'queue_name' => 'default',
                'last_seen_at' => now(),
            ]);
            $this->assertSame('ok', $health->workerStatus());

            DB::table('queue_worker_heartbeats')->update(['last_seen_at' => now()->subMinutes(11)]);
            $this->assertSame('stale', $health->workerStatus());
        } finally {
            DB::table('queue_worker_heartbeats')->delete();
        }
    }

    public function test_synchronous_nonproduction_queue_does_not_require_a_worker(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertSame('not_applicable', app(QueueHealthService::class)->workerStatus());
    }
}
