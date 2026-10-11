<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Models\Asset;
use App\Models\OrganizationMembership;
use App\Models\User;

class AssetPolicy
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
    public function view(User $user, Asset $asset): bool
    {
        return $this->membershipFor($user, $asset) !== null;
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
    public function update(User $user, Asset $asset): bool
    {
        return $this->membershipFor($user, $asset)?->role->canManageOperationalData() ?? false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Asset $asset): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Asset $asset): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Asset $asset): bool
    {
        return false;
    }

    private function membershipFor(User $user, Asset $asset): ?OrganizationMembership
    {
        return $user->organizationMemberships()
            ->where('organization_id', $asset->organization_id)
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }
}
