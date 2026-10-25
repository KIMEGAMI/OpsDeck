<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EvidenceControllerTest extends TestCase
{
    public function test_operator_can_store_evidence_on_the_private_disk(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.evidence.disk' => 'opsdeck_objects']);
        Storage::fake('opsdeck_objects');
        [$user, $organization] = $this->member();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('evidence.store'), [
            'title' => 'MFA証跡', 'evidence_type' => 'policy', 'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $evidence = Evidence::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('opsdeck_objects', $evidence->storage_disk);
        $this->assertStringStartsWith('evidence/'.$organization->id.'/', $evidence->storage_path);
        $this->assertTrue(Str::isUuid(pathinfo($evidence->storage_path, PATHINFO_FILENAME)));
        $this->assertSame('pdf', pathinfo($evidence->storage_path, PATHINFO_EXTENSION));
        Storage::disk(config('opsdeck.evidence.disk'))->assertExists($evidence->storage_path);
        $auditLog = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('entity_id', $evidence->id)
            ->where('action', AuditLog::EvidenceCreated)
            ->sole();
        $this->assertSame($user->id, $auditLog->actor_user_id);
        $this->assertSame(['created_fields' => ['title', 'evidence_type', 'file']], $auditLog->after_json);
        $this->assertStringNotContainsString('MFA証跡', json_encode($auditLog->toArray()));
        $this->assertStringNotContainsString('evidence.pdf', json_encode($auditLog->toArray()));
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);
    }

    public function test_evidence_storage_failure_returns_a_safe_file_field_error_without_creating_a_record(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $diskName = 'opsdeck_objects';
        config(['opsdeck.evidence.disk' => $diskName]);
        Storage::fake($diskName);
        [$user, $organization] = $this->member();
        $disk = \Mockery::mock(Storage::disk($diskName))->makePartial();
        Storage::set($diskName, $disk);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('Sensitive storage endpoint details.'));
        $disk->shouldReceive('delete')
            ->once()
            ->withArgs(static fn (string $path): bool => str_starts_with($path, 'evidence/'.$organization->id.'/'))
            ->andReturnTrue();

        $response = $this->from(route('evidence.create'))
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('evidence.store'), [
                'title' => '保存失敗の証跡',
                'evidence_type' => 'policy',
                'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
            ]);

        $response->assertRedirect(route('evidence.create'))
            ->assertSessionHasErrors([
                'file' => 'ファイルを保存できませんでした。通信状態を確認し、時間をおいてもう一度お試しください。',
            ]);
        $this->assertDatabaseMissing('evidence', [
            'organization_id' => $organization->id,
            'title' => '保存失敗の証跡',
        ]);
        $this->assertStringNotContainsString('Sensitive storage endpoint details.', json_encode(session('errors')?->all()));
    }

    public function test_evidence_upload_file_is_removed_when_database_persistence_fails(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.evidence.disk' => 'opsdeck_objects']);
        Storage::fake('opsdeck_objects');
        config(['app.debug' => false]);
        [$user, $organization] = $this->member();
        Evidence::creating(static function (Evidence $evidence): void {
            throw new RuntimeException('Synthetic evidence persistence failure.');
        });

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('evidence.store'), [
            'title' => 'MFA証跡',
            'evidence_type' => 'policy',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ]);

        $response->assertInternalServerError()
            ->assertDontSee('Synthetic evidence persistence failure.', false);
        $this->assertDatabaseMissing('evidence', ['organization_id' => $organization->id, 'title' => 'MFA証跡']);
        $this->assertSame([], Storage::disk(config('opsdeck.evidence.disk'))->allFiles());
    }

    public function test_evidence_upload_and_record_are_rolled_back_when_audit_log_persistence_fails(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.evidence.disk' => 'opsdeck_objects']);
        Storage::fake('opsdeck_objects');
        config(['app.debug' => false]);
        [$user, $organization] = $this->member();
        AuditLog::creating(static function (AuditLog $auditLog): void {
            if ($auditLog->action === AuditLog::EvidenceCreated) {
                throw new RuntimeException('Synthetic evidence audit failure.');
            }
        });

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('evidence.store'), [
            'title' => 'MFA証跡',
            'evidence_type' => 'policy',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ]);

        $response->assertInternalServerError()
            ->assertDontSee('Synthetic evidence audit failure.', false);
        $this->assertDatabaseMissing('evidence', ['organization_id' => $organization->id, 'title' => 'MFA証跡']);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'action' => AuditLog::EvidenceCreated]);
        $this->assertSame([], Storage::disk(config('opsdeck.evidence.disk'))->allFiles());
    }

    public function test_operator_cannot_store_evidence_with_an_overlong_description(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.evidence.disk'));
        config(['opsdeck.evidence.description_max_length' => 8]);
        [$user, $organization] = $this->member();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('evidence.store'), [
            'title' => 'MFA証跡',
            'evidence_type' => 'policy',
            'description' => str_repeat('説', 9),
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors(['description' => '説明は8文字以内で入力してください。']);

        $this->assertDatabaseMissing('evidence', ['organization_id' => $organization->id, 'title' => 'MFA証跡']);
        $this->assertSame([], Storage::disk(config('opsdeck.evidence.disk'))->allFiles());
    }

    public function test_invalid_evidence_expiry_is_shown_next_to_field_and_keeps_other_inputs(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.evidence.disk'));
        [$user, $organization] = $this->member();

        $this->from(route('evidence.create'))
            ->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('evidence.store'), [
                'title' => '保管期限の証跡',
                'evidence_type' => 'policy',
                'file' => UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf'),
                'valid_until' => 'not-a-date',
            ])
            ->assertOk()
            ->assertSee('id="valid_until-error" class="errors field-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="valid_until-error"', false)
            ->assertSee('value="保管期限の証跡"', false)
            ->assertSee('エラーが出た場合は、安全のためファイルを再選択してください。');

        $this->assertDatabaseMissing('evidence', [
            'organization_id' => $organization->id,
            'title' => '保管期限の証跡',
        ]);
        $this->assertSame([], Storage::disk(config('opsdeck.evidence.disk'))->allFiles());
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

    public function test_evidence_upload_rate_limit_stops_repeated_uploads_before_storage(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Cache::flush();
        Storage::fake(config('opsdeck.evidence.disk'));
        config([
            'opsdeck.file_uploads.per_member_attempts_per_minute' => 1,
            'opsdeck.file_uploads.per_organization_attempts_per_minute' => 10,
        ]);
        [$user, $organization] = $this->member();
        $upload = static fn (): array => [
            'title' => 'MFA証跡',
            'evidence_type' => 'policy',
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ];

        $firstResponse = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('evidence.store'), $upload());
        $firstResponse->assertRedirect();
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('evidence.store'), $upload())
            ->assertStatus(429)
            ->assertSee('アップロードを少しお待ちください')
            ->assertSee('ファイルはOpsDeckに保存されていません。');

        $this->assertSame(1, Evidence::query()->where('organization_id', $organization->id)->count());
        $this->assertCount(1, Storage::disk(config('opsdeck.evidence.disk'))->allFiles());
    }

    public function test_premium_evidence_storage_quota_warns_and_rejects_an_oversized_upload(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Cache::flush();
        Storage::fake(config('opsdeck.import.disk'));
        Storage::fake(config('opsdeck.evidence.disk'));
        config(['opsdeck.storage_quotas.plan_limit_megabytes.premium' => 1]);
        [$user, $organization] = $this->member();
        $importPath = 'imports/'.$organization->id.'/existing.csv';
        $evidencePath = 'evidence/'.$organization->id.'/existing.pdf';
        Storage::disk(config('opsdeck.import.disk'))->put($importPath, 'existing import file');
        Storage::disk(config('opsdeck.evidence.disk'))->put($evidencePath, 'existing evidence file');
        ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '保存済みの取込ファイル',
            'source_file_path' => $importPath,
            'source_storage_disk' => config('opsdeck.import.disk'),
            'source_file_size_bytes' => 500000,
            'status' => 'preview',
        ]);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => '保存済みの証跡',
            'evidence_type' => 'policy',
            'storage_disk' => config('opsdeck.evidence.disk'),
            'storage_path' => $evidencePath,
            'mime_type' => 'application/pdf',
            'file_size' => 350000,
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('evidence.create'))
            ->assertOk()
            ->assertSee('現在 0.8 MiB ／ 上限 1 MiB（82%）')
            ->assertSee('あと約 0.2 MiB 保存できます。')
            ->assertSee('取込元ファイルと証跡ファイルの合計です。登録した社員や資産などの件数は、ここには含まれません。')
            ->assertSee('保存容量が警告目安の80%に達しています。')
            ->assertSee('上限に達すると新しいファイルをアップロードできなくなります。');

        Evidence::query()->where('organization_id', $organization->id)->update(['file_size' => 548576]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('evidence.create'))
            ->assertOk()
            ->assertSee('現在 1.0 MiB ／ 上限 1 MiB（100%）')
            ->assertSee('新しく保存できる残り容量はありません。')
            ->assertSee('取込元ファイルと証跡ファイルの合計です。登録した社員や資産などの件数は、ここには含まれません。')
            ->assertSee('保存容量の上限に達しました。新しいファイルをアップロードできません。')
            ->assertSee('すでに登録したデータとファイルは削除されません。')
            ->assertSee('容量についてサポートへ相談してください')
            ->assertSee(route('support.create', ['category' => 'billing']));

        $this->followingRedirects()->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('evidence.create'))
            ->post(route('evidence.store'), [
                'title' => '新しい証跡',
                'evidence_type' => 'policy',
                'file' => UploadedFile::fake()->create('new-evidence.pdf', 300, 'application/pdf'),
            ])
            ->assertOk()
            ->assertSee('すでに保存したファイルや登録済みの情報は削除されません。');

        $this->assertSame(1, Evidence::query()->where('organization_id', $organization->id)->count());
        $this->assertSame('existing import file', Storage::disk(config('opsdeck.import.disk'))->get($importPath));
        $this->assertSame('existing evidence file', Storage::disk(config('opsdeck.evidence.disk'))->get($evidencePath));
        $storedFiles = array_values(array_unique([
            ...Storage::disk(config('opsdeck.import.disk'))->allFiles(),
            ...Storage::disk(config('opsdeck.evidence.disk'))->allFiles(),
        ]));
        $this->assertEqualsCanonicalizing([$importPath, $evidencePath], $storedFiles);
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
        $this->assertDatabaseHas('product_events', ['organization_id' => $organization->id, 'event_key' => 'first_dashboard_action_completed']);
    }

    public function test_operator_cannot_update_evidence_with_an_overlong_description(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.evidence.description_max_length' => 8]);
        [$user, $organization] = $this->member();
        $evidence = $this->evidence($organization, $user, 'MFA証跡');
        $evidence->update(['description' => '既存の説明']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->put(route('evidence.update', $evidence), [
                'title' => 'MFA証跡',
                'evidence_type' => 'policy',
                'description' => str_repeat('説', 9),
            ])
            ->assertSessionHasErrors(['description' => '説明は8文字以内で入力してください。']);

        $this->assertSame('既存の説明', $evidence->refresh()->description);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'entity_id' => $evidence->id, 'action' => AuditLog::EvidenceUpdated]);
    }

    public function test_operator_sees_the_evidence_description_limit_before_saving(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('evidence.create'))
            ->assertOk()
            ->assertSee('説明（最大'.number_format((int) config('opsdeck.evidence.description_max_length')).'文字）')
            ->assertSee('maxlength="'.(int) config('opsdeck.evidence.description_max_length').'"', false);
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

    public function test_operator_can_download_evidence_from_the_private_disk(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $disk = 'opsdeck_objects';
        config(['opsdeck.evidence.disk' => $disk]);
        Storage::fake($disk);
        [$user, $organization] = $this->member();
        $path = 'evidence/'.$organization->id.'/private-evidence.pdf';
        Storage::disk($disk)->put($path, 'private evidence contents');
        $evidence = Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Private Evidence',
            'evidence_type' => 'policy',
            'storage_disk' => $disk,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 25,
            'uploaded_by' => $user->id,
        ]);
        config(['opsdeck.evidence.disk' => 'current_evidence_disk']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('evidence.download', $evidence))
            ->assertOk()
            ->assertDownload('evidence-'.$evidence->id.'.pdf')
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_operator_cannot_download_evidence_from_another_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake(config('opsdeck.evidence.disk'));
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
            ->get(route('evidence.download', $evidence))
            ->assertNotFound();
    }

    public function test_evidence_download_returns_not_found_when_the_stored_file_is_missing(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $disk = config('opsdeck.evidence.disk');
        Storage::fake($disk);
        [$user, $organization] = $this->member();
        $evidence = $this->evidence($organization, $user, 'Missing Evidence');
        $evidence->update(['storage_path' => 'evidence/'.$organization->id.'/missing.pdf']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('evidence.download', $evidence))
            ->assertNotFound();
    }

    public function test_evidence_download_returns_a_safe_service_unavailable_response_when_storage_fails(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $diskName = 'opsdeck_objects';
        Storage::fake($diskName);
        [$user, $organization] = $this->member();
        $evidence = $this->evidence($organization, $user, '一時的に読めない証跡');
        $path = 'evidence/'.$organization->id.'/temporarily-unavailable.pdf';
        $evidence->update(['storage_disk' => $diskName, 'storage_path' => $path]);
        $disk = \Mockery::mock(Storage::disk($diskName))->makePartial();
        Storage::set($diskName, $disk);
        $disk->shouldReceive('exists')
            ->once()
            ->with($path)
            ->andThrow(new RuntimeException('Sensitive endpoint and credential details.'));
        Log::shouldReceive('warning')
            ->once()
            ->with('Private evidence storage could not be read.');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('evidence.download', $evidence))
            ->assertServiceUnavailable()
            ->assertSee('証跡ファイルを読み込めません')
            ->assertSee('ファイルの内容は変更されていません。')
            ->assertDontSee('Sensitive endpoint and credential details.', false);
    }

    public function test_evidence_download_returns_service_unavailable_when_response_metadata_cannot_be_read(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $diskName = 'opsdeck_objects';
        Storage::fake($diskName);
        [$user, $organization] = $this->member();
        $evidence = $this->evidence($organization, $user, '応答情報を読めない証跡');
        $path = 'evidence/'.$organization->id.'/unavailable-metadata.pdf';
        $evidence->update(['storage_disk' => $diskName, 'storage_path' => $path]);
        $disk = \Mockery::mock(Storage::disk($diskName))->makePartial();
        Storage::set($diskName, $disk);
        $disk->shouldReceive('exists')->once()->with($path)->andReturnTrue();
        $disk->shouldReceive('download')
            ->once()
            ->with($path, 'evidence-'.$evidence->id.'.pdf', ['Content-Type' => 'application/pdf'])
            ->andThrow(new RuntimeException('Sensitive object metadata details.'));
        Log::shouldReceive('warning')
            ->once()
            ->with('Private evidence storage could not be read.');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('evidence.download', $evidence))
            ->assertServiceUnavailable()
            ->assertSee('保存先に一時的に接続できませんでした。')
            ->assertDontSee('Sensitive object metadata details.', false);
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
