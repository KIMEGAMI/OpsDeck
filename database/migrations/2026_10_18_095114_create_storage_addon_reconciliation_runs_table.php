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
        Schema::create('storage_addon_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 24);
            $table->unsignedInteger('organizations_checked')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->unsignedInteger('unavailable_count')->default(0);
            $table->json('mismatch_organization_ids')->nullable();
            $table->json('unavailable_organization_ids')->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();
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
