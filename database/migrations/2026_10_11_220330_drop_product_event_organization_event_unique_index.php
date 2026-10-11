<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_events', function (Blueprint $table): void {
            $table->dropUnique('product_events_organization_event_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasDuplicateOrganizationEvents = DB::table('product_events')
            ->whereNotNull('organization_id')
            ->select('organization_id', 'event_key')
            ->groupBy('organization_id', 'event_key')
            ->havingRaw('COUNT(*) > ?', [1])
            ->exists();

        if ($hasDuplicateOrganizationEvents) {
            throw new RuntimeException('Cannot restore the product event uniqueness constraint while duplicate organization events exist.');
        }

        Schema::table('product_events', function (Blueprint $table): void {
            $table->unique(['organization_id', 'event_key'], 'product_events_organization_event_unique');
        });
    }
};
