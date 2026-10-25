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
        Schema::create('import_row_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('source_row_number');
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('action', 32);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('errors_json')->nullable();
            $table->timestamps();
            $table->index(['import_batch_id', 'action']);
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
