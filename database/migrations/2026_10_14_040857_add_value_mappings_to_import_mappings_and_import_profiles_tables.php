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
        Schema::table('import_mappings', function (Blueprint $table): void {
            $table->json('value_mappings')->nullable()->after('normalization_rules');
            $table->foreignId('import_profile_id')->nullable()->after('value_mappings')->constrained('import_profiles')->nullOnDelete();
            $table->unsignedInteger('import_profile_version')->nullable()->after('import_profile_id');
        });

        Schema::table('import_profiles', function (Blueprint $table): void {
            $table->json('value_mappings')->nullable()->after('normalization_rules');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('This migration is forward-only; apply a new migration instead.');
    }
};
