<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RealizedSaving;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_only_current_organization_data(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        Employee::query()->create(['organization_id' => $organization->id, 'name' => '同じ組織', 'email' => 'same-'.Str::random(8).'@example.test', 'employment_status' => 'active']);
        [, $otherOrganization] = $this->member(MembershipRole::Admin);
        Employee::query()->create(['organization_id' => $otherOrganization->id, 'name' => '別組織', 'email' => 'other-'.Str::random(8).'@example.test', 'employment_status' => 'active']);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('exports.download', 'employees'));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('同じ組織', $content);
        $this->assertStringNotContainsString('別組織', $content);
    }

    public function test_export_request_is_audited_without_exported_values(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        Employee::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Exported private name',
            'email' => 'exported-private-'.Str::uuid().'@example.test',
            'employment_status' => 'active',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('exports.download', 'employees'))
            ->assertOk();

        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('action', AuditLog::DataExportStarted)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(Organization::class, $auditLog->entity_type);
        $this->assertSame($organization->id, $auditLog->entity_id);
        $this->assertSame(['resource' => 'employees'], $auditLog->after_json);
        $this->assertStringNotContainsString('Exported private name', json_encode($auditLog->toArray()));
        $this->assertStringNotContainsString('exported-private-', json_encode($auditLog->toArray()));
    }

    public function test_viewer_cannot_export(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('exports.index'))->assertForbidden();
    }

    public function test_audit_log_export_contains_only_current_organization_logs(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'entity_type' => Employee::class,
            'action' => 'current_organization.audit_action',
        ]);
        [, $otherOrganization] = $this->member(MembershipRole::Admin);
        AuditLog::query()->create([
            'organization_id' => $otherOrganization->id,
            'entity_type' => Employee::class,
            'action' => 'other_organization.audit_action',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('exports.download', 'audit_logs'));

        $response->assertOk();
        $this->assertStringContainsString('current_organization.audit_action', $response->streamedContent());
        $this->assertStringContainsString(AuditLog::DataExportStarted, $response->streamedContent());
        $this->assertStringNotContainsString('other_organization.audit_action', $response->streamedContent());
    }

    public function test_csv_export_round_trips_quotes_and_backslashes_using_rfc_csv_escaping(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $expectedName = 'Tokyo, "HQ" \\ branch';
        Employee::query()->create(['organization_id' => $organization->id, 'name' => $expectedName, 'email' => 'csv-'.Str::uuid().'@example.test', 'employment_status' => 'active']);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('exports.download', 'employees'));
        $csvHandle = fopen('php://temp', 'w+b');
        fwrite($csvHandle, substr($response->streamedContent(), 3));
        rewind($csvHandle);
        $headers = fgetcsv($csvHandle, null, ',', '"', '');
        $row = fgetcsv($csvHandle, null, ',', '"', '');
        fclose($csvHandle);

        $this->assertSame('name', $headers[2]);
        $this->assertSame($expectedName, $row[2]);
    }

    public function test_csv_export_neutralizes_formula_like_names_without_changing_numeric_or_normal_values(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $expectedNames = [
            "\t=HYPERLINK(\"https://example.test\",\"open\")",
            "\t  =SUM(1,1)",
            "\t＠SUM(1,1)",
            "\t\n=SUM(1,1)",
            '-123.45',
            '通常の社員名',
        ];

        foreach ($expectedNames as $index => $name) {
            Employee::query()->create([
                'organization_id' => $organization->id,
                'name' => ltrim($name, "\t"),
                'email' => 'csv-formula-'.$index.'-'.Str::uuid().'@example.test',
                'employment_status' => 'active',
            ]);
        }

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('exports.download', 'employees'));
        $csvHandle = fopen('php://temp', 'w+b');
        fwrite($csvHandle, substr($response->streamedContent(), 3));
        rewind($csvHandle);
        fgetcsv($csvHandle, null, ',', '"', '');
        $actualNames = [];
        while (($row = fgetcsv($csvHandle, null, ',', '"', '')) !== false) {
            $actualNames[] = $row[2];
        }
        fclose($csvHandle);

        $this->assertSame($expectedNames, $actualNames);
    }

    public function test_realized_savings_export_is_tenant_scoped_formula_safe_and_includes_void_history(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Admin);
        $activeSaving = RealizedSaving::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'description' => '=HYPERLINK("https://example.test","proof")',
            'evidence_reference' => '-Invoice-001',
        ]);
        $voidedSaving = RealizedSaving::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'description' => 'Voided savings entry',
            'voided_by' => $user->id,
            'voided_at' => now(),
            'void_reason' => 'Incorrect amount',
        ]);
        [, $otherOrganization] = $this->member(MembershipRole::Admin);
        RealizedSaving::factory()->create([
            'organization_id' => $otherOrganization->id,
            'description' => 'Another tenant secret',
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('exports.download', 'realized_savings'));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvHandle = fopen('php://temp', 'w+b');
        fwrite($csvHandle, substr($response->streamedContent(), 3));
        rewind($csvHandle);
        $headers = fgetcsv($csvHandle, null, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($csvHandle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($csvHandle);

        $this->assertContains('void_reason', $headers);
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('Another tenant secret', $response->streamedContent());
        $idColumn = array_search('id', $headers, true);
        $descriptionColumn = array_search('description', $headers, true);
        $this->assertIsInt($idColumn);
        $this->assertIsInt($descriptionColumn);
        $activeRow = collect($rows)->first(fn (array $row): bool => (int) $row[$idColumn] === $activeSaving->id);
        $this->assertIsArray($activeRow);
        $this->assertSame("\t".$activeSaving->description, $activeRow[$descriptionColumn]);
        $this->assertContains($voidedSaving->void_reason, array_column($rows, array_search('void_reason', $headers, true)));
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'export-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Export '.$suffix, 'slug' => 'export-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
