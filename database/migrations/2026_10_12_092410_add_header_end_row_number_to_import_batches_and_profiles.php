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
            $table->unsignedInteger('header_end_row_number')->nullable()->after('header_row_number');
        });

        Schema::table('import_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('header_end_row_number')->nullable()->after('header_row_number');
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
