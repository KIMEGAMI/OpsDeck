<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table): void {
            $table->index(['weekly_summary_enabled', 'organization_id'], 'weekly_summary_preference_index');
            $table->index(['announcement_enabled', 'organization_id'], 'announcement_preference_index');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive. Production schema changes are forward-only.
    }
};
