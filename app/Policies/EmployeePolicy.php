<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Models\Employee;
use App\Models\OrganizationMembership;
use App\Models\User;

class EmployeePolicy
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
    public function view(User $user, Employee $employee): bool
    {
        return $this->membershipFor($user, $employee) !== null;
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
    public function update(User $user, Employee $employee): bool
    {
        return $this->membershipFor($user, $employee)?->role->canManageOperationalData() ?? false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Employee $employee): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Employee $employee): bool
    {
        return false;
    }

    private function membershipFor(User $user, Employee $employee): ?OrganizationMembership
    {
        return $user->organizationMemberships()
            ->where('organization_id', $employee->organization_id)
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }
}
