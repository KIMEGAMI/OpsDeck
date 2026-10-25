<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_time_measurements', function (Blueprint $table): void {
            $table->char('active_process_key', (int) config('opsdeck.work_time.process_key_length'))
                ->nullable()
                ->after('process_key');
        });
        DB::table('work_time_measurements')
            ->whereNull('voided_at')
            ->update(['active_process_key' => DB::raw('process_key')]);
        Schema::table('work_time_measurements', function (Blueprint $table): void {
            $table->dropUnique('wtime_measurements_active_unique');
            $table->unique(['organization_id', 'active_process_key'], 'wtime_measurements_current_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Work-time measurement history is forward-only; apply a new migration instead.');
    }
};
