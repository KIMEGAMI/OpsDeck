<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\SaasService;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PlanLimitService
{
    public function __construct(private PlanAccessService $planAccessService, private PlanUsageService $planUsageService) {}

    public function assertCanAdd(Organization $organization, string $resourceKey, ?OrganizationInvitation $excludingInvitation = null): void
    {
        [$label, $current] = match ($resourceKey) {
            'employees' => ['社員', Employee::query()->where('organization_id', $organization->id)->count()],
            'assets' => ['資産', Asset::query()->where('organization_id', $organization->id)->count()],
            'saas_services' => ['SaaS', SaasService::query()->where('organization_id', $organization->id)->count()],
            'administrators' => ['管理者', $this->planUsageService->administratorCount($organization, $excludingInvitation?->id)],
            default => throw new InvalidArgumentException('Unsupported plan limit resource.'),
        };
        $planCode = $this->planAccessService->effectivePlanCode($organization);
        $planLimits = config("opsdeck.plans.{$planCode}.limits", []);
        $planLimits = is_array($planLimits) ? $planLimits : [];
        $legacyLimits = $planCode === config('opsdeck.legacy_plan_code') ? config('opsdeck.limits', []) : [];
        $limit = (int) ($planLimits[$resourceKey] ?? $legacyLimits[$resourceKey] ?? 0);

        if ($current >= $limit) {
            throw ValidationException::withMessages([
                'plan_limit' => "{$label}がこのプランの上限（{$limit}件）に達しています。不要なデータを整理するか、プランを変更してください。",
            ]);
        }
    }
}
