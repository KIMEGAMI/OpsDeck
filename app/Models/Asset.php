<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $fillable = ['organization_id', 'asset_number', 'asset_type', 'manufacturer', 'model', 'serial_number', 'os', 'purchased_at', 'purchase_cost', 'warranty_until', 'status', 'location', 'memo'];

    protected function casts(): array
    {
        return ['purchased_at' => 'date', 'purchase_cost' => 'decimal:2', 'warranty_until' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }
}
