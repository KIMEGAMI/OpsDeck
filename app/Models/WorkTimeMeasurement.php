<?php

namespace App\Models;

use Database\Factories\WorkTimeMeasurementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTimeMeasurement extends Model
{
    /** @use HasFactory<WorkTimeMeasurementFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'created_by', 'voided_by', 'process_name', 'process_key', 'active_process_key', 'before_minutes', 'after_minutes',
        'monthly_occurrences', 'hourly_rate', 'currency', 'measured_on', 'evidence_reference', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'before_minutes' => 'integer',
            'after_minutes' => 'integer',
            'monthly_occurrences' => 'integer',
            'hourly_rate' => 'decimal:2',
            'measured_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
