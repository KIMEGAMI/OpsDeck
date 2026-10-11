<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class ImportBatchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->activeOrganizationMemberships()->exists();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ImportBatch $importBatch): bool
    {
        return $this->membershipFor($user, $importBatch) !== null;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $this->membershipForOrganization($user, $organization->id)?->role->canManageOperationalData() ?? false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ImportBatch $importBatch): bool
    {
        return $this->membershipFor($user, $importBatch)?->role->canManageOperationalData() ?? false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ImportBatch $importBatch): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ImportBatch $importBatch): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ImportBatch $importBatch): bool
    {
        return false;
    }

    private function membershipFor(User $user, ImportBatch $importBatch): ?OrganizationMembership
    {
        return $this->membershipForOrganization($user, $importBatch->organization_id);
    }

    private function membershipForOrganization(User $user, int $organizationId): ?OrganizationMembership
    {
        return $user->organizationMemberships()->where('organization_id', $organizationId)->where('status', MembershipStatus::Active->value)->first();
    }
}
