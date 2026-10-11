<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcement_deliveries', function (Blueprint $table): void {
            $table->index(['announcement_id', 'sent_at', 'failed_at'], 'announcement_delivery_status_index');
        });

        Schema::table('weekly_summary_deliveries', function (Blueprint $table): void {
            $table->index(['organization_id', 'week_start', 'sent_at'], 'weekly_summary_delivery_status_index');
        });

    }

    public function down(): void
    {
        // Intentionally non-destructive. Production schema changes are forward-only.
    }
};
