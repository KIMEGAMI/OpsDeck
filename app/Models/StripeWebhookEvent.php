<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    protected $fillable = ['organization_id', 'stripe_event_id', 'event_type', 'payload_hash', 'stripe_created_at', 'processed_at', 'processing_error'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
