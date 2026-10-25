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
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('storage_addon_gib')->default(0)->after('stripe_subscription_id');
            $table->unsignedInteger('storage_addon_pending_gib')->nullable()->after('storage_addon_gib');
            $table->timestamp('storage_addon_effective_at')->nullable()->after('storage_addon_pending_gib');
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
