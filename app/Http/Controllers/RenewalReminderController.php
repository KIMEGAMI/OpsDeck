<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Services\ContractRenewalWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RenewalReminderController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): View
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('view', $organization);
        $today = CarbonImmutable::today($organization->timezone);
        $contracts = Contract::query()->where('organization_id', $organization->id)->whereIn('status', ['active', 'pending_cancel'])->get();
        $reminders = ['overdue' => collect(), 'within_30_days' => collect(), 'within_90_days' => collect()];
        $renewalWindow = app(ContractRenewalWindow::class);
        foreach ($contracts as $contract) {
            $date = $renewalWindow->reminderDateWithin($contract, $today, $today->addDays(ContractRenewalWindow::SAVINGS_WINDOW_DAYS))
                ?? $renewalWindow->reminderDateOnOrBefore($contract, $today->subDay());
            if ($date === null) {
                continue;
            }
            $contract->setAttribute('reminder_date', $date);
            $contract->setAttribute('reminder_date_label', $renewalWindow->reminderDateLabel($contract, $date));
            if ($date->isBefore($today)) {
                $reminders['overdue']->push($contract);
            } elseif (! $date->isAfter($today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS))) {
                $reminders['within_30_days']->push($contract);
            } else {
                $reminders['within_90_days']->push($contract);
            }
        }

        $canConfirmOnboarding = in_array($request->attributes->get('currentMembership')?->role?->value, ['owner', 'admin'], true);
        $renewalChecked = OnboardingStep::query()
            ->where('organization_id', $organization->id)
            ->where('step_key', OnboardingStep::RENEWAL_CHECKED_STEP)
            ->exists();

        return view('reminders.index', compact('organization', 'reminders', 'canConfirmOnboarding', 'renewalChecked'));
    }
}
