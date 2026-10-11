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
        if (Schema::hasTable('user_notification_preferences') && ! Schema::hasColumn('user_notification_preferences', 'announcement_enabled')) {
            Schema::table('user_notification_preferences', function (Blueprint $table): void {
                $table->boolean('announcement_enabled')->default(true)->after('weekly_summary_enabled');
            });
        }
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            //
        });
    }
};
