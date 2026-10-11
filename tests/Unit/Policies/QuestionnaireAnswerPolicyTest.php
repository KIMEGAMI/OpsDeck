<?php

namespace Tests\Unit\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Models\User;
use App\Policies\QuestionnaireAnswerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionnaireAnswerPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('rolePermissions')]
    public function test_unapproved_answer_update_permission_matches_the_membership_role(MembershipRole $role, bool $expected): void
    {
        [$user, $answer] = $this->answerFor($role);

        $this->assertSame($expected, app(QuestionnaireAnswerPolicy::class)->update($user, $answer));
    }

    #[DataProvider('membershipRoles')]
    public function test_approved_answer_cannot_be_updated_by_any_role(MembershipRole $role): void
    {
        [$user, $answer] = $this->answerFor($role, true);

        $this->assertFalse(app(QuestionnaireAnswerPolicy::class)->update($user, $answer));
    }

    public function test_user_without_membership_cannot_update_an_answer(): void
    {
        [$user] = $this->answerFor(MembershipRole::Owner);
        $otherOrganization = $this->organization();
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $otherOrganization->id,
            'title' => '別組織の質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
        ]);
        $question = QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '別組織の質問',
            'answer_type' => 'text',
            'sort_order' => 1,
        ]);
        $answer = QuestionnaireAnswer::query()->create([
            'question_id' => $question->id,
            'answer_status' => 'draft',
            'source_type' => 'UNKNOWN',
        ]);

        $this->assertFalse(app(QuestionnaireAnswerPolicy::class)->update($user, $answer));
    }

    public function test_suspended_member_cannot_update_an_answer(): void
    {
        [$user, $answer] = $this->answerFor(MembershipRole::Owner);
        OrganizationMembership::query()->where('user_id', $user->id)->update(['status' => MembershipStatus::Suspended->value]);

        $this->assertFalse(app(QuestionnaireAnswerPolicy::class)->update($user, $answer));
    }

    /** @return array<string, array{0: MembershipRole, 1: bool}> */
    public static function rolePermissions(): array
    {
        return [
            'owner' => [MembershipRole::Owner, true],
            'admin' => [MembershipRole::Admin, true],
            'operator' => [MembershipRole::Operator, true],
            'viewer' => [MembershipRole::Viewer, false],
        ];
    }

    /** @return array<string, array{0: MembershipRole}> */
    public static function membershipRoles(): array
    {
        return [
            'owner' => [MembershipRole::Owner],
            'admin' => [MembershipRole::Admin],
            'operator' => [MembershipRole::Operator],
            'viewer' => [MembershipRole::Viewer],
        ];
    }

    /** @return array{0: User, 1: QuestionnaireAnswer} */
    private function answerFor(MembershipRole $role, bool $approved = false): array
    {
        $organization = $this->organization();
        $user = User::factory()->create(['email' => 'answer-policy-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
        ]);
        $question = QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '質問',
            'answer_type' => 'text',
            'sort_order' => 1,
        ]);
        $answer = QuestionnaireAnswer::query()->create([
            'question_id' => $question->id,
            'answer_status' => 'draft',
            'source_type' => 'UNKNOWN',
            'approved_by' => $approved ? $user->id : null,
            'approved_at' => $approved ? now() : null,
        ]);

        return [$user, $answer];
    }

    private function organization(): Organization
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));

        return Organization::query()->create([
            'name' => 'Answer Policy '.$suffix,
            'slug' => 'answer-policy-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
    }
}
