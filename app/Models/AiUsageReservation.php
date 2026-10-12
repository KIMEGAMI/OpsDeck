<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageReservation extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'model', 'purpose', 'reserved_cost_usd', 'expires_at'];

    protected function casts(): array
    {
        return ['reserved_cost_usd' => 'decimal:6', 'expires_at' => 'datetime'];
    }
}
