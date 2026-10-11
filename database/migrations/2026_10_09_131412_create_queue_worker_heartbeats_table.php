<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('queue_worker_heartbeats')) {
            Schema::create('queue_worker_heartbeats', function (Blueprint $table): void {
                $table->string('worker_key', 64)->primary();
                $table->string('connection_name', 64);
                $table->string('queue_name', 255);
                $table->timestamp('last_seen_at');
            });
        }

        if (! Schema::hasIndex('queue_worker_heartbeats', ['connection_name', 'queue_name', 'last_seen_at'])) {
            Schema::table('queue_worker_heartbeats', function (Blueprint $table): void {
                $table->index(['connection_name', 'queue_name', 'last_seen_at'], 'qwh_connection_queue_seen_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_worker_heartbeats');
    }
};
