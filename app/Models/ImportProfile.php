<?php

namespace App\Models;

use Database\Factories\ImportProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportProfile extends Model
{
    /** @use HasFactory<ImportProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'profile_key', 'version', 'name', 'target_type', 'source_signature', 'header_row_number', 'header_end_row_number', 'data_start_row_number',
        'selected_sheet_name', 'source_headers', 'column_map', 'normalization_rules', 'created_by',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'header_row_number' => 'integer', 'header_end_row_number' => 'integer', 'data_start_row_number' => 'integer', 'source_headers' => 'array', 'column_map' => 'array', 'normalization_rules' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
