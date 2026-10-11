<?php

namespace Tests\Unit\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\User;
use App\Policies\QuestionnairePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionnairePolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('rolePermissions')]
    public function test_update_permission_matches_the_membership_role(MembershipRole $role, bool $expected): void
    {
        [$user, $questionnaire] = $this->questionnaireFor($role);

        $this->assertSame($expected, app(QuestionnairePolicy::class)->update($user, $questionnaire));
    }

    public function test_user_without_membership_cannot_update_a_questionnaire(): void
    {
        [$user] = $this->questionnaireFor(MembershipRole::Owner);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $this->organization()->id,
            'title' => '別組織の質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
        ]);

        $this->assertFalse(app(QuestionnairePolicy::class)->update($user, $questionnaire));
    }

    public function test_suspended_member_cannot_update_a_questionnaire(): void
    {
        [$user, $questionnaire] = $this->questionnaireFor(MembershipRole::Owner);
        OrganizationMembership::query()->where('user_id', $user->id)->update(['status' => MembershipStatus::Suspended->value]);

        $this->assertFalse(app(QuestionnairePolicy::class)->update($user, $questionnaire));
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

    /** @return array{0: User, 1: Questionnaire} */
    private function questionnaireFor(MembershipRole $role): array
    {
        $organization = $this->organization();
        $user = User::factory()->create(['email' => 'questionnaire-policy-'.Str::uuid().'@example.test']);
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

        return [$user, $questionnaire];
    }

    private function organization(): Organization
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));

        return Organization::query()->create([
            'name' => 'Questionnaire Policy '.$suffix,
            'slug' => 'questionnaire-policy-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
    }
}
