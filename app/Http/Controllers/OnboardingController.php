<?php

namespace App\Http\Controllers;

use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Services\OnboardingProgressService;
use App\Services\PlanFeatureService;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorizeManager($request);
        app(ProductEventRecorder::class)->recordOnce('onboarding_started', $organization);
        $progressService = app(OnboardingProgressService::class);
        $steps = $progressService->steps($organization);
        $progressService->recordCompletedSteps($organization, $steps);
        $completed = count(array_filter($steps, static fn (array $step): bool => $step['completed']));

        return view('onboarding.index', [
            'organization' => $organization,
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
            'percent' => count($steps) > 0 ? (int) round(($completed / count($steps)) * 100) : 0,
            'premiumFeaturesUnavailable' => ! app(PlanFeatureService::class)->isAvailable($organization, 'renewal_alerts'),
        ]);
    }

    public function markRenewalsChecked(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorizeManager($request);
        OnboardingStep::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'step_key' => OnboardingStep::RENEWAL_CHECKED_STEP],
            ['completed_at' => now(), 'completed_by' => $request->user()->id],
        );
        app(OnboardingProgressService::class)->recordCompletionIfReady($organization);

        return to_route('reminders.renewals')->with('status', '更新期限を確認済みとして記録しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }

    private function authorizeManager(Request $request): void
    {
        $role = $request->attributes->get('currentMembership')?->role?->value;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);
    }
}
