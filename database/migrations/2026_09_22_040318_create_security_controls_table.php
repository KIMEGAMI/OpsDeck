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
        Schema::create('security_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('control_code');
            $table->string('title');
            $table->string('category');
            $table->text('description');
            $table->string('status');
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('review_frequency')->nullable();
            $table->date('last_reviewed_at')->nullable();
            $table->date('next_review_at')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'control_code']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'next_review_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive. Production schema changes are forward-only.
    }
};
