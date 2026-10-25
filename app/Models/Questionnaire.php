<?php

namespace App\Models;

use Database\Factories\QuestionnaireFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Questionnaire extends Model
{
    /** @use HasFactory<QuestionnaireFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'title', 'requester_name', 'requested_at', 'due_at', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['requested_at' => 'date', 'due_at' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionnaireQuestion::class);
    }

    public function pendingAnswers(): HasManyThrough
    {
        return $this->hasManyThrough(
            QuestionnaireAnswer::class,
            QuestionnaireQuestion::class,
            'questionnaire_id',
            'question_id',
            'id',
            'id',
        )->whereNull('approved_at');
    }
}
