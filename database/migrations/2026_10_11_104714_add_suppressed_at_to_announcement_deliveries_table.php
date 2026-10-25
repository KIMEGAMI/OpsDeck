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
        Schema::table('announcement_deliveries', function (Blueprint $table) {
            $table->timestamp('suppressed_at')->nullable()->after('failed_at');
            $table->index(['failed_at', 'sent_at', 'suppressed_at'], 'announcement_delivery_retry_status_index');
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
