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
        if (! Schema::hasTable('work_time_measurements')) {
            Schema::create('work_time_measurements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('process_name', (int) config('opsdeck.work_time.process_name_max_length'));
                $table->char('process_key', (int) config('opsdeck.work_time.process_key_length'));
                $table->unsignedSmallInteger('before_minutes');
                $table->unsignedSmallInteger('after_minutes');
                $table->unsignedSmallInteger('monthly_occurrences');
                $table->decimal('hourly_rate', 12, 2)->nullable();
                $table->char('currency', (int) config('opsdeck.work_time.currency_code_length'))->nullable();
                $table->date('measured_on');
                $table->string('evidence_reference', (int) config('opsdeck.work_time.evidence_reference_max_length'));
                $table->timestamp('voided_at')->nullable();
                $table->string('void_reason', (int) config('opsdeck.work_time.void_reason_max_length'))->nullable();
                $table->timestamps();
            });
        }

        Schema::table('work_time_measurements', function (Blueprint $table): void {
            $table->unique(['organization_id', 'process_key', 'voided_at'], 'wtime_measurements_active_unique');
            $table->index(['organization_id', 'voided_at'], 'wtime_measurements_org_voided_idx');
            $table->index(['organization_id', 'measured_on'], 'wtime_measurements_org_measured_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Work-time measurements are retained; apply a new migration instead.');
    }
};
