<?php

namespace App\Models;

use Database\Factories\QuestionnaireAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionnaireAnswer extends Model
{
    public const DRAFT_STATUS = 'draft';

    public const APPROVED_STATUS = 'approved';

    /** @use HasFactory<QuestionnaireAnswerFactory> */
    use HasFactory;

    protected $fillable = ['question_id', 'answer_text', 'answer_choice', 'answer_status', 'source_type', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireQuestion::class, 'question_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
