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
        foreach (['announcement_deliveries', 'weekly_summary_deliveries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('manual_review_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing delivery state is preserved during rollbacks.
    }
};
