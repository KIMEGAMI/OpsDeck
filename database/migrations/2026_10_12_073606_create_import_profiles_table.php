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
        Schema::create('import_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('profile_key');
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->string('target_type', 64);
            $table->char('source_signature', 64);
            $table->unsignedInteger('header_row_number')->default(1);
            $table->unsignedInteger('data_start_row_number');
            $table->string('selected_sheet_name')->nullable();
            $table->json('source_headers');
            $table->json('column_map');
            $table->json('normalization_rules')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'profile_key', 'version'], 'import_profiles_org_key_version_unique');
            $table->index(['organization_id', 'source_signature', 'target_type'], 'import_profiles_matching_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive for production safety.
    }
};
