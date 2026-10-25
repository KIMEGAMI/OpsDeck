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
            $table->index(
                ['organization_id', 'source_type', 'source_storage_disk', 'source_file_path', 'source_file_size_bytes'],
                'import_batches_storage_quota_idx',
            );
        });

        Schema::table('evidence', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'storage_disk', 'storage_path', 'file_size'],
                'evidence_storage_quota_idx',
            );
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
