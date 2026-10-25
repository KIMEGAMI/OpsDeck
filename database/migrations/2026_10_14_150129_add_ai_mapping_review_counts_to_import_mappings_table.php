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
        Schema::table('import_mappings', function (Blueprint $table): void {
            $table->unsignedInteger('ai_suggestion_count')->nullable();
            $table->unsignedInteger('ai_suggestion_accepted_count')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('This migration is forward-only; apply a new migration instead.');
    }
};
