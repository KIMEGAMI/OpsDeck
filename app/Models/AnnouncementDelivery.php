<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementDelivery extends Model
{
    protected $fillable = ['announcement_id', 'user_id', 'sent_at', 'failed_at', 'suppressed_at', 'resend_idempotency_started_at', 'manual_review_at', 'manual_review_resolved_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'failed_at' => 'datetime', 'suppressed_at' => 'datetime', 'resend_idempotency_started_at' => 'datetime', 'manual_review_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
