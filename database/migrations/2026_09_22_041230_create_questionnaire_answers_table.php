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
        Schema::create('questionnaire_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questionnaire_questions')->restrictOnDelete();
            $table->text('answer_text')->nullable();
            $table->string('answer_choice')->nullable();
            $table->string('answer_status');
            $table->string('source_type');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['question_id', 'answer_status']);
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
