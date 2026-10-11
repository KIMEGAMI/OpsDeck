<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationSettingsRequest;
use App\Models\AuditLog;
use App\Models\OnboardingStep;
use App\Services\OnboardingProgressService;
use App\Services\PlanUsageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class OrganizationSettingsController extends Controller
{
    public function edit(): View
    {
        $organization = request()->attributes->get('currentOrganization');
        $this->authorize('update', $organization);

        $limits = app(PlanUsageService::class)->forOrganization($organization);

        return view('settings.organization', compact('organization', 'limits'));
    }

    public function update(UpdateOrganizationSettingsRequest $request): RedirectResponse
    {
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('update', $organization);
        $settingsFields = [
            'timezone',
            'company_size',
            'ai_enabled',
            'cost_savings_renewal_rate_percent',
            'cost_savings_unused_asset_monthly_jpy',
        ];
        $before = $organization->only($settingsFields);
        $organization->update($request->validated());
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'entity_type' => $organization::class,
            'entity_id' => $organization->id,
            'action' => AuditLog::OrganizationSettingsUpdated,
            'before_json' => $before,
            'after_json' => $organization->only($settingsFields),
        ]);
        OnboardingStep::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'step_key' => OnboardingStep::ORGANIZATION_SETTINGS_STEP],
            ['completed_at' => now(), 'completed_by' => $request->user()->id],
        );
        app(OnboardingProgressService::class)->recordCompletionIfReady($organization);

        return to_route('settings.organization')->with('status', '組織設定を更新しました。');
    }
}
