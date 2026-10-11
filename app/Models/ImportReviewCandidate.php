<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportReviewCandidate extends Model
{
    protected $fillable = ['import_batch_id', 'candidate_type', 'source_row_numbers_json', 'confidence', 'details_json', 'status', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['source_row_numbers_json' => 'array', 'details_json' => 'array', 'confidence' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
