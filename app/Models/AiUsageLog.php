<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'provider', 'model', 'purpose', 'input_tokens', 'output_tokens', 'estimated_cost_usd', 'estimated_cost_jpy', 'success', 'error_code', 'input_hash'];

    protected function casts(): array
    {
        return ['estimated_cost_usd' => 'decimal:6', 'estimated_cost_jpy' => 'decimal:2', 'success' => 'boolean'];
    }
}
