<?php

namespace App\Models;

use App\Enums\MembershipRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'invited_by', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at',
        'resend_idempotency_started_at', 'invitation_email_sent_at', 'invitation_email_failed_at',
        'invitation_email_manual_review_at', 'invitation_email_manual_review_resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'resend_idempotency_started_at' => 'datetime',
            'invitation_email_sent_at' => 'datetime',
            'invitation_email_failed_at' => 'datetime',
            'invitation_email_manual_review_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isUsable(): bool
    {
        return $this->lifecycleStatus() === 'pending';
    }

    public function lifecycleStatus(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            ! $this->expires_at->isFuture() => 'expired',
            default => 'pending',
        };
    }

    public function emailDeliveryStatus(): string
    {
        return match (true) {
            $this->invitation_email_sent_at !== null => 'accepted',
            $this->invitation_email_manual_review_at !== null => 'manual_review',
            $this->invitation_email_failed_at !== null => 'failed',
            $this->resend_idempotency_started_at !== null => 'result_unknown',
            default => 'untracked',
        };
    }
}
