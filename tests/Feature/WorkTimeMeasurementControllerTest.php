<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RealizedSaving;
use App\Models\User;
use App\Models\WorkTimeMeasurement;
use App\Services\WorkTimeMeasurementSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkTimeMeasurementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['opsdeck.release_features.work_time_tracking' => true]);
    }

    public function test_work_time_tracking_is_hidden_and_blocked_when_not_in_the_release_scope(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $measurement = WorkTimeMeasurement::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
        ]);
        config(['opsdeck.release_features.work_time_tracking' => false]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('作業時間の変化');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertDontSee('作業時間の変化（担当者が入力した参考値）');
        $featureGuide = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('feature-guide.index'))
            ->assertOk()
            ->assertDontSee('作業時間の変化')
            ->assertSee('保存した一覧と週次まとめを確認し、権限のある管理者がCSVを書き出す流れ')
            ->assertSee('現在利用できるCSV書き出しは無料です。導入効果レポートは現在提供していません。');
        $this->assertStringNotContainsString('導入効果レポート', file_get_contents(public_path('feature-guides/report-flow.svg')));
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.store'), $this->validInput())
            ->assertNotFound();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.void', $measurement), ['reason' => '公開範囲外'])
            ->assertNotFound();

        $this->assertDatabaseHas('work_time_measurements', ['id' => $measurement->id, 'voided_at' => null]);
    }

    public function test_operator_can_record_audited_work_time_separately_from_realized_savings(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $input = $this->validInput(['hourly_rate' => '3000.00', 'currency' => 'JPY']);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('作業時間の変化（担当者が入力した参考値）')
            ->assertSee('作業名')
            ->assertSee('改善前：1回にかかる時間（分）')
            ->assertSee('この作業は月に何回ありますか？')
            ->assertSee('どうやって時間を調べましたか？')
            ->assertSee('担当者の1時間あたりの人件費（任意）')
            ->assertSee('1時間あたり3,000円なら「3000」と入力します。')
            ->assertSee('以前は1回30分、今は1回10分で、月4回なら')
            ->assertSee('月80分の短縮として表示します')
            ->assertSee('同じ条件で時間を測り')
            ->assertSee('実際の経費削減額や給与額には加算しません。');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.store'), $input)
            ->assertRedirect(route('costs.dashboard'))
            ->assertSessionHas('status', '作業時間を記録しました。時間の短縮は参考値で、経費削減額には含めていません。');

        $measurement = $organization->workTimeMeasurements()->firstOrFail();
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
        $this->assertSame($input['process_name'], $measurement->process_name);
        $this->assertSame('3000.00', $measurement->hourly_rate);
        $this->assertSame(['monthly_minutes' => 80, 'monthly_hours' => 1.33, 'monthly_labor_equivalent_by_currency' => ['JPY' => 4000.0]], app(WorkTimeMeasurementSummary::class)->forOrganization($organization));
        $this->assertSame(0, $organization->realizedSavings()->count());

        $auditLog = AuditLog::query()->where('entity_type', WorkTimeMeasurement::class)->where('entity_id', $measurement->id)->firstOrFail();
        $this->assertSame(AuditLog::WorkTimeMeasurementRecorded, $auditLog->action);
        $this->assertSame(['changed_fields' => ['process_name', 'before_minutes', 'after_minutes', 'monthly_occurrences', 'hourly_rate', 'currency', 'measured_on', 'evidence_reference'], 'updated_existing' => false], $auditLog->after_json);
        $this->assertStringNotContainsString($input['process_name'], json_encode($auditLog->toArray()));
        $this->assertStringNotContainsString($input['evidence_reference'], json_encode($auditLog->toArray()));
    }

    public function test_work_time_increase_is_labeled_as_an_increase_in_the_reference_amount(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $input = $this->validInput([
            'before_minutes' => 20,
            'after_minutes' => 30,
            'monthly_occurrences' => 4,
            'hourly_rate' => '3000.00',
            'currency' => 'JPY',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.store'), $input)
            ->assertRedirect(route('costs.dashboard'));

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('0.67時間増加')
            ->assertSee('JPY -2,000.00（増加分）')
            ->assertSee('実際の経費削減ではありません。');

        $this->assertSame(-40, app(WorkTimeMeasurementSummary::class)->forOrganization($organization)['monthly_minutes']);
        $this->assertSame(0, $organization->realizedSavings()->count());
    }

    public function test_same_process_name_updates_the_current_measurement_instead_of_double_counting(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)->post(route('work-time-measurements.store'), $this->validInput())->assertRedirect(route('costs.dashboard'));
        $measurement = $organization->workTimeMeasurements()->firstOrFail();
        $this->actingAs($user)->withSession($session)->post(route('work-time-measurements.store'), $this->validInput([
            'process_name' => '　月末の請求書確認　',
            'before_minutes' => 40,
            'after_minutes' => 5,
            'monthly_occurrences' => 2,
        ]))->assertSessionHas('status', '同じ作業名の記録を更新しました。時間の短縮は参考値で、経費削減額には含めていません。');

        $this->assertSame(1, $organization->workTimeMeasurements()->whereNull('voided_at')->count());
        $this->assertSame('月末の請求書確認', $measurement->fresh()->process_name);
        $this->assertSame($measurement->process_key, $measurement->fresh()->active_process_key);
        $this->assertSame(70, app(WorkTimeMeasurementSummary::class)->forOrganization($organization)['monthly_minutes']);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLog::WorkTimeMeasurementRecorded, 'after_json->updated_existing' => true]);
    }

    public function test_voided_work_time_record_is_retained_and_excluded_from_totals(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $session = ['current_organization_id' => $organization->id];
        $this->actingAs($user)->withSession($session)->post(route('work-time-measurements.store'), $this->validInput())->assertRedirect(route('costs.dashboard'));
        $measurement = $organization->workTimeMeasurements()->firstOrFail();

        $this->from(route('costs.dashboard'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession($session)
            ->post(route('work-time-measurements.void', $measurement), [
                'costs_form' => 'work_time_void',
                'work_time_measurement_id' => $measurement->id,
            ])
            ->assertOk()
            ->assertSee('id="work-time-void-reason-'.$measurement->id.'-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="work-time-void-reason-'.$measurement->id.'-error"', false);
        $this->assertNull($measurement->fresh()->voided_at);

        $this->actingAs($user)->withSession($session)
            ->post(route('work-time-measurements.void', $measurement), ['reason' => '二重に記録したため'])
            ->assertRedirect(route('costs.dashboard'));
        $this->assertNotNull($measurement->fresh()->voided_at);
        $this->assertNull($measurement->fresh()->active_process_key);
        $this->assertSame(0, app(WorkTimeMeasurementSummary::class)->forOrganization($organization)['monthly_minutes']);

        $this->actingAs($user)->withSession($session)->post(route('work-time-measurements.store'), $this->validInput())->assertRedirect(route('costs.dashboard'));
        $this->assertSame(2, $organization->workTimeMeasurements()->count());
        $this->assertSame(1, $organization->workTimeMeasurements()->whereNull('voided_at')->count());
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $measurement->id, 'action' => AuditLog::WorkTimeMeasurementVoided]);
    }

    public function test_viewer_cannot_record_but_free_operator_can_record_work_time_measurements(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$viewer, $organization] = $this->member(MembershipRole::Viewer);
        $this->actingAs($viewer)->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.store'), $this->validInput())
            ->assertForbidden();
        $this->assertSame(0, $organization->workTimeMeasurements()->count());
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);

        [$operator, $freeOrganization] = $this->member(MembershipRole::Operator);
        $freeOrganization->update(['plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => 'inactive']);
        $this->actingAs($operator)->withSession(['current_organization_id' => $freeOrganization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('作業時間の変化（担当者が入力した参考値）');
        $this->actingAs($operator)->withSession(['current_organization_id' => $freeOrganization->id])
            ->post(route('work-time-measurements.store'), $this->validInput())
            ->assertRedirect(route('costs.dashboard'));
        $this->assertSame(1, $freeOrganization->workTimeMeasurements()->count());
    }

    public function test_member_cannot_see_or_void_another_organizations_work_time_record(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Operator);
        $measurement = WorkTimeMeasurement::factory()->create(['organization_id' => $otherOrganization->id, 'created_by' => $otherUser->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertDontSee($measurement->process_name);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.void', $measurement), ['reason' => '権限確認'])
            ->assertNotFound();
        $this->assertNull($measurement->fresh()->voided_at);
    }

    public function test_realized_savings_and_work_time_history_have_independent_pagination(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => 2]);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        foreach (range(1, 3) as $number) {
            RealizedSaving::factory()->create([
                'organization_id' => $organization->id,
                'created_by' => $user->id,
                'description' => '削減記録'.$number,
                'realized_on' => today()->subDays(4 - $number)->toDateString(),
            ]);
            WorkTimeMeasurement::factory()->create([
                'organization_id' => $organization->id,
                'created_by' => $user->id,
                'process_name' => '作業記録'.$number,
                'measured_on' => today()->subDays(4 - $number)->toDateString(),
            ]);
        }

        $session = ['current_organization_id' => $organization->id];
        $realizedSavingsSecondPage = $this->actingAs($user)
            ->withSession($session)
            ->get(route('costs.dashboard', ['realized_savings_page' => 2]));

        $realizedSavingsSecondPage->assertOk()
            ->assertSee('削減記録1')
            ->assertDontSee('削減記録3')
            ->assertSee('作業記録3')
            ->assertSee('作業記録2')
            ->assertDontSee('作業記録1');

        $workTimeSecondPage = $this->actingAs($user)
            ->withSession($session)
            ->get(route('costs.dashboard', ['work_time_page' => 2]));

        $workTimeSecondPage->assertOk()
            ->assertSee('作業記録1')
            ->assertDontSee('作業記録3')
            ->assertSee('削減記録3')
            ->assertSee('削減記録2')
            ->assertDontSee('削減記録1');
    }

    public function test_invalid_work_time_measurements_are_rejected_with_clear_messages(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.store'), $this->validInput([
                'before_minutes' => 0,
                'after_minutes' => -1,
                'process_name' => '　　',
                'monthly_occurrences' => 0,
                'hourly_rate' => '0',
                'currency' => 'jpy',
                'measured_on' => today()->addDay()->toDateString(),
                'evidence_reference' => '',
            ]))
            ->assertSessionHasErrors([
                'process_name' => '作業名を入力してください。空白だけでは登録できません。',
                'before_minutes' => '改善前の作業時間は1分以上を入力してください。',
                'after_minutes' => '改善後の作業時間は0分以上を入力してください。',
                'monthly_occurrences' => '月あたりの回数は1回以上を入力してください。',
                'hourly_rate' => '時間単価は0より大きい金額を入力してください。',
                'currency' => '通貨コードは大文字の3英字で入力してください。',
                'measured_on' => '計測日は今日以前の日付を指定してください。',
                'evidence_reference' => '計測方法や確認した資料を入力してください。',
            ]);
        $this->assertSame(0, $organization->workTimeMeasurements()->count());
    }

    public function test_invalid_hourly_rate_opens_advanced_options_and_highlights_only_work_time_fields(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $response = $this->from(route('costs.dashboard'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.store'), $this->validInput([
                'hourly_rate' => '0',
                'currency' => 'jpy',
                'costs_form' => 'work_time',
            ]))
            ->assertOk()
            ->assertSee('id="hourly_rate-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="hourly_rate-error"', false)
            ->assertSee('id="work-time-currency-error" class="errors field-error"', false)
            ->assertSee('aria-describedby="work-time-currency-error"', false)
            ->assertSee('id="work_time_evidence_reference" name="evidence_reference" value="直近2か月の平均を手計測"', false)
            ->assertSee('id="saving_evidence_reference" name="evidence_reference" value=""', false)
            ->assertDontSee('id="currency-error"', false);

        $this->assertMatchesRegularExpression('/<details\b[^>]*\bopen(?:\s|>)/', $response->getContent());

        $this->assertSame(0, $organization->workTimeMeasurements()->count());
    }

    public function test_user_supplied_work_time_labels_are_escaped_when_shown_in_history(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $input = $this->validInput([
            'process_name' => '<script>alert(1)</script>',
            'evidence_reference' => '<img src=x onerror=alert(2)>',
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('work-time-measurements.store'), $input)
            ->assertRedirect(route('costs.dashboard'));

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }

    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'work-time-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Work Time '.$suffix, 'slug' => 'work-time-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }

    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'process_name' => '月末の請求書確認',
            'before_minutes' => 30,
            'after_minutes' => 10,
            'monthly_occurrences' => 4,
            'hourly_rate' => null,
            'currency' => null,
            'measured_on' => today()->toDateString(),
            'evidence_reference' => '直近2か月の平均を手計測',
        ], $overrides);
    }
}
