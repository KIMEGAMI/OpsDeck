<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireQuestion;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionnaireQuestionControllerTest extends TestCase
{
    public function test_operator_can_create_a_question(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $questionnaire = $this->questionnaire($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-questions.store'), [
                'questionnaire_id' => $questionnaire->id,
                'question_text' => '追加する質問',
                'answer_type' => 'text',
                'sort_order' => 1,
            ])
            ->assertRedirect(route('questionnaires.show', $questionnaire));

        $this->assertDatabaseHas('questionnaire_questions', [
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '追加する質問',
        ]);
    }

    public function test_viewer_cannot_create_a_question(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $questionnaire = $this->questionnaire($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-questions.store'), [
                'questionnaire_id' => $questionnaire->id,
                'question_text' => '閲覧者が追加する質問',
                'answer_type' => 'text',
                'sort_order' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('questionnaire_questions', [
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '閲覧者が追加する質問',
        ]);
    }

    public function test_operator_can_update_a_question(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-questions.update', $question), [
                'question_text' => '更新された質問',
                'answer_type' => 'text',
                'sort_order' => 2,
            ])
            ->assertRedirect(route('questionnaires.show', $question->questionnaire_id));

        $this->assertSame('更新された質問', $question->refresh()->question_text);
    }

    public function test_viewer_cannot_update_a_question(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $question = $this->question($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-questions.update', $question), [
                'question_text' => '閲覧者の変更',
                'answer_type' => 'text',
                'sort_order' => 2,
            ])
            ->assertForbidden();

        $this->assertNotSame('閲覧者の変更', $question->refresh()->question_text);
    }

    public function test_operator_cannot_update_a_question_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Operator);
        $question = $this->question($otherOrganization, $otherUser);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-questions.update', $question), [
                'question_text' => '越境変更',
                'answer_type' => 'text',
                'sort_order' => 2,
            ])
            ->assertNotFound();

        $this->assertNotSame('越境変更', $question->refresh()->question_text);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'questionnaire-question-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Organization '.$suffix,
            'slug' => 'organization-'.$suffix,
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }

    private function questionnaire(Organization $organization, User $user): Questionnaire
    {
        return Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
        ]);
    }

    private function question(Organization $organization, User $user): QuestionnaireQuestion
    {
        return QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $this->questionnaire($organization, $user)->id,
            'question_text' => '元の質問',
            'answer_type' => 'text',
            'sort_order' => 1,
        ]);
    }
}
