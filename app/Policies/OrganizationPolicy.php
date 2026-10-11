<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationPolicy
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
    public function view(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization) !== null;
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
    public function update(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization)?->role === MembershipRole::Owner;
    }

    public function manageOperationalData(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization)?->role->canManageOperationalData() ?? false;
    }

    public function export(User $user, Organization $organization): bool
    {
        return in_array($this->membershipFor($user, $organization)?->role, [MembershipRole::Owner, MembershipRole::Admin], true);
    }

    public function manageBilling(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization)?->role === MembershipRole::Owner;
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization)?->role === MembershipRole::Owner;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Organization $organization): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Organization $organization): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Organization $organization): bool
    {
        return false;
    }

    private function membershipFor(User $user, Organization $organization): ?OrganizationMembership
    {
        return $user->activeOrganizationMemberships()
            ->where('organization_id', $organization->id)
            ->first();
    }
}
