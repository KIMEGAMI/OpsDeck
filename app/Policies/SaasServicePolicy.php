<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\User;

class SaasServicePolicy
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
    public function view(User $user, SaasService $saasService): bool
    {
        return $this->membershipFor($user, $saasService) !== null;
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
    public function update(User $user, SaasService $saasService): bool
    {
        return $this->membershipFor($user, $saasService)?->role->canManageOperationalData() ?? false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SaasService $saasService): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SaasService $saasService): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SaasService $saasService): bool
    {
        return false;
    }

    private function membershipFor(User $user, SaasService $saasService): ?OrganizationMembership
    {
        return $user->organizationMemberships()
            ->where('organization_id', $saasService->organization_id)
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }
}
