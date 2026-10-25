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
        Schema::create('questionnaire_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained()->restrictOnDelete();
            $table->string('section')->nullable();
            $table->string('question_number')->nullable();
            $table->text('question_text');
            $table->string('answer_type');
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->index(['questionnaire_id', 'sort_order']);
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
