<?php

namespace App\Services;

use App\Models\AnnouncementDelivery;
use App\Models\OrganizationInvitation;
use App\Models\WeeklySummaryDelivery;

class ResendIdempotencyWindow
{
    public function startOrRequireManualReview(AnnouncementDelivery|OrganizationInvitation|WeeklySummaryDelivery $delivery): bool
    {
        if ($delivery->resend_idempotency_started_at === null) {
            $delivery->newQuery()
                ->whereKey($delivery->getKey())
                ->whereNull('resend_idempotency_started_at')
                ->update(['resend_idempotency_started_at' => now()]);
            $delivery->refresh();
        }

        $windowHours = (int) config('mail.resend_idempotency_window_hours');
        $windowExpiresAt = $delivery->resend_idempotency_started_at->copy()->addHours($windowHours);

        if (now()->lt($windowExpiresAt)) {
            return true;
        }

        if ($delivery instanceof OrganizationInvitation) {
            $delivery->update([
                'invitation_email_failed_at' => $delivery->invitation_email_failed_at ?? now(),
                'invitation_email_manual_review_at' => $delivery->invitation_email_manual_review_at ?? now(),
            ]);
        } else {
            $delivery->update([
                'failed_at' => $delivery->failed_at ?? now(),
                'manual_review_at' => $delivery->manual_review_at ?? now(),
            ]);
        }

        return false;
    }
}
