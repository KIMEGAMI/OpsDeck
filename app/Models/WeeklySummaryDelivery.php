<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklySummaryDelivery extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'week_start', 'sent_at', 'failed_at'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
