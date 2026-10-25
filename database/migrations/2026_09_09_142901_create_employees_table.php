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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('employee_number')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('employment_status');
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->string('job_title')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'employee_number']);
            $table->index(['organization_id', 'employment_status']);
            $table->index(['organization_id', 'department_id']);
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
