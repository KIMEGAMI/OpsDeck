<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpactMetricSnapshot extends Model
{
    use HasFactory;

    protected $fillable = ['organization_id', 'import_batch_id', 'stage', 'snapshot_date', 'metrics_json', 'captured_at'];

    protected function casts(): array
    {
        return ['metrics_json' => 'array', 'snapshot_date' => 'date', 'captured_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
