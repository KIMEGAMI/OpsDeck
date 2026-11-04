<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\SendWeeklySummaryEmail;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OpsDeckBPlusAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_company_can_import_assign_manage_renew_and_offboard_it_resources(): void
    {
        Notification::fake();
        Storage::fake(config('opsdeck.import.disk'));
        Storage::fake(config('opsdeck.evidence.disk'));

        $this->post('/register', [
            'organization_name' => 'B+受入テスト会社',
            'name' => 'B+受入テスト管理者',
            'email' => 'bplus-owner@example.test',
            'password' => 'A-secure-password-2026',
            'password_confirmation' => 'A-secure-password-2026',
        ])->assertRedirectToRoute('onboarding.index');

        $owner = User::query()->where('email', 'bplus-owner@example.test')->firstOrFail();
        $organization = Organization::query()->where('name', 'B+受入テスト会社')->firstOrFail();
        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
            'id' => $owner->getKey(),
            'hash' => sha1($owner->getEmailForVerification()),
        ]);
        $this->get($verificationUrl)->assertRedirectToRoute('dashboard');

        // Premium entitlements are a fixture here; Stripe checkout and webhook behavior have separate tests.
        $organization->update([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);
        $session = ['current_organization_id' => $organization->id];

        $this->withSession($session)->put(route('settings.organization.update'), [
            'timezone' => 'Asia/Tokyo',
            'company_size' => 10,
        ])->assertRedirect(route('settings.organization'));
        $this->assertSame('Asia/Tokyo', $organization->refresh()->timezone);
        $this->assertSame(10, $organization->company_size);

        $this->withSession($session)->post(route('departments.store'), [
            'name' => '情報システム部',
            'is_active' => true,
        ])->assertRedirect();
        $department = Department::query()
            ->where('organization_id', $organization->id)
            ->where('name', '情報システム部')
            ->sole();

        $joinedAt = today()->subDays(90)->toDateString();

        $this->withSession($session)->post(route('employees.store'), [
            'name' => '受入テスト社員',
            'email' => 'bplus-employee@example.test',
            'department_id' => $department->id,
            'employment_status' => config('opsdeck.employee_status.active'),
            'joined_at' => $joinedAt,
        ])->assertRedirect();
        $employee = Employee::query()
            ->where('organization_id', $organization->id)
            ->where('email', 'bplus-employee@example.test')
            ->firstOrFail();
        $this->assertSame($department->id, $employee->department_id);

        $uploadResponse = $this->withSession($session)->post(route('imports.store'), [
            'target_type' => 'assets',
            'source_name' => '端末台帳',
            'file' => UploadedFile::fake()->createWithContent(
                'assets.csv',
                "資産番号,種類,状態\nBPLUS-PC-001,pc,in_stock\n",
            ),
        ]);
        $batch = ImportBatch::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
        $uploadResponse->assertRedirect(route('imports.show', [$batch, 'target_type' => 'assets']));
        $this->assertSame('preview', $batch->refresh()->status);
        $this->assertDatabaseMissing('assets', [
            'organization_id' => $organization->id,
            'asset_number' => 'BPLUS-PC-001',
        ]);

        $this->withSession($session)->post(route('imports.mapping.store', $batch), [
            'target_type' => 'assets',
            'column_map' => ['資産番号' => 'asset_number', '種類' => 'asset_type', '状態' => 'status'],
        ])->assertRedirect(route('imports.show', $batch));
        $this->assertSame('ready', $batch->refresh()->status);
        $this->withSession($session)->get(route('imports.show', [$batch, 'target_type' => 'assets']))
            ->assertOk()
            ->assertSee('まだ会社の情報は変わりません')
            ->assertSee('BPLUS-PC-001');
        $this->withSession($session)->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $asset = Asset::query()
            ->where('organization_id', $organization->id)
            ->where('asset_number', 'BPLUS-PC-001')
            ->firstOrFail();
        $this->assertSame('completed', $batch->refresh()->status);
        $this->withSession($session)->post(route('asset-assignments.store'), [
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'assigned_at' => today()->toDateString(),
        ])->assertRedirectToRoute('assets.show', $asset);
        $this->assertDatabaseHas('asset_assignments', [
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'returned_at' => null,
        ]);

        $this->withSession($session)->post(route('saas-services.store'), [
            'name' => 'B+受入テストSaaS',
            'status' => config('opsdeck.saas_service_active_status'),
        ])->assertRedirect();
        $service = SaasService::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'B+受入テストSaaS')
            ->firstOrFail();
        $this->withSession($session)->post(route('saas-assignments.store'), [
            'saas_service_id' => $service->id,
            'employee_id' => $employee->id,
            'account_identifier' => 'bplus-employee@example.test',
            'license_name' => 'Business',
            'status' => 'active',
            'mfa_status' => config('opsdeck.saas_assignment_mfa_status.unknown'),
            'assigned_at' => today()->toDateString(),
        ])->assertRedirectToRoute('saas-services.show', $service);
        $saasAssignment = SaasAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('saas_service_id', $service->id)
            ->sole();

        $renewalDate = today()->addDays(15)->toDateString();
        $cancellationDeadline = today()->addDays(5)->toDateString();
        $this->withSession($session)->post(route('contracts.store'), [
            'saas_service_id' => $service->id,
            'vendor_name' => 'B+受入テスト提供元',
            'contract_name' => 'B+受入テスト年間契約',
            'contract_type' => 'subscription',
            'billing_cycle' => 'annual',
            'amount' => '9800.00',
            'currency' => 'JPY',
            'auto_renew' => true,
            'cancellation_deadline' => $cancellationDeadline,
            'renewal_date' => $renewalDate,
            'license_quantity' => 10,
            'owner_employee_id' => $employee->id,
            'status' => config('opsdeck.contract_status.active'),
        ])->assertRedirect();
        $contract = Contract::query()
            ->where('organization_id', $organization->id)
            ->where('contract_name', 'B+受入テスト年間契約')
            ->firstOrFail();
        $this->assertSame($renewalDate, $contract->renewal_date?->toDateString());

        $this->withSession($session)->post(route('evidence.store'), [
            'title' => 'B+受入テスト請求書',
            'evidence_type' => 'invoice',
            'file' => UploadedFile::fake()->create('bplus-invoice.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $evidence = Evidence::query()
            ->where('organization_id', $organization->id)
            ->where('title', 'B+受入テスト請求書')
            ->firstOrFail();
        Storage::disk($evidence->storage_disk)->assertExists($evidence->storage_path);

        $this->withSession($session)->post(route('realized-savings.store'), [
            'contract_id' => $contract->id,
            'evidence_id' => $evidence->id,
            'description' => '契約内容の見直しによる削減',
            'amount' => '120000.00',
            'currency' => 'JPY',
            'period' => 'annual',
            'realized_on' => today()->toDateString(),
            'evidence_reference' => 'BPLUS-INVOICE-001',
        ])->assertRedirect(route('costs.dashboard'));

        $this->assertDatabaseHas('realized_savings', [
            'organization_id' => $organization->id,
            'contract_id' => $contract->id,
            'evidence_id' => $evidence->id,
            'amount' => '120000.00',
            'currency' => 'JPY',
            'period' => 'annual',
        ]);
        $this->withSession($session)->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('契約内容の見直しによる削減')
            ->assertSee('B+受入テスト請求書')
            ->assertSee(route('evidence.show', $evidence), false);

        $this->withSession($session)->get(route('reminders.renewals'))
            ->assertOk()
            ->assertSee('B+受入テスト年間契約')
            ->assertSee($cancellationDeadline);

        Queue::fake();
        $this->artisan('opsdeck:weekly-summaries')->assertExitCode(0);
        Queue::assertPushed(
            SendWeeklySummaryEmail::class,
            fn (SendWeeklySummaryEmail $job): bool => $job->organizationId === $organization->id
                && $job->userId === $owner->id
                && $job->summary['renewals'] === 1,
        );

        $this->withSession($session)->post(route('security-controls.store'), [
            'control_code' => 'BPLUS-ACCESS-001',
            'title' => '退職時のアクセス権確認',
            'category' => 'アクセス制御',
            'description' => '退職時に担当者がアクセス権の移管を確認します。',
            'owner_employee_id' => $employee->id,
            'status' => config('opsdeck.security_control_status.unknown'),
        ])->assertRedirect();
        $securityControl = SecurityControl::query()
            ->where('organization_id', $organization->id)
            ->where('control_code', 'BPLUS-ACCESS-001')
            ->firstOrFail();

        $this->withSession($session)->post(route('questionnaires.store'), [
            'title' => 'B+受入テスト取引先質問票',
            'status' => config('opsdeck.questionnaire_status.draft'),
        ])->assertRedirect();
        $questionnaire = Questionnaire::query()
            ->where('organization_id', $organization->id)
            ->where('title', 'B+受入テスト取引先質問票')
            ->firstOrFail();
        $this->withSession($session)->post(route('questionnaire-questions.store'), [
            'questionnaire_id' => $questionnaire->id,
            'question_text' => '多要素認証を有効にしていますか？',
            'answer_type' => 'text',
            'sort_order' => 1,
        ])->assertRedirectToRoute('questionnaires.show', $questionnaire);
        $question = QuestionnaireQuestion::query()
            ->where('questionnaire_id', $questionnaire->id)
            ->firstOrFail();
        $this->withSession($session)->post(route('questionnaire-answers.store'), [
            'question_id' => $question->id,
            'answer_text' => '対象アカウントでは多要素認証を有効にしています。',
            'answer_status' => QuestionnaireAnswer::DRAFT_STATUS,
            'source_type' => 'FACT',
        ])->assertRedirectToRoute('questionnaires.show', $questionnaire);
        $answer = QuestionnaireAnswer::query()->where('question_id', $question->id)->firstOrFail();
        $this->assertNull($answer->approved_at);
        $this->withSession($session)->post(route('questionnaire-answers.approve', $answer))
            ->assertRedirectToRoute('questionnaires.show', $questionnaire);
        $this->assertSame($owner->id, $answer->fresh()->approved_by);
        $this->assertNotNull($answer->fresh()->approved_at);
        $this->withSession($session)->get(route('questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertSee('多要素認証を有効にしていますか？')
            ->assertSee('承認済み');

        $this->withSession($session)->put(route('employees.update', $employee), [
            'name' => $employee->name,
            'email' => $employee->email,
            'employment_status' => config('opsdeck.employee_status.retired'),
            'joined_at' => $joinedAt,
            'left_at' => today()->toDateString(),
        ])->assertRedirect();
        $this->assertSame(config('opsdeck.employee_status.retired'), $employee->fresh()->employment_status);

        $this->withSession($session)->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('退職時の確認')
            ->assertSee('BPLUS-PC-001')
            ->assertSee('B+受入テストSaaS')
            ->assertSee('B+受入テスト年間契約')
            ->assertSee('退職時のアクセス権確認')
            ->assertSee(route('assets.show', $asset), false)
            ->assertSee(route('saas-assignments.edit', $saasAssignment), false)
            ->assertSee(route('contracts.show', $contract), false)
            ->assertSee(route('security-controls.show', $securityControl), false)
            ->assertSee('外部サービスのアカウント停止や権限移管が完了したかは');

        $otherOrganization = Organization::query()->create([
            'name' => '別組織 B+受入テスト',
            'slug' => 'other-bplus-acceptance',
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.free_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        Employee::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => '他社には表示しない社員',
            'email' => 'other-bplus-employee@example.test',
            'employment_status' => config('opsdeck.employee_status.active'),
        ]);
        $this->withSession($session)->get(route('employees.show', Employee::query()->where('organization_id', $otherOrganization->id)->firstOrFail()))
            ->assertNotFound();

        $exportResponse = $this->withSession($session)->get(route('exports.download', 'employees'));
        $exportResponse->assertOk();
        $this->assertStringContainsString('受入テスト社員', $exportResponse->streamedContent());
        $this->assertStringNotContainsString('他社には表示しない社員', $exportResponse->streamedContent());

        $viewer = User::factory()->create(['email' => 'bplus-viewer@example.test']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $viewer->id,
            'role' => MembershipRole::Viewer,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $this->actingAs($viewer)->withSession($session)
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('退職時の確認')
            ->assertDontSee(route('saas-assignments.edit', $saasAssignment));
        $this->actingAs($viewer)->withSession($session)
            ->put(route('employees.update', $employee), [
                'name' => $employee->name,
                'employment_status' => config('opsdeck.employee_status.active'),
            ])
            ->assertForbidden();
        $this->assertSame(config('opsdeck.employee_status.retired'), $employee->fresh()->employment_status);
    }

    public function test_new_free_company_can_import_csv_without_premium_entitlements(): void
    {
        Storage::fake(config('opsdeck.import.disk'));

        $this->post('/register', [
            'organization_name' => 'Free取込受入会社',
            'name' => 'Free取込管理者',
            'email' => 'free-import-owner@example.test',
            'password' => 'A-secure-password-2026',
            'password_confirmation' => 'A-secure-password-2026',
        ])->assertRedirectToRoute('onboarding.index');

        $owner = User::query()->where('email', 'free-import-owner@example.test')->firstOrFail();
        $organization = Organization::query()->where('name', 'Free取込受入会社')->firstOrFail();
        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
            'id' => $owner->getKey(),
            'hash' => sha1($owner->getEmailForVerification()),
        ]);
        $this->get($verificationUrl)->assertRedirectToRoute('dashboard');
        $this->assertSame(config('opsdeck.free_plan_code'), $organization->refresh()->plan_code);

        $session = ['current_organization_id' => $organization->id];
        $this->withSession($session)
            ->get(route('imports.create', ['target_type' => 'employees']))
            ->assertOk()
            ->assertSee('まずファイルの内容を確認します。');

        $uploadResponse = $this->withSession($session)->post(route('imports.store'), [
            'target_type' => 'employees',
            'source_name' => 'Free社員名簿',
            'file' => UploadedFile::fake()->createWithContent(
                'free-employees.csv',
                "name,email\nFree取込社員,free-import-employee@example.test\n",
            ),
        ]);
        $batch = ImportBatch::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
        $uploadResponse->assertRedirect(route('imports.show', [$batch, 'target_type' => 'employees']));
        $this->assertDatabaseMissing('employees', [
            'organization_id' => $organization->id,
            'email' => 'free-import-employee@example.test',
        ]);

        $this->withSession($session)->post(route('imports.mapping.store', $batch), [
            'target_type' => 'employees',
            'column_map' => ['name' => 'name', 'email' => 'email'],
        ])->assertRedirect(route('imports.show', $batch));
        $this->withSession($session)->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
        $this->assertDatabaseHas('employees', [
            'organization_id' => $organization->id,
            'name' => 'Free取込社員',
            'email' => 'free-import-employee@example.test',
        ]);
    }
}
