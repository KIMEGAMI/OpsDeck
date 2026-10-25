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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('asset_number');
            $table->string('asset_type');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('os')->nullable();
            $table->date('purchased_at')->nullable();
            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('status');
            $table->string('location')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'asset_number']);
            $table->index(['organization_id', 'status']);
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
