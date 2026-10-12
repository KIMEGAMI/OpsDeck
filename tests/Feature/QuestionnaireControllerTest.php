<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionnaireControllerTest extends TestCase
{
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

    public function test_operator_can_open_ai_recovery_guide_from_questionnaire(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $questionnaire = Questionnaire::query()->create(['organization_id' => $organization->id, 'title' => 'AI recovery', 'status' => config('opsdeck.questionnaire_status.draft'), 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertSee(route('help.recovery', 'ai'))
            ->assertSee('AI回答下書きが使えないときの復旧ガイド');
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
