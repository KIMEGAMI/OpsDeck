<?php

namespace App\Models;

use Database\Factories\ImportRowResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowResult extends Model
{
    /** @use HasFactory<ImportRowResultFactory> */
    use HasFactory;

    protected $fillable = [
        'import_batch_id', 'sheet_name', 'source_row_number', 'target_type', 'target_id', 'action',
        'before_json', 'after_json', 'errors_json', 'is_selected',
    ];

    protected function casts(): array
    {
        return ['before_json' => 'array', 'after_json' => 'array', 'errors_json' => 'array', 'is_selected' => 'boolean'];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
