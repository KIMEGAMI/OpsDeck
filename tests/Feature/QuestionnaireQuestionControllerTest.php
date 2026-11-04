<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionnaireQuestionControllerTest extends TestCase
{
    use RefreshDatabase;

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
        $question = QuestionnaireQuestion::query()->where('questionnaire_id', $questionnaire->id)->where('question_text', '追加する質問')->firstOrFail();
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $question->id)->where('action', AuditLog::QuestionnaireQuestionCreated)->sole();
        $this->assertSame(['changed_fields' => ['questionnaire_id', 'question_text', 'answer_type', 'sort_order']], $auditLog->after_json);
        $this->assertStringNotContainsString('追加する質問', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_question_section_and_reference_number_can_be_saved_and_viewed(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $questionnaire = $this->questionnaire($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-questions.store'), [
                'questionnaire_id' => $questionnaire->id,
                'section' => '基本情報',
                'question_number' => '1-2',
                'question_text' => '担当者の連絡先を教えてください',
                'answer_type' => 'text',
                'sort_order' => 1,
            ])
            ->assertRedirect(route('questionnaires.show', $questionnaire));

        $question = QuestionnaireQuestion::query()->where('questionnaire_id', $questionnaire->id)->sole();
        $this->assertSame('基本情報', $question->section);
        $this->assertSame('1-2', $question->question_number);

        $this->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertSee('基本情報・1-2：')
            ->assertSee('担当者の連絡先を教えてください');
    }

    public function test_invalid_question_order_is_shown_next_to_field_and_preserves_question_text(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $questionnaire = $this->questionnaire($organization, $user);

        $this->from(route('questionnaires.show', $questionnaire))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-questions.store'), [
                'questionnaire_id' => $questionnaire->id,
                'question_text' => '順番を直して追加する質問',
                'answer_type' => 'text',
                'sort_order' => 0,
            ])
            ->assertOk()
            ->assertSee('id="sort_order-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="sort_order-error"', false)
            ->assertSee('>順番を直して追加する質問</textarea>', false)
            ->assertSee('value="0"', false);

        $this->assertDatabaseMissing('questionnaire_questions', [
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '順番を直して追加する質問',
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
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::QuestionnaireQuestionCreated]);
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
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $question->id)->where('action', AuditLog::QuestionnaireQuestionUpdated)->sole();
        $this->assertSame(['changed_fields' => ['question_text', 'sort_order']], $auditLog->after_json);
        $this->assertStringNotContainsString('更新された質問', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
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
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'entity_id' => $question->id, 'action' => AuditLog::QuestionnaireQuestionUpdated]);
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
