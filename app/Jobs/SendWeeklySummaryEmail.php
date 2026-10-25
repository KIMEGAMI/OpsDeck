<?php

namespace App\Jobs;

use App\Mail\ResendIdempotencyConflictException;
use App\Mail\WeeklySummaryMail;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\WeeklySummaryDelivery;
use App\Services\PlanFeatureService;
use App\Services\ResendIdempotencyWindow;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendWeeklySummaryEmail implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return config('mail.delivery_job_backoff_seconds');
    }

    public function uniqueId(): string
    {
        return hash_hmac('sha256', $this->organizationId.':'.$this->userId.':'.$this->weekStart, (string) config('app.key'));
    }

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public int $organizationId, public array $summary, public string $weekStart) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        $organization = Organization::query()->find($this->organizationId);
        if ($user === null || $organization === null || ! $user->hasVerifiedEmail()) {
            return;
        }
        $preference = UserNotificationPreference::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->first();
        if (($preference !== null && ! $preference->weekly_summary_enabled) || ! OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->exists()) {
            return;
        }
        $delivery = WeeklySummaryDelivery::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->whereDate('week_start', $this->weekStart)->first();
        if ($delivery === null) {
            try {
                $delivery = WeeklySummaryDelivery::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'week_start' => $this->weekStart]);
            } catch (QueryException $exception) {
                $delivery = WeeklySummaryDelivery::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->whereDate('week_start', $this->weekStart)->first();
                if ($delivery === null) {
                    throw $exception;
                }
            }
        }
        if ($delivery->sent_at !== null) {
            return;
        }
        $useResend = (bool) config('mail.mailers.resend_idempotent.enabled');
        if ($useResend && ! app(ResendIdempotencyWindow::class)->startOrRequireManualReview($delivery)) {
            return;
        }

        $summary = $this->summary;
        $summary['renewal_alerts_enabled'] = app(PlanFeatureService::class)->isAvailable($organization, 'renewal_alerts');
        $summary['security_alerts_enabled'] = app(PlanFeatureService::class)->isAvailable($organization, 'security_controls');
        if (! $summary['renewal_alerts_enabled']) {
            $summary['renewals'] = 0;
        }
        if (! $summary['security_alerts_enabled']) {
            $summary['security_open'] = 0;
            $summary['mfa_unknown'] = 0;
        }
        try {
            $idempotencyKey = $useResend ? 'weekly-summary-delivery/'.$delivery->getKey() : null;
            $mail = new WeeklySummaryMail($organization, $summary, $idempotencyKey);
            if ($useResend) {
                Mail::mailer('resend_idempotent')->to($user->email)->send($mail);
            } else {
                Mail::to($user->email)->send($mail);
            }
            $delivery->update(['sent_at' => now(), 'failed_at' => null, 'manual_review_at' => null]);
        } catch (ResendIdempotencyConflictException) {
            $delivery->update(['failed_at' => now(), 'manual_review_at' => now()]);
        } catch (Throwable $exception) {
            $delivery->update(['failed_at' => now()]);

            throw new RuntimeException('Weekly summary email delivery failed: '.$exception::class);
        }
    }
}
