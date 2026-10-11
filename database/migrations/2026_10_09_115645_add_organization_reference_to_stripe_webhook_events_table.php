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
        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('stripe_created_at')->nullable()->after('payload_hash');
            $table->index(['organization_id', 'stripe_created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            // Intentionally non-destructive. Production schema changes are forward-only.
        });
    }
};
