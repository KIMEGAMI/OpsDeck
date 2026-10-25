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
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->index(['organization_id', 'created_at', 'id'], 'import_batches_org_created_id_idx');
        });

        Schema::table('import_row_results', function (Blueprint $table): void {
            $table->index(['import_batch_id', 'source_row_number', 'id'], 'import_rows_batch_row_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('This migration is forward-only; apply a new migration to change the schema.');
    }
};
