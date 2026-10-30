<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionnaireControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_questionnaire_index_counts_and_filters_pending_answers_within_the_current_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Viewer);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '確認が必要な質問票',
            'status' => 'answering',
            'created_by' => $user->id,
        ]);
        $question = QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '回答を確認してください',
            'answer_type' => 'text',
            'sort_order' => 1,
        ]);
        QuestionnaireAnswer::query()->create([
            'question_id' => $question->id,
            'answer_text' => '未承認の回答',
            'answer_status' => 'draft',
            'source_type' => 'UNKNOWN',
        ]);
        QuestionnaireAnswer::query()->create([
            'question_id' => $question->id,
            'answer_text' => '承認済みの回答',
            'answer_status' => 'approved',
            'source_type' => 'FACT',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
        $noPendingQuestionnaire = Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '確認不要の質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
        ]);
        $otherQuestionnaire = Questionnaire::query()->create([
            'organization_id' => $otherOrganization->id,
            'title' => '別組織の質問票',
            'status' => 'answering',
            'created_by' => $otherUser->id,
        ]);
        $otherQuestion = QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $otherQuestionnaire->id,
            'question_text' => '別組織の質問',
            'answer_type' => 'text',
            'sort_order' => 1,
        ]);
        QuestionnaireAnswer::query()->create([
            'question_id' => $otherQuestion->id,
            'answer_status' => 'draft',
            'source_type' => 'UNKNOWN',
        ]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('questionnaires.index', ['pending' => 1]))
            ->assertOk()
            ->assertSee($questionnaire->title)
            ->assertSee('未承認回答：1件')
            ->assertDontSee($noPendingQuestionnaire->title)
            ->assertDontSee($otherQuestionnaire->title);

        $this->get(route('questionnaires.index'))
            ->assertOk()
            ->assertSee('未承認回答：1件')
            ->assertSee('未承認回答：0件')
            ->assertDontSee($otherQuestionnaire->title);
    }

    public function test_questionnaire_screens_show_japanese_status_labels(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '取引先の確認票',
            'status' => 'answering',
            'created_by' => $user->id,
        ]);
        $question = QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $questionnaire->id,
            'question_text' => 'MFAを使っていますか？',
            'answer_type' => 'text',
            'sort_order' => 1,
        ]);
        QuestionnaireAnswer::query()->create([
            'question_id' => $question->id,
            'answer_text' => '登録情報を確認してください。',
            'answer_status' => 'draft',
            'source_type' => 'UNKNOWN',
        ]);
        QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '連絡担当者は誰ですか？',
            'answer_type' => 'text',
            'sort_order' => 2,
        ]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('questionnaires.index'))
            ->assertOk()
            ->assertSee('回答中');

        $this->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertSee('回答中')
            ->assertSee('自由記入')
            ->assertSee('情報不足')
            ->assertSee('下書き（未承認）')
            ->assertSee('回答を確認・修正する')
            ->assertSee('回答を登録する')
            ->assertSee('<select id="answer_type" name="answer_type"', false)
            ->assertSee('<option value="text" selected>自由記入</option>', false)
            ->assertSee('現在は文章で回答する形式です。')
            ->assertDontSee('placeholder="例：text"', false);

        $this->get(route('questionnaires.create'))
            ->assertOk()
            ->assertSee('下書き')
            ->assertSee('確認中')
            ->assertSee('保管済み');
    }

    public function test_invalid_questionnaire_status_falls_back_to_draft(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $title = '状態選択確認';

        $this->from(route('questionnaires.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaires.store'), [
                'title' => $title,
                'status' => 'not-a-configured-status',
            ])
            ->assertOk()
            ->assertSee('id="status-error" class="errors field-error"', false)
            ->assertSee('<option value="draft" selected>下書き</option>', false);

        $this->assertDatabaseMissing('questionnaires', [
            'organization_id' => $organization->id,
            'title' => $title,
        ]);
    }

    public function test_operator_can_create_a_questionnaire(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('questionnaires.store'), ['title' => '取引先質問票', 'status' => config('opsdeck.questionnaire_status.draft')])->assertRedirect();

        $this->assertDatabaseHas('questionnaires', ['organization_id' => $organization->id, 'title' => '取引先質問票']);
        $questionnaire = Questionnaire::query()->where('organization_id', $organization->id)->where('title', '取引先質問票')->firstOrFail();
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $questionnaire->id)->where('action', AuditLog::QuestionnaireCreated)->sole();
        $this->assertSame(['changed_fields' => ['title', 'status']], $auditLog->after_json);
        $this->assertStringNotContainsString('取引先質問票', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_questionnaire_request_date_and_requester_can_be_saved_and_viewed(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaires.store'), [
                'title' => '取引先質問票',
                'requester_name' => '仕入先A社',
                'requested_at' => '2026-10-10',
                'due_at' => '2026-10-31',
                'status' => config('opsdeck.questionnaire_status.draft'),
            ])
            ->assertRedirect();

        $questionnaire = Questionnaire::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame('仕入先A社', $questionnaire->requester_name);
        $this->assertSame('2026-10-10', $questionnaire->requested_at?->toDateString());

        $this->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertSee('仕入先A社')
            ->assertSee('2026-10-10');

        $this->get(route('questionnaires.edit', $questionnaire))
            ->assertOk()
            ->assertSee('name="requested_at" type="date"', false)
            ->assertSee('value="2026-10-10"', false);
    }

    public function test_invalid_questionnaire_due_date_is_shown_next_to_field_and_preserved(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->from(route('questionnaires.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaires.store'), [
                'title' => '期限確認の質問票',
                'status' => config('opsdeck.questionnaire_status.draft'),
                'due_at' => 'not-a-date',
            ])
            ->assertOk()
            ->assertSee('id="due_at-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="due_at-error"', false)
            ->assertSee('value="not-a-date"', false);

        $this->assertDatabaseMissing('questionnaires', [
            'organization_id' => $organization->id,
            'title' => '期限確認の質問票',
        ]);
    }

    public function test_viewer_cannot_create_a_questionnaire(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaires.store'), ['title' => '閲覧者の質問票', 'status' => config('opsdeck.questionnaire_status.draft')])
            ->assertForbidden();

        $this->assertDatabaseMissing('questionnaires', ['organization_id' => $organization->id, 'title' => '閲覧者の質問票']);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::QuestionnaireCreated]);
    }

    public function test_operator_can_update_a_questionnaire(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '元の質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaires.update', $questionnaire), [
                'title' => '更新した質問票',
                'status' => config('opsdeck.questionnaire_status.draft'),
            ])
            ->assertRedirect(route('questionnaires.show', $questionnaire));

        $this->assertSame('更新した質問票', $questionnaire->refresh()->title);
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $questionnaire->id)->where('action', AuditLog::QuestionnaireUpdated)->sole();
        $this->assertSame(['changed_fields' => ['title']], $auditLog->after_json);
        $this->assertStringNotContainsString('更新した質問票', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_viewer_cannot_update_a_questionnaire(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $organization->id,
            'title' => '閲覧専用質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaires.update', $questionnaire), [
                'title' => '不正な変更',
                'status' => config('opsdeck.questionnaire_status.draft'),
            ])
            ->assertForbidden();

        $this->assertSame('閲覧専用質問票', $questionnaire->refresh()->title);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'entity_id' => $questionnaire->id, 'action' => AuditLog::QuestionnaireUpdated]);
    }

    public function test_operator_cannot_update_a_questionnaire_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $questionnaire = Questionnaire::query()->create([
            'organization_id' => $otherOrganization->id,
            'title' => '別組織の質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('questionnaires.update', $questionnaire), [
                'title' => '不正な変更',
                'status' => config('opsdeck.questionnaire_status.draft'),
            ])
            ->assertNotFound();

        $this->assertSame('別組織の質問票', $questionnaire->refresh()->title);
    }

    public function test_questionnaire_pages_use_a_stable_order_when_due_dates_match(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => 1]);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $dueDate = now()->addWeek()->toDateString();
        $firstQuestionnaire = Questionnaire::query()->create(['organization_id' => $organization->id, 'title' => '同一期限の質問票', 'due_at' => $dueDate, 'status' => config('opsdeck.questionnaire_status.draft'), 'created_by' => $user->id]);
        $secondQuestionnaire = Questionnaire::query()->create(['organization_id' => $organization->id, 'title' => '同一期限の質問票', 'due_at' => $dueDate, 'status' => config('opsdeck.questionnaire_status.draft'), 'created_by' => $user->id]);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('questionnaires.index', ['page' => 1]))
            ->assertOk()
            ->assertSee('href="'.route('questionnaires.show', $firstQuestionnaire).'"', false)
            ->assertDontSee('href="'.route('questionnaires.show', $secondQuestionnaire).'"', false);

        $this->actingAs($user)->withSession($session)
            ->get(route('questionnaires.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('href="'.route('questionnaires.show', $secondQuestionnaire).'"', false)
            ->assertDontSee('href="'.route('questionnaires.show', $firstQuestionnaire).'"', false);
    }

    public function test_questionnaire_returns_404_for_another_organization(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $otherOrganization = Organization::query()->create($this->organizationAttributes());
        $questionnaire = Questionnaire::query()->create(['organization_id' => $otherOrganization->id, 'title' => 'Private', 'status' => config('opsdeck.questionnaire_status.draft'), 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('questionnaires.show', $questionnaire))->assertNotFound();
    }

    public function test_operator_sees_how_questionnaire_drafts_are_generated(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $questionnaire = Questionnaire::query()->create(['organization_id' => $organization->id, 'title' => 'AI recovery', 'status' => config('opsdeck.questionnaire_status.draft'), 'created_by' => $user->id]);
        QuestionnaireQuestion::query()->create([
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '管理策を確認する質問',
            'answer_type' => 'text',
            'sort_order' => 1,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertSee('回答案を入力し、登録情報と証跡を確認してから承認してください。')
            ->assertDontSee('登録情報から回答案を作成')
            ->assertDontSee('AI回答下書きが使えないときの復旧ガイド');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('feature-guide.index'))
            ->assertOk()
            ->assertSee('登録情報・証跡を確認しながら回答案を入力し');
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $user = User::factory()->create(['email' => 'questionnaire-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create($this->organizationAttributes());
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }

    /** @return array{name: string, slug: string, timezone: string, plan_code: string, billing_status: string} */
    private function organizationAttributes(): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));

        return ['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')];
    }
}
