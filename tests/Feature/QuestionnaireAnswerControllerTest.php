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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionnaireAnswerControllerTest extends TestCase
{
    use RefreshDatabase;

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
        $answer = QuestionnaireAnswer::query()->where('question_id', $question->id)->firstOrFail();
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $answer->id)->where('action', AuditLog::QuestionnaireAnswerCreated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['changed_fields' => ['question_id', 'answer_text', 'answer_status', 'source_type']], $auditLog->after_json);
        $this->assertStringNotContainsString('多要素認証を有効にしています。', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_operator_cannot_register_another_answer_while_a_draft_is_pending(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);
        $existingAnswer = $question->answers()->create([
            'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
            'source_type' => 'UNKNOWN',
        ]);

        $this->followingRedirects()
            ->from(route('questionnaires.show', $question->questionnaire_id))
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-answers.store'), [
                'question_id' => $question->id,
                'answer_text' => '重複した承認待ち回答',
                'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
                'source_type' => 'FACT',
            ])
            ->assertOk()
            ->assertSee('この質問には未承認の回答案があります。既存の回答を確認してください。');

        $this->assertSame(1, QuestionnaireAnswer::query()->where('question_id', $question->id)->whereNull('approved_at')->count());
        $this->assertDatabaseMissing('questionnaire_answers', [
            'question_id' => $question->id,
            'answer_text' => '重複した承認待ち回答',
        ]);
        $this->assertDatabaseHas('questionnaire_answers', ['id' => $existingAnswer->id]);
    }

    public function test_invalid_answer_source_error_is_scoped_to_the_answer_being_edited(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $targetAnswer = $this->answer($organization, $user);
        $targetQuestionnaire = $targetAnswer->question->questionnaire;
        $targetAnswer->update(['answer_text' => '保存済みの回答1', 'source_type' => 'FACT']);
        $otherQuestion = QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $targetQuestionnaire->id,
            'question_text' => '別の設問',
            'answer_type' => 'text',
            'sort_order' => 2,
        ]);
        $otherAnswer = QuestionnaireAnswer::query()->create([
            'question_id' => $otherQuestion->id,
            'answer_text' => '保存済みの回答2',
            'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
            'source_type' => 'UNKNOWN',
        ]);

        $this->from(route('questionnaires.show', $targetQuestionnaire))
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $targetAnswer), [
                'answer_id' => $targetAnswer->id,
                'answer_text' => '修正途中の回答',
                'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
                'source_type' => 'INVALID',
            ])
            ->assertRedirect(route('questionnaires.show', $targetQuestionnaire))
            ->assertSessionHasErrors('source_type');

        $this->get(route('questionnaires.show', $targetQuestionnaire))
            ->assertOk()
            ->assertSee('<textarea id="answer-text-'.$targetAnswer->id.'" name="answer_text" >修正途中の回答</textarea>', false)
            ->assertSee('<textarea id="answer-text-'.$otherAnswer->id.'" name="answer_text" >保存済みの回答2</textarea>', false)
            ->assertSee('<option value="FACT" selected>登録情報に基づく</option>', false)
            ->assertSee('<option value="UNKNOWN" selected>情報不足</option>', false);

        $this->withSession(['_old_input' => [
            'answer_id' => $targetAnswer->id,
            'answer_text' => '修正途中の回答',
            'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
            'source_type' => 'INVALID',
        ]]);
        $this->withViewErrors(['source_type' => ['情報の根拠を選択してください。']]);
        $this->view('questionnaires.show', [
            'questionnaire' => $targetQuestionnaire->load(['questions' => fn ($query) => $query->with('answers')->orderBy('sort_order')]),
        ])
            ->assertSee('id="answer-source-error-'.$targetAnswer->id.'" class="errors field-error"', false)
            ->assertSee('aria-describedby="answer-source-error-'.$targetAnswer->id.'"', false)
            ->assertDontSee('id="answer-source-error-'.$otherAnswer->id.'"', false);

        $this->assertSame('保存済みの回答1', $targetAnswer->refresh()->answer_text);
        $this->assertSame('FACT', $targetAnswer->source_type);
        $this->assertSame('保存済みの回答2', $otherAnswer->refresh()->answer_text);
    }

    public function test_operator_cannot_register_an_answer_with_the_approved_status(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.store'), [
            'question_id' => $question->id,
            'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
            'source_type' => 'FACT',
        ])->assertInvalid(['answer_status']);

        $this->assertDatabaseMissing('questionnaire_answers', ['question_id' => $question->id]);
    }

    public function test_operator_cannot_register_an_empty_answer(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-answers.store'), [
                'question_id' => $question->id,
                'answer_text' => '',
                'answer_choice' => '',
                'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
                'source_type' => 'UNKNOWN',
            ])
            ->assertInvalid(['answer_text', 'answer_choice']);

        $this->assertDatabaseMissing('questionnaire_answers', ['question_id' => $question->id]);
    }

    public function test_operator_cannot_register_an_answer_with_an_unknown_status(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.store'), [
            'question_id' => $question->id,
            'answer_status' => 'needs_special_review',
            'source_type' => 'FACT',
        ])->assertInvalid(['answer_status']);

        $this->assertDatabaseMissing('questionnaire_answers', ['question_id' => $question->id]);
    }

    public function test_answer_registration_returns_404_for_a_question_in_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Operator);
        $question = $this->question($otherOrganization, $otherUser);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.store'), [
            'question_id' => $question->id,
            'answer_text' => '越境確認用の回答',
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
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $answer->id)->where('action', AuditLog::QuestionnaireAnswerUpdated)->sole();
        $this->assertSame(['changed_fields' => ['answer_text', 'source_type']], $auditLog->after_json);
        $this->assertStringNotContainsString('確認済みの回答案', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_operator_cannot_save_an_empty_answer(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $answer = $this->answer($organization, $user);
        $answer->update(['answer_text' => '既存の回答案']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $answer), [
                'answer_text' => '',
                'answer_choice' => '',
                'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
                'source_type' => 'UNKNOWN',
            ])
            ->assertInvalid(['answer_text', 'answer_choice']);

        $this->assertSame('既存の回答案', $answer->refresh()->answer_text);
    }

    public function test_operator_cannot_set_the_approved_status_when_updating_an_answer(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $answer = $this->answer($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $answer), [
                'answer_text' => '未承認の回答',
                'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
                'source_type' => 'FACT',
            ])
            ->assertInvalid(['answer_status']);

        $answer->refresh();
        $this->assertSame('draft', $answer->answer_status);
        $this->assertNull($answer->approved_at);
    }

    public function test_operator_cannot_set_an_unknown_status_when_updating_an_answer(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $answer = $this->answer($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $answer), [
                'answer_text' => '未承認の回答',
                'answer_status' => 'needs_special_review',
                'source_type' => 'FACT',
            ])
            ->assertInvalid(['answer_status']);

        $answer->refresh();
        $this->assertSame(QuestionnaireAnswer::DRAFT_STATUS, $answer->answer_status);
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
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'entity_id' => $answer->id, 'action' => AuditLog::QuestionnaireAnswerUpdated]);
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

    public function test_answer_edit_rechecks_approval_after_locking_the_current_record(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $answer = $this->answer($organization, $user);
        $answer->update(['answer_text' => '承認前の内容']);
        $approvalCommitted = false;

        QuestionnaireAnswer::retrieved(static function (QuestionnaireAnswer $retrievedAnswer) use ($answer, $user, &$approvalCommitted): void {
            if ($approvalCommitted || $retrievedAnswer->isNot($answer)) {
                return;
            }

            QuestionnaireAnswer::query()->whereKey($answer->id)->update([
                'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
            $approvalCommitted = true;
        });

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaire-answers.update', $answer), [
                'answer_text' => '承認後に書き換えようとした内容',
                'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
                'source_type' => 'FACT',
            ])
            ->assertForbidden();

        $this->assertTrue($approvalCommitted);
        $this->assertDatabaseHas('questionnaire_answers', [
            'id' => $answer->id,
            'answer_text' => '承認前の内容',
            'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
            'approved_by' => $user->id,
        ]);
    }

    public function test_admin_can_approve_an_answer(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $answer = $this->answer($organization, $user);
        $questionnaire = $answer->question->questionnaire;

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertSee('この回答を確認して承認')
            ->assertSee(route('questionnaire-answers.approve', $answer), false);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.approve', $answer))->assertRedirect();

        $answer->refresh();
        $this->assertSame(QuestionnaireAnswer::APPROVED_STATUS, $answer->answer_status);
        $this->assertSame($user->id, $answer->approved_by);
        $this->assertNotNull($answer->approved_at);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'entity_id' => $answer->id,
            'action' => AuditLog::QuestionnaireAnswerApproved,
        ]);
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);

        $this->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertSee('承認済み')
            ->assertDontSee('この回答を確認して承認');
    }

    public function test_operator_cannot_approve_an_answer(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $answer = $this->answer($organization, $user);
        $questionnaire = $answer->question->questionnaire;

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertDontSee('この回答を確認して承認');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaire-answers.approve', $answer))->assertForbidden();
    }

    public function test_admin_cannot_approve_an_answer_more_than_once(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $answer = $this->answer($organization, $user);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->post(route('questionnaire-answers.approve', $answer))
            ->assertRedirect();

        $approvedAnswer = $answer->refresh();
        $approvedAt = $approvedAnswer->approved_at;
        $auditLogCount = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('entity_id', $answer->id)
            ->where('action', AuditLog::QuestionnaireAnswerApproved)
            ->count();

        $this->post(route('questionnaire-answers.approve', $answer))
            ->assertForbidden();

        $this->assertSame($user->id, $answer->refresh()->approved_by);
        $this->assertEquals($approvedAt, $answer->approved_at);
        $this->assertSame(1, $auditLogCount);
        $this->assertSame($auditLogCount, AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('entity_id', $answer->id)
            ->where('action', AuditLog::QuestionnaireAnswerApproved)
            ->count());
    }

    public function test_operator_can_generate_unknown_questionnaire_draft_without_auto_approval(): void
    {
        config(['opsdeck.release_features.questionnaire_ai_draft' => true]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaires.ai-draft', $question->questionnaire_id), ['question_id' => $question->id])
            ->assertRedirect()
            ->assertSessionHas('status', '登録情報をもとに回答案を作成しました。根拠と内容を確認してから承認してください。');

        $this->assertDatabaseHas('questionnaire_answers', ['question_id' => $question->id, 'source_type' => 'UNKNOWN', 'answer_status' => 'draft', 'approved_by' => null]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('questionnaires.show', $question->questionnaire_id))
            ->post(route('questionnaire-answers.store'), [
                'question_id' => $question->id,
                'answer_text' => 'AI下書きを重複させた回答',
                'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
                'source_type' => 'FACT',
            ])
            ->assertRedirect(route('questionnaires.show', $question->questionnaire_id))
            ->assertSessionHasErrors('question_id');

        $this->assertSame(1, QuestionnaireAnswer::query()->where('question_id', $question->id)->count());
    }

    public function test_questionnaire_draft_does_not_treat_unlinked_evidence_as_a_fact(): void
    {
        config(['opsdeck.release_features.questionnaire_ai_draft' => true]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);
        SecurityControl::query()->create(['organization_id' => $organization->id, 'control_code' => 'MFA', 'title' => 'MFA', 'category' => 'access', 'description' => 'MFA control', 'status' => 'implemented']);
        Evidence::query()->create(['organization_id' => $organization->id, 'title' => 'MFA証跡', 'evidence_type' => 'screenshot', 'storage_disk' => 'local', 'storage_path' => 'metadata-only', 'mime_type' => 'application/x-opsdeck-metadata', 'file_size' => 0, 'uploaded_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaires.ai-draft', $question->questionnaire_id), ['question_id' => $question->id])->assertRedirect();

        $answer = QuestionnaireAnswer::query()->where('question_id', $question->id)->firstOrFail();
        $this->assertSame('INFERENCE', $answer->source_type);
        $this->assertStringContainsString('実際の運用状況と証跡を確認してください。', $answer->answer_text);
        $this->assertNull($answer->approved_by);
    }

    public function test_questionnaire_draft_cannot_overwrite_an_approved_answer(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.release_features.questionnaire_ai_draft' => true]);
        config(['app.debug' => false]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);
        $answer = $question->answers()->create([
            'answer_text' => '承認済みの回答',
            'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
            'source_type' => 'FACT',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $this->followingRedirects()
            ->from(route('questionnaires.show', $question->questionnaire_id))
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaires.ai-draft', $question->questionnaire_id), ['question_id' => $question->id])
            ->assertOk()
            ->assertSee('承認済み回答は回答案で上書きできません。');

        $this->assertDatabaseHas('questionnaire_answers', [
            'id' => $answer->id,
            'answer_text' => '承認済みの回答',
            'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
            'approved_by' => $user->id,
        ]);
    }

    public function test_questionnaire_draft_rechecks_approval_after_locking_the_current_answer(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.release_features.questionnaire_ai_draft' => true]);
        config(['app.debug' => false]);
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $question = $this->question($organization, $user);
        $answer = $question->answers()->create([
            'answer_text' => '承認前の回答',
            'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
            'source_type' => 'FACT',
            'approved_by' => null,
            'approved_at' => null,
        ]);
        $approvalCommitted = false;

        QuestionnaireAnswer::retrieved(static function (QuestionnaireAnswer $retrievedAnswer) use ($answer, $user, &$approvalCommitted): void {
            if ($approvalCommitted || $retrievedAnswer->isNot($answer)) {
                return;
            }

            QuestionnaireAnswer::query()->whereKey($answer->id)->update([
                'answer_status' => QuestionnaireAnswer::APPROVED_STATUS,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
            $approvalCommitted = true;
        });

        $this->followingRedirects()
            ->from(route('questionnaires.show', $question->questionnaire_id))
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaires.ai-draft', $question->questionnaire_id), ['question_id' => $question->id])
            ->assertOk()
            ->assertSee('承認済み回答は回答案で上書きできません。');

        $this->assertTrue($approvalCommitted);
        $this->assertDatabaseHas('questionnaire_answers', [
            'id' => $answer->id,
            'answer_text' => '承認前の回答',
            'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
            'approved_by' => null,
        ]);
    }

    public function test_operator_cannot_create_an_ai_draft_for_another_organizations_questionnaire(): void
    {
        config(['opsdeck.release_features.questionnaire_ai_draft' => true]);
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
        config(['opsdeck.release_features.questionnaire_ai_draft' => true]);
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

    public function test_questionnaire_ai_draft_is_not_available_when_the_release_flag_is_disabled(): void
    {
        config(['opsdeck.release_features.questionnaire_ai_draft' => false]);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $question = $this->question($organization, $user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaires.ai-draft', $question->questionnaire_id), ['question_id' => $question->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('questionnaire_answers', ['question_id' => $question->id]);
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
