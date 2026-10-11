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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('saas_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('vendor_name');
            $table->string('contract_name');
            $table->string('contract_type');
            $table->string('billing_cycle');
            $table->decimal('amount', 14, 2);
            $table->string('currency');
            $table->date('started_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('auto_renew');
            $table->date('cancellation_deadline')->nullable();
            $table->date('renewal_date')->nullable();
            $table->unsignedInteger('license_quantity')->nullable();
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('status');
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'ends_at']);
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
