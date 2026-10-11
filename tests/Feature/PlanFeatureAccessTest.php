<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireQuestion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanFeatureAccessTest extends TestCase
{
    public function test_free_plan_cannot_open_premium_pages_by_direct_url(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $premiumRoutes = [
            route('exports.index'),
            route('exports.download', 'employees'),
            route('security-controls.index'),
            route('evidence.index'),
            route('questionnaires.index'),
            route('impact-report.show'),
            route('impact-report.download'),
        ];

        foreach ($premiumRoutes as $url) {
            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get($url)->assertForbidden();
        }

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('reminders.renewals'), false)
            ->assertDontSee(route('security-controls.index'), false)
            ->assertDontSee(route('evidence.index'), false)
            ->assertDontSee(route('questionnaires.index'), false)
            ->assertDontSee(route('exports.index'), false)
            ->assertDontSee(route('impact-report.show'), false);
    }

    public function test_free_import_upgrade_path_respects_billing_owner_permission(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$owner, $organization] = $this->member();

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create'))
            ->assertOk()
            ->assertSee(route('billing.index'), false)
            ->assertSee('Free / Premiumの違いを確認');

        $operator = User::factory()->create(['email' => 'free-operator-'.Str::uuid().'@example.test']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $operator->id,
            'role' => MembershipRole::Operator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($operator)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('imports.create'))
            ->assertOk()
            ->assertSee('Premium契約の相談は組織Ownerにご依頼ください。')
            ->assertDontSee(route('billing.index'), false);
    }

    public function test_free_plan_cannot_create_premium_resources_by_direct_post(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.evidence.disk'));
        [$user, $organization] = $this->member();
        $questionnaire = Questionnaire::query()->create(['organization_id' => $organization->id, 'title' => 'Existing', 'status' => 'draft', 'created_by' => $user->id]);
        $question = QuestionnaireQuestion::query()->create(['questionnaire_id' => $questionnaire->id, 'question_text' => 'Existing question', 'answer_type' => 'text', 'sort_order' => 1]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('security-controls.store'), ['control_code' => 'MFA', 'title' => 'MFA', 'category' => 'access', 'description' => 'Control', 'status' => 'unknown'])
            ->assertForbidden();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('evidence.store'), ['title' => 'Evidence', 'evidence_type' => 'policy', 'file' => UploadedFile::fake()->create('policy.pdf', 20, 'application/pdf')])
            ->assertForbidden();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaires.store'), ['title' => 'New questionnaire', 'status' => 'draft'])
            ->assertForbidden();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-questions.store'), ['questionnaire_id' => $questionnaire->id, 'question_text' => 'New question', 'answer_type' => 'text', 'sort_order' => 2])
            ->assertForbidden();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('questionnaire-answers.store'), ['question_id' => $question->id, 'answer_text' => 'Answer', 'answer_status' => 'draft', 'source_type' => 'FACT'])
            ->assertForbidden();

        $this->assertDatabaseMissing('security_controls', ['organization_id' => $organization->id, 'control_code' => 'MFA']);
        $this->assertDatabaseMissing('evidence', ['organization_id' => $organization->id, 'title' => 'Evidence']);
        $this->assertDatabaseMissing('questionnaires', ['organization_id' => $organization->id, 'title' => 'New questionnaire']);
        $this->assertDatabaseMissing('questionnaire_questions', ['questionnaire_id' => $questionnaire->id, 'question_text' => 'New question']);
        $this->assertDatabaseMissing('questionnaire_answers', ['question_id' => $question->id]);
        $this->assertSame([], Storage::disk(config('opsdeck.evidence.disk'))->allFiles('evidence/'.$organization->id));
    }

    /** @return array{0: User, 1: Organization} */
    private function member(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'free-feature-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Free Features '.$suffix, 'slug' => 'free-features-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
