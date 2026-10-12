<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceControllerTest extends TestCase
{
    public function test_operator_can_store_evidence_on_the_private_disk(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.evidence.disk'));
        [$user, $organization] = $this->member();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('evidence.store'), [
            'title' => 'MFA証跡', 'evidence_type' => 'policy', 'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $evidence = Evidence::query()->where('organization_id', $organization->id)->firstOrFail();
        Storage::disk(config('opsdeck.evidence.disk'))->assertExists($evidence->storage_path);
    }

    public function test_viewer_cannot_store_evidence(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.evidence.disk'));
        [$user, $organization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('evidence.store'), [
                'title' => '閲覧者の証跡',
                'evidence_type' => 'policy',
                'file' => UploadedFile::fake()->create('viewer-evidence.pdf', 100, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('evidence', ['organization_id' => $organization->id, 'title' => '閲覧者の証跡']);
    }

    public function test_operator_can_update_evidence_metadata(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $evidence = $this->evidence($organization, $user, 'MFA証跡');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('evidence.update', $evidence), [
                'title' => '更新後のMFA証跡',
                'evidence_type' => 'policy',
                'description' => '最新の設定確認',
            ])
            ->assertRedirect(route('evidence.show', $evidence));

        $this->assertSame('更新後のMFA証跡', $evidence->refresh()->title);
        $auditLog = AuditLog::query()->where('organization_id', $organization->id)->where('entity_id', $evidence->id)->where('action', AuditLog::EvidenceUpdated)->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['changed_fields' => ['title', 'description']], $auditLog->after_json);
        $this->assertStringNotContainsString('更新後のMFA証跡', json_encode($auditLog->toArray()));
        $this->assertStringNotContainsString('最新の設定確認', json_encode($auditLog->toArray()));
    }

    public function test_viewer_cannot_update_evidence_metadata(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Viewer);
        $evidence = $this->evidence($organization, $user, '閲覧専用証跡');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('evidence.update', $evidence), [
                'title' => '変更されるべきでない証跡',
                'evidence_type' => 'policy',
            ])
            ->assertForbidden();

        $this->assertSame('閲覧専用証跡', $evidence->refresh()->title);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'entity_id' => $evidence->id, 'action' => AuditLog::EvidenceUpdated]);
    }

    public function test_operator_cannot_update_evidence_in_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();
        $otherOrganization = Organization::query()->create([
            'name' => 'Other Evidence Org '.Str::random(8),
            'slug' => 'other-evidence-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
        $evidence = $this->evidence($otherOrganization, $user, 'Private Evidence');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('evidence.update', $evidence), [
                'title' => 'Cross organization change',
                'evidence_type' => 'policy',
            ])
            ->assertNotFound();

        $this->assertSame('Private Evidence', $evidence->refresh()->title);
    }

    public function test_expired_evidence_filter_uses_the_organizations_local_date(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->travelTo('2026-10-09 12:30:00');
        [$user, $organization] = $this->member();
        $organization->update(['timezone' => 'Pacific/Kiritimati']);
        $localToday = CarbonImmutable::today($organization->timezone);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Locally expired evidence',
            'evidence_type' => 'policy',
            'storage_disk' => config('opsdeck.evidence.disk'),
            'storage_path' => 'metadata-only',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
            'uploaded_by' => $user->id,
            'valid_until' => $localToday->subDay(),
        ]);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Locally current evidence',
            'evidence_type' => 'policy',
            'storage_disk' => config('opsdeck.evidence.disk'),
            'storage_path' => 'metadata-only',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
            'uploaded_by' => $user->id,
            'valid_until' => $localToday,
        ]);

        try {
            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
                ->get(route('evidence.index', ['expired' => 1]))
                ->assertOk()
                ->assertSee('Locally expired evidence')
                ->assertDontSee('Locally current evidence');
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_evidence_pages_use_a_stable_order_when_creation_times_match(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => 1]);
        [$user, $organization] = $this->member();
        $createdAt = now()->startOfSecond();
        $sharedAttributes = [
            'organization_id' => $organization->id,
            'evidence_type' => 'policy',
            'storage_disk' => config('opsdeck.evidence.disk'),
            'mime_type' => 'application/pdf',
            'file_size' => 1,
            'uploaded_by' => $user->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        $firstEvidence = Evidence::query()->create($sharedAttributes + ['title' => '同時刻の証跡1', 'storage_path' => 'metadata-only-1']);
        $secondEvidence = Evidence::query()->create($sharedAttributes + ['title' => '同時刻の証跡2', 'storage_path' => 'metadata-only-2']);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('evidence.index', ['page' => 1]))
            ->assertOk()
            ->assertSee('href="'.route('evidence.show', $secondEvidence).'"', false)
            ->assertDontSee('href="'.route('evidence.show', $firstEvidence).'"', false);

        $this->actingAs($user)->withSession($session)
            ->get(route('evidence.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('href="'.route('evidence.show', $firstEvidence).'"', false)
            ->assertDontSee('href="'.route('evidence.show', $secondEvidence).'"', false);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role = MembershipRole::Operator): array
    {
        $suffix = Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        $user = User::factory()->create(['email' => 'evidence-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Organization '.$suffix, 'slug' => 'organization-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }

    private function evidence(Organization $organization, User $user, string $title): Evidence
    {
        return Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => $title,
            'evidence_type' => 'policy',
            'storage_disk' => config('opsdeck.evidence.disk'),
            'storage_path' => 'metadata-only',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
            'uploaded_by' => $user->id,
        ]);
    }
}
