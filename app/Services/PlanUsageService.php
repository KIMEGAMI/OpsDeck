<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\SaasService;

class PlanUsageService
{
    public function __construct(private PlanAccessService $planAccessService) {}

    /**
     * @return list<array{key: string, label: string, current: int, limit: int, percentage: float, status: string}>
     */
    public function forOrganization(Organization $organization): array
    {
        $planCode = $this->planAccessService->effectivePlanCode($organization);
        $planLimits = config("opsdeck.plans.{$planCode}.limits", []);

        if (! is_array($planLimits)) {
            $planLimits = [];
        }
        $legacyLimits = $planCode === config('opsdeck.legacy_plan_code') ? config('opsdeck.limits', []) : [];

        return [
            $this->usage('employees', '社員', Employee::query()->where('organization_id', $organization->id)->count(), (int) ($planLimits['employees'] ?? $legacyLimits['employees'] ?? 0)),
            $this->usage('assets', '資産', Asset::query()->where('organization_id', $organization->id)->count(), (int) ($planLimits['assets'] ?? $legacyLimits['assets'] ?? 0)),
            $this->usage('saas_services', 'SaaS', SaasService::query()->where('organization_id', $organization->id)->count(), (int) ($planLimits['saas_services'] ?? $legacyLimits['saas_services'] ?? 0)),
            $this->usage('administrators', '管理者', $this->administratorCount($organization), (int) ($planLimits['administrators'] ?? $legacyLimits['administrators'] ?? 0)),
        ];
    }

    public function administratorCount(Organization $organization, ?int $excludingInvitationId = null): int
    {
        $membershipCount = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])
            ->count();
        $pendingInvitationCount = OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->when($excludingInvitationId !== null, fn ($query) => $query->where('id', '!=', $excludingInvitationId))
            ->count();

        return $membershipCount + $pendingInvitationCount;
    }

    /**
     * @return array{key: string, label: string, current: int, limit: int, percentage: float, status: string}
     */
    private function usage(string $key, string $label, int $current, int $limit): array
    {
        $percentage = $limit > 0 ? ($current / $limit) * 100 : 100;
        $warningPercentage = max(0, min(100, (int) config('opsdeck.limits.warning_percentage')));

        return [
            'key' => $key,
            'label' => $label,
            'current' => $current,
            'limit' => $limit,
            'percentage' => round($percentage, 1),
            'status' => $percentage >= 100 ? '上限超過' : ($percentage >= $warningPercentage ? '注意' : '通常'),
        ];
    }
}
