<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasService extends Model
{
    protected $fillable = ['organization_id', 'name', 'vendor', 'category', 'website_url', 'status', 'memo'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SaasAssignment::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'saas_service_id');
    }
}
