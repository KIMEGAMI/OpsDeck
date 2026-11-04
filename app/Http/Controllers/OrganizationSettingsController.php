<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationSettingsRequest;
use App\Models\AuditLog;
use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Services\OnboardingProgressService;
use App\Services\OrganizationStorageQuotaService;
use App\Services\PlanFeatureService;
use App\Services\PlanUsageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrganizationSettingsController extends Controller
{
    public function edit(): View
    {
        $organization = request()->attributes->get('currentOrganization');
        $this->authorize('update', $organization);

        $limits = app(PlanUsageService::class)->forOrganization($organization);

        $aiEnabledAvailable = app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping');
        $storageQuota = app(OrganizationStorageQuotaService::class)->summary($organization);

        return view('settings.organization', compact('organization', 'limits', 'aiEnabledAvailable', 'storageQuota'));
    }

    public function update(UpdateOrganizationSettingsRequest $request): RedirectResponse
    {
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('update', $organization);
        $attributes = $request->validated();
        if (($attributes['ai_enabled'] ?? false) && ! app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping')) {
            return back()->withErrors(['ai_enabled' => '現在のプランではAI列マッピング候補を利用できません。']);
        }

        DB::transaction(function () use ($request, $organization, $attributes): void {
            $lockedOrganization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $lockedOrganization->fill($attributes);
            $changedFields = array_keys($lockedOrganization->getDirty());
            $lockedOrganization->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $lockedOrganization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => Organization::class,
                    'entity_id' => $lockedOrganization->id,
                    'action' => AuditLog::OrganizationSettingsUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
            }

            OnboardingStep::query()->updateOrCreate(
                ['organization_id' => $lockedOrganization->id, 'step_key' => OnboardingStep::ORGANIZATION_SETTINGS_STEP],
                ['completed_at' => now(), 'completed_by' => $request->user()->id],
            );
            app(OnboardingProgressService::class)->recordCompletionIfReady($lockedOrganization);
        });

        return to_route('settings.organization')->with('status', '組織設定を更新しました。');
    }
}
