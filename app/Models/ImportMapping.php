<?php

namespace App\Models;

use Database\Factories\ImportMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportMapping extends Model
{
    /** @use HasFactory<ImportMappingFactory> */
    use HasFactory;

    protected $fillable = ['import_batch_id', 'target_type', 'column_map', 'normalization_rules', 'value_mappings', 'import_profile_id', 'import_profile_version', 'ai_suggestion_count', 'ai_suggestion_accepted_count', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['column_map' => 'array', 'normalization_rules' => 'array', 'value_mappings' => 'array', 'import_profile_version' => 'integer', 'ai_suggestion_count' => 'integer', 'ai_suggestion_accepted_count' => 'integer'];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function importProfile(): BelongsTo
    {
        return $this->belongsTo(ImportProfile::class);
    }
}
