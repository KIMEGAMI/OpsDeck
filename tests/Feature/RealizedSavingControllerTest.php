<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RealizedSaving;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealizedSavingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_record_realized_savings_with_an_audit_entry(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $contract = $this->contract($organization);
        $input = [
            'contract_id' => $contract->id,
            'description' => 'Annual license reduction',
            'amount' => '120000',
            'currency' => 'JPY',
            'period' => 'annual',
            'realized_on' => today()->toDateString(),
            'evidence_reference' => 'Invoice-2026-10',
        ];

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $input)
            ->assertRedirect(route('costs.dashboard'))
            ->assertSessionHas('status', '削減実績を記録しました。金額は入力した通貨と期間ごとに集計されます。');

        $this->assertDatabaseHas('realized_savings', [
            'organization_id' => $organization->id,
            'contract_id' => $contract->id,
            'created_by' => $user->id,
            'description' => $input['description'],
            'amount' => '120000.00',
            'currency' => 'JPY',
            'period' => 'annual',
            'evidence_reference' => $input['evidence_reference'],
        ]);
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
        $saving = $organization->realizedSavings()->firstOrFail();
        $this->assertSame($input['realized_on'], $saving->realized_on->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'action' => AuditLog::RealizedSavingRecorded,
        ]);
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $saving->id)->where('action', AuditLog::RealizedSavingRecorded)->firstOrFail();
        $this->assertSame(['changed_fields' => ['contract_id', 'description', 'amount', 'currency', 'period', 'realized_on', 'evidence_reference']], $auditLog->after_json);
        $serializedAuditLog = json_encode($auditLog->toArray());
        $this->assertStringNotContainsString($input['description'], $serializedAuditLog);
        $this->assertStringNotContainsString($input['amount'], $serializedAuditLog);
        $this->assertStringNotContainsString($input['evidence_reference'], $serializedAuditLog);
    }

    public function test_viewer_cannot_record_realized_savings(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput())
            ->assertForbidden();

        $this->assertDatabaseMissing('realized_savings', ['organization_id' => $organization->id]);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => 'cost_saving.realized']);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_viewer_cannot_void_a_savings_record(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $saving = RealizedSaving::factory()->create(['organization_id' => $organization->id, 'created_by' => $user->id]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.void', $saving), ['reason' => 'Incorrect entry'])
            ->assertForbidden();

        $this->assertNull($saving->refresh()->voided_at);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $saving->id, 'action' => 'cost_saving.voided']);
    }

    public function test_contract_from_another_organization_is_rejected(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $otherContract = $this->contract($otherOrganization);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput(['contract_id' => $otherContract->id]))
            ->assertSessionHasErrors('contract_id');

        $this->assertDatabaseMissing('realized_savings', ['organization_id' => $organization->id]);
    }

    public function test_premium_operator_can_attach_same_organization_evidence_to_realized_savings(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code')]);
        $evidence = Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Approved invoice',
            'evidence_type' => 'invoice',
            'storage_disk' => config('opsdeck.evidence.disk'),
            'storage_path' => 'evidence/'.$organization->id.'/approved-invoice.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 128,
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('関連する保存済み資料（任意）')
            ->assertSee('何を見直しましたか？')
            ->assertSee('削減できた金額')
            ->assertSee('通貨（例：JPY＝日本円）')
            ->assertSee('年間120,000円を削減できた場合');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput(['evidence_id' => $evidence->id]))
            ->assertRedirect(route('costs.dashboard'));

        $saving = $organization->realizedSavings()->firstOrFail();
        $this->assertSame($evidence->id, $saving->evidence_id);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('Approved invoice')
            ->assertSee(route('evidence.show', $evidence), false);
    }

    public function test_realized_savings_reject_evidence_from_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [, $otherOrganization] = $this->member(MembershipRole::Operator);
        $otherEvidence = Evidence::query()->create([
            'organization_id' => $otherOrganization->id,
            'title' => 'Other tenant evidence',
            'evidence_type' => 'invoice',
            'storage_disk' => config('opsdeck.evidence.disk'),
            'storage_path' => 'evidence/'.$otherOrganization->id.'/private.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 128,
            'uploaded_by' => $otherOrganization->memberships()->value('user_id'),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput(['evidence_id' => $otherEvidence->id]))
            ->assertSessionHasErrors('evidence_id');

        $this->assertDatabaseMissing('realized_savings', ['organization_id' => $organization->id]);
    }

    public function test_free_plan_cannot_record_or_view_realized_savings_even_without_evidence(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $organization->update(['plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => 'inactive']);
        $evidence = Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Existing evidence',
            'evidence_type' => 'invoice',
            'storage_disk' => config('opsdeck.evidence.disk'),
            'storage_path' => 'evidence/'.$organization->id.'/existing.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 128,
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertDontSee('関連する保存済み資料（任意）');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput(['evidence_id' => $evidence->id]))
            ->assertForbidden();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput())
            ->assertForbidden();

        $existingSaving = RealizedSaving::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'description' => 'Free plan hidden saving history',
        ]);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.void', $existingSaving), ['reason' => 'Attempted Free plan change'])
            ->assertForbidden();

        $this->assertSame(1, $organization->realizedSavings()->count());
        $this->assertNull($existingSaving->fresh()->voided_at);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertDontSee('記録された削減実績')
            ->assertDontSee('Free plan hidden saving history');
    }

    public function test_cost_dashboard_and_void_route_do_not_expose_another_organizations_record(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        [$otherUser, $otherOrganization] = $this->member(MembershipRole::Operator);
        $otherSaving = RealizedSaving::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by' => $otherUser->id,
            'description' => 'Other organization private saving',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertDontSee('Other organization private saving');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.void', $otherSaving), ['reason' => 'Tamper attempt'])
            ->assertNotFound();

        $this->assertNull($otherSaving->refresh()->voided_at);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $otherSaving->id, 'action' => 'cost_saving.voided']);
    }

    public function test_invalid_saving_amount_currency_period_date_and_evidence_are_rejected(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput([
                'currency' => 'jpy',
                'period' => 'lifetime',
                'realized_on' => today()->addDay()->toDateString(),
                'amount' => '0',
                'evidence_reference' => '',
            ]))
            ->assertSessionHasErrors([
                'amount' => '削減額は0より大きい値を入力してください。',
                'currency' => '通貨コードは大文字の3英字で入力してください。',
                'period' => '削減額の期間を選択してください。',
                'realized_on' => '実現日は今日以前の日付を指定してください。',
                'evidence_reference' => '削減を確認した請求書・稟議などの参照情報を入力してください。',
            ]);

        $this->assertDatabaseMissing('realized_savings', ['organization_id' => $organization->id]);
    }

    public function test_invalid_realized_saving_highlights_only_the_realized_saving_form(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);

        $this->from(route('costs.dashboard'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput([
                'amount' => '0',
                'currency' => 'USD',
                'evidence_reference' => 'Savings-invoice-001',
                'costs_form' => 'realized_saving',
            ]))
            ->assertOk()
            ->assertSee('id="amount-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="amount-error"', false)
            ->assertSee('id="saving_amount" name="amount" type="number"', false)
            ->assertSee('value="0"', false)
            ->assertSee('id="saving_currency" name="currency" value="USD"', false)
            ->assertSee('id="saving_evidence_reference" name="evidence_reference" value="Savings-invoice-001"', false)
            ->assertDontSee('id="work_time_currency"', false)
            ->assertDontSee('id="work_time_evidence_reference"', false)
            ->assertDontSee('id="work-time-evidence-reference-error"', false);

        $this->assertDatabaseMissing('realized_savings', ['organization_id' => $organization->id]);
    }

    public function test_operator_can_void_a_savings_record_without_deleting_it(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput())
            ->assertRedirect(route('costs.dashboard'));
        $saving = $organization->realizedSavings()->firstOrFail();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.void', $saving), ['reason' => 'Entered from an incorrect invoice'])
            ->assertRedirect(route('costs.dashboard'));
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.void', $saving), ['reason' => 'Duplicate cancellation'])
            ->assertConflict();

        $saving->refresh();
        $this->assertNotNull($saving->voided_at);
        $this->assertSame($user->id, $saving->voided_by);
        $this->assertSame('Entered from an incorrect invoice', $saving->void_reason);
        $this->assertSame(1, $organization->realizedSavings()->count());
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('取消済み')
            ->assertDontSee('JPY（年額）：120,000.00');
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'entity_id' => $saving->id,
            'action' => 'cost_saving.voided',
        ]);
        $this->assertSame(1, $organization->realizedSavings()->whereNotNull('voided_at')->count());
    }

    public function test_void_requires_a_reason_and_does_not_change_the_record_without_one(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput())
            ->assertRedirect(route('costs.dashboard'));
        $saving = $organization->realizedSavings()->firstOrFail();

        $this->from(route('costs.dashboard'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.void', $saving), [
                'costs_form' => 'realized_saving_void',
                'saving_id' => $saving->id,
            ])
            ->assertOk()
            ->assertSee('id="saving-void-reason-'.$saving->id.'-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="saving-void-reason-'.$saving->id.'-error"', false)
            ->assertDontSee('id="work-time-void-reason-'.$saving->id.'-error"', false);

        $this->assertNull($saving->refresh()->voided_at);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $saving->id, 'action' => 'cost_saving.voided']);
    }

    public function test_cost_dashboard_escapes_saving_notes_and_keeps_currency_and_period_totals_separate(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Operator);
        $user->update(['name' => '<script>creator</script>']);
        $contract = $this->contract($organization);
        $contract->update(['vendor_name' => '<script>vendor</script>', 'contract_name' => '<script>contract</script>']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput([
                'contract_id' => $contract->id,
                'description' => '<script>alert(1)</script>',
                'amount' => '1000',
                'period' => 'monthly',
                'evidence_reference' => '<img src=x onerror=alert(2)>',
            ]))
            ->assertRedirect(route('costs.dashboard'));
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput(['amount' => '2000', 'period' => 'annual']))
            ->assertRedirect(route('costs.dashboard'));
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput(['amount' => '3000', 'currency' => 'USD', 'period' => 'one_time']))
            ->assertRedirect(route('costs.dashboard'));
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.store'), $this->validInput(['description' => 'Void-test saving', 'amount' => '4000', 'currency' => 'EUR', 'period' => 'one_time']))
            ->assertRedirect(route('costs.dashboard'));
        $voidedSaving = $organization->realizedSavings()->where('description', 'Void-test saving')->firstOrFail();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('realized-savings.void', $voidedSaving), ['reason' => '<script>alert(3)</script>'])
            ->assertRedirect(route('costs.dashboard'));

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('costs.dashboard'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('&lt;script&gt;creator&lt;/script&gt;', false)
            ->assertSee('&lt;script&gt;vendor&lt;/script&gt;', false)
            ->assertSee('&lt;script&gt;contract&lt;/script&gt;', false)
            ->assertSee('&lt;script&gt;alert(3)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(3)</script>', false)
            ->assertSee('JPY（月額）')
            ->assertSee('JPY（年額）')
            ->assertSee('USD（一時）')
            ->assertSee('JPY（月額）：1,000.00')
            ->assertSee('JPY（年額）：2,000.00')
            ->assertSee('USD（一時）：3,000.00');
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'realized-saving-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Savings '.$suffix, 'slug' => 'savings-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }

    private function contract(Organization $organization): Contract
    {
        return Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Savings vendor', 'contract_name' => 'Savings contract', 'contract_type' => 'service', 'billing_cycle' => 'annual', 'amount' => 120000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active']);
    }

    /** @return array<string, int|string> */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Verified contract savings',
            'amount' => '120000',
            'currency' => 'JPY',
            'period' => 'annual',
            'realized_on' => today()->toDateString(),
            'evidence_reference' => 'Invoice-2026-10',
        ], $overrides);
    }
}
