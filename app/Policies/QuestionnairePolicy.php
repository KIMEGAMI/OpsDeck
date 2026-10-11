<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\User;

class QuestionnairePolicy
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
    public function view(User $user, Questionnaire $questionnaire): bool
    {
        return $this->membershipFor($user, $questionnaire) !== null;
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
    public function update(User $user, Questionnaire $questionnaire): bool
    {
        return $this->membershipFor($user, $questionnaire)?->role->canManageOperationalData() ?? false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Questionnaire $questionnaire): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Questionnaire $questionnaire): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Questionnaire $questionnaire): bool
    {
        return false;
    }

    private function membershipFor(User $user, Questionnaire $questionnaire): ?OrganizationMembership
    {
        return $user->organizationMemberships()->where('organization_id', $questionnaire->organization_id)->where('status', MembershipStatus::Active->value)->first();
    }
}
