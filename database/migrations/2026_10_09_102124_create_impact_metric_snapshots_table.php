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
        if (! Schema::hasTable('impact_metric_snapshots')) {
            Schema::create('impact_metric_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('stage', 32);
                $table->date('snapshot_date')->nullable();
                $table->json('metrics_json');
                $table->timestamp('captured_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('impact_metric_snapshots', ['import_batch_id', 'stage'], 'unique')) {
            Schema::table('impact_metric_snapshots', function (Blueprint $table): void {
                $table->unique(['import_batch_id', 'stage'], 'ims_import_stage_uq');
            });
        }

        if (! Schema::hasIndex('impact_metric_snapshots', ['organization_id', 'stage', 'snapshot_date'], 'unique')) {
            Schema::table('impact_metric_snapshots', function (Blueprint $table): void {
                $table->unique(['organization_id', 'stage', 'snapshot_date'], 'ims_org_stage_snapshot_date_uq');
            });
        }

        if (! Schema::hasIndex('impact_metric_snapshots', ['organization_id', 'stage', 'captured_at'])) {
            Schema::table('impact_metric_snapshots', function (Blueprint $table): void {
                $table->index(['organization_id', 'stage', 'captured_at'], 'ims_org_stage_captured_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('impact_metric_snapshots');
    }
};
