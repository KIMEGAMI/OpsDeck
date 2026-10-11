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
        Schema::create('realized_savings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->char('currency', (int) config('opsdeck.cost_savings.currency_code_length'));
            $table->string('period');
            $table->date('realized_on');
            $table->string('evidence_reference');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'realized_on']);
            $table->index(['organization_id', 'currency', 'period']);
            $table->index(['organization_id', 'voided_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive. Production financial history is forward-only.
    }
};
