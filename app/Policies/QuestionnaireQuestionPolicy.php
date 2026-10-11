<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Models\OrganizationMembership;
use App\Models\QuestionnaireQuestion;
use App\Models\User;

class QuestionnaireQuestionPolicy
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
    public function view(User $user, QuestionnaireQuestion $questionnaireQuestion): bool
    {
        return $this->membershipFor($user, $questionnaireQuestion) !== null;
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
    public function update(User $user, QuestionnaireQuestion $questionnaireQuestion): bool
    {
        return $this->membershipFor($user, $questionnaireQuestion)?->role->canManageOperationalData() ?? false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, QuestionnaireQuestion $questionnaireQuestion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, QuestionnaireQuestion $questionnaireQuestion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, QuestionnaireQuestion $questionnaireQuestion): bool
    {
        return false;
    }

    private function membershipFor(User $user, QuestionnaireQuestion $questionnaireQuestion): ?OrganizationMembership
    {
        return $user->organizationMemberships()
            ->where('organization_id', $questionnaireQuestion->questionnaire()->value('organization_id'))
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }
}
