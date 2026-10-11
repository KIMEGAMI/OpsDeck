<?php

namespace Tests\Unit\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireQuestion;
use App\Models\User;
use App\Policies\QuestionnaireQuestionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionnaireQuestionPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('rolePermissions')]
    public function test_update_permission_matches_the_membership_role(MembershipRole $role, bool $expected): void
    {
        [$user, $question] = $this->questionFor($role);

        $this->assertSame($expected, app(QuestionnaireQuestionPolicy::class)->update($user, $question));
    }

    public function test_user_without_membership_cannot_update_a_question(): void
    {
        [$user] = $this->questionFor(MembershipRole::Owner);
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

        $this->assertFalse(app(QuestionnaireQuestionPolicy::class)->update($user, $question));
    }

    public function test_suspended_member_cannot_update_a_question(): void
    {
        [$user, $question] = $this->questionFor(MembershipRole::Owner);
        OrganizationMembership::query()->where('user_id', $user->id)->update(['status' => MembershipStatus::Suspended->value]);

        $this->assertFalse(app(QuestionnaireQuestionPolicy::class)->update($user, $question));
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

    /** @return array{0: User, 1: QuestionnaireQuestion} */
    private function questionFor(MembershipRole $role): array
    {
        $organization = $this->organization();
        $user = User::factory()->create(['email' => 'question-policy-'.Str::uuid().'@example.test']);
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

        return [$user, $question];
    }

    private function organization(): Organization
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));

        return Organization::query()->create([
            'name' => 'Question Policy '.$suffix,
            'slug' => 'question-policy-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
    }
}
