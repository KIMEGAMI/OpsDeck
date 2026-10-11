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
        Schema::create('saas_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('saas_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('account_identifier')->nullable();
            $table->string('license_name')->nullable();
            $table->string('status');
            $table->string('mfa_status');
            $table->date('last_reviewed_at')->nullable();
            $table->date('assigned_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'saas_service_id', 'status']);
            $table->index(['organization_id', 'employee_id', 'status']);
            $table->index(['organization_id', 'mfa_status']);
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
