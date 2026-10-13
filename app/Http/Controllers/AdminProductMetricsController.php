<?php

namespace App\Http\Controllers;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRead;
use App\Models\Asset;
use App\Models\CancellationFeedback;
use App\Models\Employee;
use App\Models\ImportReviewCandidate;
use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\ProductEvent;
use App\Models\ReferralCode;
use App\Models\ReferralConversion;
use App\Models\SaasService;
use App\Models\SupportRequest;
use App\Models\WeeklySummaryDelivery;
use App\Services\ImportFailureMetrics;
use App\Services\PlanAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminProductMetricsController extends Controller
{
    public function __invoke(PlanAccessService $planAccessService, ImportFailureMetrics $importFailureMetricsService): View
    {
        $events = ProductEvent::query()->select('event_key')->selectRaw('COUNT(*) as event_count')->groupBy('event_key')->orderBy('event_key')->get();
        $organizationEvents = ProductEvent::query()->whereNotNull('organization_id')->select('event_key')->selectRaw('COUNT(DISTINCT organization_id) as organization_count')->groupBy('event_key')->get()->keyBy('event_key');
        $activation = $this->activationMetrics();
        $onboarding = $this->onboardingMetrics();
        $monthlyActiveOrganizations = $this->monthlyActiveOrganizations();
        $retention = $this->retentionMetrics();
        $importFailureRate = $importFailureMetricsService->summarize();
        $aiApproval = $this->aiApprovalMetrics();
        $renewalChecks = OnboardingStep::query()->where('step_key', 'renewal_checked')->count();
        $cancellation = $this->cancellationMetrics();
        $referrals = $this->referralMetrics();
        $announcementReadRate = $this->announcementReadRate();
        $invitationMetrics = $this->invitationMetrics();
        $cancellationReasons = CancellationFeedback::query()->select('reason')->selectRaw('COUNT(*) as reason_count')->groupBy('reason')->orderByDesc('reason_count')->get();
        $deliveryFailures = $this->deliveryFailures();
        $deliveryRates = $this->deliveryRates();
        $supportCategories = SupportRequest::query()->select('category')->selectRaw('COUNT(*) as request_count')->groupBy('category')->orderByDesc('request_count')->get();
        $supportStatuses = SupportRequest::query()->select('status')->selectRaw('COUNT(*) as request_count')->groupBy('status')->orderBy('status')->get();
        $planLimitMetrics = $this->planLimitMetrics($planAccessService);

        return view('admin.product-metrics', compact('events', 'organizationEvents', 'activation', 'onboarding', 'monthlyActiveOrganizations', 'retention', 'importFailureRate', 'aiApproval', 'renewalChecks', 'cancellation', 'referrals', 'announcementReadRate', 'invitationMetrics', 'cancellationReasons', 'deliveryFailures', 'deliveryRates', 'supportCategories', 'supportStatuses', 'planLimitMetrics'));
    }

    public function export(PlanAccessService $planAccessService, ImportFailureMetrics $importFailureMetricsService): StreamedResponse
    {
        $events = ProductEvent::query()->select('event_key')->selectRaw('COUNT(*) as event_count')->groupBy('event_key')->orderBy('event_key')->get();
        $recentEvents = ProductEvent::query()->where('occurred_at', '>=', now()->subDays(30))->select('event_key')->selectRaw('COUNT(*) as event_count')->groupBy('event_key')->orderBy('event_key')->get();
        $activation = $this->activationMetrics();
        $onboarding = $this->onboardingMetrics();
        $retention = $this->retentionMetrics();
        $importFailureRate = $importFailureMetricsService->summarize();
        $aiApproval = $this->aiApprovalMetrics();
        $renewalChecks = OnboardingStep::query()->where('step_key', 'renewal_checked')->count();
        $cancellation = $this->cancellationMetrics();
        $referrals = $this->referralMetrics();
        $announcementReadRate = $this->announcementReadRate();
        $invitationMetrics = $this->invitationMetrics();
        $deliveryRates = $this->deliveryRates();
        $deliveryFailures = $this->deliveryFailures();
        $planLimitMetrics = $this->planLimitMetrics($planAccessService);
        $metrics = ['monthly_active_organizations' => $this->monthlyActiveOrganizations(), 'registered_organizations' => $activation['registered'], 'activation_eligible_organizations' => $activation['eligible'], 'activation_rate' => $activation['activation_rate'], 'previewed_within_7_days' => $activation['previewed'], 'preview_activation_rate' => $activation['preview_activation_rate'], 'preview_to_completion_rate' => $activation['preview_to_completion_rate'], 'average_time_to_value_hours' => $activation['average_time_to_value_hours'] ?? '', 'onboarding_completion_rate' => $onboarding['completion_rate'], 'retention_30_day_rate' => $retention['rate'], 'import_failure_rate' => $importFailureRate['rate'], 'ai_candidate_approval_rate' => $aiApproval['rate'], 'renewal_deadline_confirmation_count' => $renewalChecks, 'cancellation_rate' => $cancellation['rate'], 'referral_clicks' => $referrals['clicks'], 'referral_registrations' => $referrals['registrations'], 'referral_conversion_rate' => $referrals['rate'], 'announcement_read_rate' => $announcementReadRate['rate'], 'invitation_created' => $invitationMetrics['created'], 'invitation_joined' => $invitationMetrics['joined'], 'invitation_conversion_rate' => $invitationMetrics['rate'], 'organizations_near_plan_limit' => $planLimitMetrics['near_limit'], 'organizations_at_plan_limit' => $planLimitMetrics['at_limit'], 'plan_limit_warning_percentage' => config('opsdeck.limits.warning_percentage'), 'announcement_delivery_success_rate' => $deliveryRates['announcement'], 'weekly_summary_delivery_success_rate' => $deliveryRates['weekly_summary'], 'invitation_email_delivery_success_rate' => $deliveryRates['invitation_email'], 'announcement_delivery_failures' => $deliveryFailures['announcement'], 'announcement_delivery_manual_review' => $deliveryFailures['announcement_manual_review'], 'weekly_summary_delivery_failures' => $deliveryFailures['weekly_summary'], 'weekly_summary_delivery_manual_review' => $deliveryFailures['weekly_summary_manual_review'], 'invitation_email_failures' => $deliveryFailures['invitation_email'], 'invitation_email_manual_review' => $deliveryFailures['invitation_email_manual_review'], 'support_requests' => SupportRequest::query()->count()];

        return response()->streamDownload(static function () use ($events, $recentEvents, $metrics): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['metric_key', 'value'], ',', '"', '');
            foreach ($metrics as $key => $value) {
                fputcsv($handle, [$key, $value], ',', '"', '');
            }
            foreach ($events as $event) {
                fputcsv($handle, ['event:'.$event->event_key, $event->event_count], ',', '"', '');
            }
            foreach ($recentEvents as $event) {
                fputcsv($handle, ['event_30d:'.$event->event_key, $event->event_count], ',', '"', '');
            }
            fclose($handle);
        }, 'opsdeck-product-metrics-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{near_limit: int, at_limit: int} */
    private function planLimitMetrics(PlanAccessService $planAccessService): array
    {
        $warningPercentage = max(0, min(100, (int) config('opsdeck.limits.warning_percentage')));
        $nearLimit = 0;
        $atLimit = 0;
        $chunkSize = max(1, (int) config('opsdeck.pagination.per_page'));

        Organization::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $organizations) use ($planAccessService, $warningPercentage, &$nearLimit, &$atLimit): void {
                $organizationIds = $organizations->modelKeys();
                $counts = [
                    'employees' => Employee::query()->whereIn('organization_id', $organizationIds)->select('organization_id')->selectRaw('COUNT(*) as aggregate')->groupBy('organization_id')->pluck('aggregate', 'organization_id'),
                    'assets' => Asset::query()->whereIn('organization_id', $organizationIds)->select('organization_id')->selectRaw('COUNT(*) as aggregate')->groupBy('organization_id')->pluck('aggregate', 'organization_id'),
                    'saas_services' => SaasService::query()->whereIn('organization_id', $organizationIds)->select('organization_id')->selectRaw('COUNT(*) as aggregate')->groupBy('organization_id')->pluck('aggregate', 'organization_id'),
                    'administrators' => OrganizationMembership::query()->whereIn('organization_id', $organizationIds)->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])->select('organization_id')->selectRaw('COUNT(*) as aggregate')->groupBy('organization_id')->pluck('aggregate', 'organization_id'),
                ];
                $pendingAdministratorInvitations = OrganizationInvitation::query()
                    ->whereIn('organization_id', $organizationIds)
                    ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->select('organization_id')
                    ->selectRaw('COUNT(*) as aggregate')
                    ->groupBy('organization_id')
                    ->pluck('aggregate', 'organization_id');

                foreach ($organizations as $organization) {
                    $planCode = $planAccessService->effectivePlanCode($organization);
                    $planLimits = config("opsdeck.plans.{$planCode}.limits", []);

                    if (! is_array($planLimits)) {
                        $planLimits = [];
                    }
                    $legacyLimits = $planCode === config('opsdeck.legacy_plan_code') ? config('opsdeck.limits', []) : [];

                    $isNear = false;
                    $isAt = false;
                    foreach (array_keys($counts) as $key) {
                        $limit = (int) ($planLimits[$key] ?? $legacyLimits[$key] ?? 0);
                        $currentCount = (int) ($counts[$key][$organization->id] ?? 0);
                        if ($key === 'administrators') {
                            $currentCount += (int) ($pendingAdministratorInvitations[$organization->id] ?? 0);
                        }
                        $percentage = $limit > 0 ? ($currentCount / $limit) * 100 : 100;
                        $isNear = $isNear || $percentage >= $warningPercentage;
                        $isAt = $isAt || $percentage >= 100;
                    }
                    $nearLimit += $isNear ? 1 : 0;
                    $atLimit += $isAt ? 1 : 0;
                }
            });

        return ['near_limit' => $nearLimit, 'at_limit' => $atLimit];
    }

    /** @return array{registered:int, eligible:int, previewed:int, activated:int, activation_rate:float, preview_activation_rate:float, preview_to_completion_rate:float, average_time_to_value_hours:float|null} */
    private function activationMetrics(): array
    {
        $asOf = now();
        $organizations = DB::table('product_events')
            ->whereNotNull('organization_id')
            ->whereIn('event_key', ['registered', 'first_import_previewed', 'first_import_completed'])
            ->select('organization_id')
            ->selectRaw('MIN(CASE WHEN event_key = ? THEN occurred_at END) as registered_at', ['registered'])
            ->selectRaw('MIN(CASE WHEN event_key = ? THEN occurred_at END) as previewed_at', ['first_import_previewed'])
            ->selectRaw('MIN(CASE WHEN event_key = ? THEN occurred_at END) as completed_at', ['first_import_completed'])
            ->groupBy('organization_id')
            ->orderBy('organization_id');
        $registeredCount = 0;
        $eligibleRegistrationCount = 0;
        $activatedCount = 0;
        $previewedCount = 0;
        $previewCompletions = 0;
        $totalTimeToValueMinutes = 0.0;
        $eligibilityCutoff = $asOf->copy()->subDays(7);

        foreach ($organizations->cursor() as $organization) {
            if ($organization->registered_at === null) {
                continue;
            }

            $registeredAt = Carbon::parse($organization->registered_at);
            $registeredCount++;
            if ($registeredAt->greaterThan($eligibilityCutoff)) {
                continue;
            }

            $eligibleRegistrationCount++;
            $windowEndsAt = $registeredAt->copy()->addDays(7);
            $previewedAt = $organization->previewed_at !== null ? Carbon::parse($organization->previewed_at) : null;
            $completedAt = $organization->completed_at !== null ? Carbon::parse($organization->completed_at) : null;

            $previewReachedWithinWindow = $previewedAt !== null
                && $previewedAt->between($registeredAt, $windowEndsAt);
            $importCompletedWithinWindow = $completedAt !== null
                && $completedAt->between($registeredAt, $windowEndsAt);

            if ($previewReachedWithinWindow) {
                $previewedCount++;
            }

            if ($importCompletedWithinWindow) {
                $activatedCount++;
                $totalTimeToValueMinutes += $registeredAt->diffInMinutes($completedAt);

                if ($previewReachedWithinWindow && $completedAt->greaterThanOrEqualTo($previewedAt)) {
                    $previewCompletions++;
                }
            }
        }

        return [
            'registered' => $registeredCount,
            'eligible' => $eligibleRegistrationCount,
            'previewed' => $previewedCount,
            'activated' => $activatedCount,
            'activation_rate' => $eligibleRegistrationCount > 0 ? round(($activatedCount / $eligibleRegistrationCount) * 100, 2) : 0.0,
            'preview_activation_rate' => $eligibleRegistrationCount > 0 ? round(($previewedCount / $eligibleRegistrationCount) * 100, 2) : 0.0,
            'preview_to_completion_rate' => $previewedCount > 0 ? round(($previewCompletions / $previewedCount) * 100, 2) : 0.0,
            'average_time_to_value_hours' => $activatedCount > 0 ? round(($totalTimeToValueMinutes / $activatedCount) / 60, 2) : null,
        ];
    }

    /** @return array{completed:int, completion_rate:float} */
    private function onboardingMetrics(): array
    {
        $registered = ProductEvent::query()->where('event_key', 'registered')->whereNotNull('organization_id')->distinct('organization_id')->count('organization_id');
        $completed = ProductEvent::query()->where('event_key', 'onboarding_completed')->whereNotNull('organization_id')->distinct('organization_id')->count('organization_id');

        return ['completed' => $completed, 'completion_rate' => $registered > 0 ? round(($completed / $registered) * 100, 2) : 0.0];
    }

    /** @return array{reviewed:int, approved:int, rate:float} */
    private function aiApprovalMetrics(): array
    {
        $reviewed = ImportReviewCandidate::query()->whereNotNull('reviewed_at')->count();
        $approved = ImportReviewCandidate::query()->where('status', 'accepted')->whereNotNull('reviewed_at')->count();

        return ['reviewed' => $reviewed, 'approved' => $approved, 'rate' => $reviewed > 0 ? round(($approved / $reviewed) * 100, 2) : 0.0];
    }

    /** @return array{total:int, cancelled:int, rate:float, current_total:int, current_cancelled:int, current_rate:float} */
    private function cancellationMetrics(): array
    {
        $total = ProductEvent::query()->where('event_key', 'billing_active')->whereNotNull('organization_id')->distinct('organization_id')->count('organization_id');
        $cancelled = ProductEvent::query()->where('event_key', 'billing_cancelled')->whereNotNull('organization_id')->distinct('organization_id')->count('organization_id');
        $currentTotal = Organization::query()->whereIn('billing_status', ['active', 'trialing', 'past_due', 'cancelled'])->count();
        $currentCancelled = Organization::query()->where('billing_status', 'cancelled')->count();

        return ['total' => $total, 'cancelled' => $cancelled, 'rate' => $total > 0 ? round(($cancelled / $total) * 100, 2) : 0.0, 'current_total' => $currentTotal, 'current_cancelled' => $currentCancelled, 'current_rate' => $currentTotal > 0 ? round(($currentCancelled / $currentTotal) * 100, 2) : 0.0];
    }

    /** @return array{clicks:int, registrations:int, rate:float} */
    private function referralMetrics(): array
    {
        $clicks = (int) ReferralCode::query()->sum('click_count');
        $registrations = ReferralConversion::query()->count();

        return ['clicks' => $clicks, 'registrations' => $registrations, 'rate' => $clicks > 0 ? round(($registrations / $clicks) * 100, 2) : 0.0];
    }

    /** @return array{recipients:int, reads:int, rate:float} */
    private function announcementReadRate(): array
    {
        $recipients = (int) Announcement::query()->sum('recipients_count');
        $reads = AnnouncementRead::query()->count();

        return ['recipients' => $recipients, 'reads' => $reads, 'rate' => $recipients > 0 ? round(($reads / $recipients) * 100, 2) : 0.0];
    }

    /** @return array{created:int, joined:int, rate:float} */
    private function invitationMetrics(): array
    {
        $created = ProductEvent::query()->where('event_key', 'member_invited')->count();
        $joined = ProductEvent::query()->where('event_key', 'member_joined')->count();

        return ['created' => $created, 'joined' => $joined, 'rate' => $created > 0 ? round(($joined / $created) * 100, 2) : 0.0];
    }

    /** @return array{announcement:float, weekly_summary:float, invitation_email:float} */
    private function deliveryRates(): array
    {
        $announcementTotal = AnnouncementDelivery::query()->whereNull('suppressed_at')->count();
        $announcementSent = AnnouncementDelivery::query()->whereNotNull('sent_at')->count();
        $weeklyTotal = WeeklySummaryDelivery::query()->count();
        $weeklySent = WeeklySummaryDelivery::query()->whereNotNull('sent_at')->count();
        $invitationTotal = OrganizationInvitation::query()
            ->whereNotNull('invitation_email_sent_at')
            ->orWhereNotNull('invitation_email_failed_at')
            ->orWhereNotNull('invitation_email_manual_review_at')
            ->count();
        $invitationSent = OrganizationInvitation::query()->whereNotNull('invitation_email_sent_at')->count();

        return [
            'announcement' => $announcementTotal > 0 ? round(($announcementSent / $announcementTotal) * 100, 2) : 0.0,
            'weekly_summary' => $weeklyTotal > 0 ? round(($weeklySent / $weeklyTotal) * 100, 2) : 0.0,
            'invitation_email' => $invitationTotal > 0 ? round(($invitationSent / $invitationTotal) * 100, 2) : 0.0,
        ];
    }

    /** @return array{announcement:int, announcement_manual_review:int, weekly_summary:int, weekly_summary_manual_review:int, invitation_email:int, invitation_email_manual_review:int} */
    private function deliveryFailures(): array
    {
        return [
            'announcement' => AnnouncementDelivery::query()->whereNotNull('failed_at')->whereNull('sent_at')->whereNull('suppressed_at')->whereNull('manual_review_at')->count(),
            'announcement_manual_review' => AnnouncementDelivery::query()->whereNotNull('manual_review_at')->whereNull('sent_at')->whereNull('suppressed_at')->count(),
            'weekly_summary' => WeeklySummaryDelivery::query()->whereNotNull('failed_at')->whereNull('sent_at')->whereNull('manual_review_at')->count(),
            'weekly_summary_manual_review' => WeeklySummaryDelivery::query()->whereNotNull('manual_review_at')->whereNull('sent_at')->count(),
            'invitation_email' => OrganizationInvitation::query()->whereNotNull('invitation_email_failed_at')->whereNull('invitation_email_sent_at')->whereNull('invitation_email_manual_review_at')->count(),
            'invitation_email_manual_review' => OrganizationInvitation::query()->whereNotNull('invitation_email_manual_review_at')->whereNull('invitation_email_sent_at')->count(),
        ];
    }

    /** @return array{eligible:int, retained:int, rate:float} */
    private function retentionMetrics(): array
    {
        $asOf = now();
        $cutoff = $asOf->copy()->subDays(30);
        $eligibleOrganizations = ProductEvent::query()
            ->where('event_key', 'registered')
            ->whereNotNull('organization_id')
            ->where('occurred_at', '<=', $cutoff)
            ->select('organization_id')
            ->distinct();
        $eligible = (clone $eligibleOrganizations)->count('organization_id');
        $retentionCutoff = $asOf->copy()->setTimezone('UTC')->subDays(29)->toDateString();
        $retentionEnd = $asOf->copy()->setTimezone('UTC')->toDateString();
        $retained = DB::table('organization_activity_days')
            ->whereIn('organization_id', $eligibleOrganizations)
            ->where('activity_date', '>=', $retentionCutoff)
            ->where('activity_date', '<=', $retentionEnd)
            ->distinct('organization_id')
            ->count('organization_id');

        return ['eligible' => $eligible, 'retained' => $retained, 'rate' => $eligible > 0 ? round(($retained / $eligible) * 100, 2) : 0.0];
    }

    private function monthlyActiveOrganizations(): int
    {
        return DB::table('organization_activity_days')
            ->where('activity_date', '>=', now('UTC')->subDays(29)->toDateString())
            ->where('activity_date', '<=', now('UTC')->toDateString())
            ->distinct('organization_id')
            ->count('organization_id');
    }
}
