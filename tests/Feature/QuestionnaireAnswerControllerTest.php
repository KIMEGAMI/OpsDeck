<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Models\SecurityControl;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionnaireAnswerControllerTest extends TestCase
{
    public function test_operator_can_register_an_answer_for_a_question_in_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.store'), [
            'question_id' => $question->id,
            'answer_text' => '多要素認証を有効にしています。',
            'answer_status' => 'draft',
            'source_type' => 'FACT',
        ])->assertRedirect();

        $this->assertDatabaseHas('questionnaire_answers', ['question_id' => $question->id, 'source_type' => 'FACT', 'approved_by' => null]);
    }

    public function test_answer_registration_returns_404_for_a_question_in_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Operator);
        $question = $this->question($otherOrganization, $otherUser);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.store'), [
            'question_id' => $question->id,
            'answer_status' => 'draft',
            'source_type' => 'UNKNOWN',
        ])->assertNotFound();
    }

    public function test_viewer_cannot_register_an_answer(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $question = $this->question($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-answers.store'), [
                'question_id' => $question->id,
                'answer_text' => '閲覧者の回答',
                'answer_status' => 'draft',
                'source_type' => 'FACT',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('questionnaire_answers', ['question_id' => $question->id, 'answer_text' => '閲覧者の回答']);
    }

    public function test_operator_can_update_an_unapproved_answer(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $answer = $this->answer($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $answer), [
                'answer_text' => '確認済みの回答案',
                'answer_status' => 'draft',
                'source_type' => 'FACT',
            ])
            ->assertRedirect(route('questionnaires.show', $answer->question->questionnaire_id));

        $answer->refresh();
        $this->assertSame('確認済みの回答案', $answer->answer_text);
        $this->assertNull($answer->approved_at);
    }

    public function test_viewer_cannot_update_an_answer(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $answer = $this->answer($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $answer), [
                'answer_text' => '閲覧者の変更',
                'answer_status' => 'draft',
                'source_type' => 'FACT',
            ])
            ->assertForbidden();

        $this->assertNotSame('閲覧者の変更', $answer->refresh()->answer_text);
    }

    public function test_operator_cannot_update_an_answer_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Operator);
        $answer = $this->answer($otherOrganization, $otherUser);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $answer), [
                'answer_text' => '越境変更',
                'answer_status' => 'draft',
                'source_type' => 'FACT',
            ])
            ->assertNotFound();

        $this->assertNotSame('越境変更', $answer->refresh()->answer_text);
    }

    public function test_approved_answer_cannot_be_edited(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $answer = $this->answer($organization, $user);
        $answer->update(['approved_by' => $user->id, 'approved_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $answer), [
                'answer_text' => '承認済み内容の変更',
                'answer_status' => 'draft',
                'source_type' => 'FACT',
            ])
            ->assertForbidden();

        $this->assertNull($answer->refresh()->answer_text);
        $this->assertNotNull($answer->approved_at);
    }

    public function test_admin_can_approve_an_answer(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $answer = $this->answer($organization, $user);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.approve', $answer))->assertRedirect();

        $answer->refresh();
        $this->assertSame($user->id, $answer->approved_by);
        $this->assertNotNull($answer->approved_at);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'entity_id' => $answer->id,
            'action' => AuditLog::QuestionnaireAnswerApproved,
        ]);
    }

    public function test_operator_cannot_approve_an_answer(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $answer = $this->answer($organization, $user);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.approve', $answer))->assertForbidden();
    }

    public function test_operator_can_generate_unknown_ai_draft_without_auto_approval(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaires.ai-draft', $question->questionnaire_id), ['question_id' => $question->id])->assertRedirect();

        $this->assertDatabaseHas('questionnaire_answers', ['question_id' => $question->id, 'source_type' => 'UNKNOWN', 'answer_status' => 'draft', 'approved_by' => null]);
    }

    public function test_ai_draft_marks_answer_fact_only_with_control_and_evidence(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);
        SecurityControl::query()->create(['organization_id' => $organization->id, 'control_code' => 'MFA', 'title' => 'MFA', 'category' => 'access', 'description' => 'MFA control', 'status' => 'implemented']);
        Evidence::query()->create(['organization_id' => $organization->id, 'title' => 'MFA証跡', 'evidence_type' => 'screenshot', 'storage_disk' => 'local', 'storage_path' => 'metadata-only', 'mime_type' => 'application/x-opsdeck-metadata', 'file_size' => 0, 'uploaded_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaires.ai-draft', $question->questionnaire_id), ['question_id' => $question->id])->assertRedirect();

        $this->assertDatabaseHas('questionnaire_answers', ['question_id' => $question->id, 'source_type' => 'FACT', 'approved_by' => null]);
    }

    public function test_operator_cannot_create_an_ai_draft_for_another_organizations_questionnaire(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Operator);
        $question = $this->question($otherOrganization, $otherUser);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(
            route('questionnaires.ai-draft', $question->questionnaire_id),
            ['question_id' => $question->id],
        )->assertNotFound();

        $this->assertDatabaseMissing('questionnaire_answers', ['question_id' => $question->id]);
    }

    public function test_operator_cannot_overwrite_another_organizations_ai_draft(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Operator);
        $answer = $this->answer($otherOrganization, $otherUser);
        $answer->update(['answer_text' => '別組織の既存回答']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(
            route('questionnaires.ai-draft', $answer->question->questionnaire_id),
            ['question_id' => $answer->question_id],
        )->assertNotFound();

        $this->assertDatabaseHas('questionnaire_answers', [
            'id' => $answer->id,
            'answer_text' => '別組織の既存回答',
            'source_type' => 'UNKNOWN',
        ]);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'questionnaire-answer-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }

    private function question(Organization $organization, User $creator): QuestionnaireQuestion
    {
        $questionnaire = Questionnaire::query()->create(['organization_id' => $organization->id, 'title' => '質問票', 'status' => config('opsdeck.questionnaire_status.draft'), 'created_by' => $creator->id]);

        return QuestionnaireQuestion::query()->create(['questionnaire_id' => $questionnaire->id, 'question_text' => 'MFAは有効ですか？', 'answer_type' => 'text', 'sort_order' => 1]);
    }

    private function answer(Organization $organization, User $creator): QuestionnaireAnswer
    {
        return QuestionnaireAnswer::query()->create(['question_id' => $this->question($organization, $creator)->id, 'answer_status' => 'draft', 'source_type' => 'UNKNOWN']);
    }
}
