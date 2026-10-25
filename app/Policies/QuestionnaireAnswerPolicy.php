<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Models\OrganizationMembership;
use App\Models\QuestionnaireAnswer;
use App\Models\User;

class QuestionnaireAnswerPolicy
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
    public function view(User $user, QuestionnaireAnswer $questionnaireAnswer): bool
    {
        return $this->membershipFor($user, $questionnaireAnswer) !== null;
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
    public function update(User $user, QuestionnaireAnswer $questionnaireAnswer): bool
    {
        if ($questionnaireAnswer->approved_at !== null) {
            return false;
        }

        return $this->membershipFor($user, $questionnaireAnswer)?->role->canManageOperationalData() ?? false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, QuestionnaireAnswer $questionnaireAnswer): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, QuestionnaireAnswer $questionnaireAnswer): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, QuestionnaireAnswer $questionnaireAnswer): bool
    {
        return false;
    }

    public function approve(User $user, QuestionnaireAnswer $questionnaireAnswer): bool
    {
        if ($questionnaireAnswer->approved_at !== null
            || $questionnaireAnswer->answer_status !== QuestionnaireAnswer::DRAFT_STATUS) {
            return false;
        }

        return $this->membershipFor($user, $questionnaireAnswer)?->role->canApproveQuestionnaires() ?? false;
    }

    private function membershipFor(User $user, QuestionnaireAnswer $questionnaireAnswer): ?OrganizationMembership
    {
        $organizationId = $questionnaireAnswer->question()->value('questionnaire_id')
            ? $questionnaireAnswer->question->questionnaire()->value('organization_id')
            : null;

        return $user->organizationMemberships()
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }
}
