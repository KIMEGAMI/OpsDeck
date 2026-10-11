<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->membershipForOrganization($user, $organization->id)?->role->canViewAuditLogs() ?? false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->membershipFor($user, $auditLog)?->role->canViewAuditLogs() ?? false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    private function membershipFor(User $user, AuditLog $auditLog): ?OrganizationMembership
    {
        return $this->membershipForOrganization($user, $auditLog->organization_id);
    }

    private function membershipForOrganization(User $user, int $organizationId): ?OrganizationMembership
    {
        return $user->organizationMemberships()->where('organization_id', $organizationId)->where('status', MembershipStatus::Active->value)->first();
    }
}
