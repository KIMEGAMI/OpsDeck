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

    protected $fillable = ['import_batch_id', 'target_type', 'column_map', 'normalization_rules', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['column_map' => 'array', 'normalization_rules' => 'array'];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
