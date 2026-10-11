<?php

namespace App\Models;

use Database\Factories\RealizedSavingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealizedSaving extends Model
{
    /** @use HasFactory<RealizedSavingFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'contract_id', 'evidence_id', 'created_by', 'description', 'amount', 'currency', 'period',
        'realized_on', 'evidence_reference', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'realized_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
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
