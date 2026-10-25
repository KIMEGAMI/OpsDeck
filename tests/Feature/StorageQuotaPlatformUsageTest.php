<?php

namespace Tests\Feature;

use App\Exceptions\StorageQuotaExceededException;
use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationStorageQuotaService;
use App\Services\PlanAccessService;
use App\Services\StorageAddonCapacityService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorageQuotaPlatformUsageTest extends TestCase
{
    use DatabaseTransactions;

    private const MEBIBYTES_PER_GIBIBYTE = 1024;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_platform_usage_counts_unique_import_and_evidence_files_across_organizations(): void
    {
        $baseline = app(OrganizationStorageQuotaService::class)->platformUsageSummary();
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $user = User::factory()->create();
        $importDisk = (string) config('opsdeck.import.disk');
        $sharedFilePath = 'shared/customer-data-'.Str::uuid().'.csv';
        $distinctFilePath = 'shared/policy-'.Str::uuid().'.pdf';
        $organizationIds = [$firstOrganization->id, $secondOrganization->id];

        try {
            foreach ([1200, 1100] as $fileSize) {
                ImportBatch::query()->create([
                    'organization_id' => $firstOrganization->id,
                    'source_type' => 'csv',
                    'source_name' => 'reimported-source',
                    'source_file_path' => $sharedFilePath,
                    'source_storage_disk' => null,
                    'source_file_size_bytes' => $fileSize,
                    'status' => 'completed',
                ]);
            }

            ImportBatch::query()->create([
                'organization_id' => $secondOrganization->id,
                'source_type' => 'google_sheet',
                'source_name' => 'external-sheet',
                'source_file_path' => 'shared/external-sheet-'.Str::uuid().'.csv',
                'source_storage_disk' => $importDisk,
                'source_file_size_bytes' => 9000,
                'status' => 'completed',
            ]);

            Evidence::query()->create([
                'organization_id' => $firstOrganization->id,
                'title' => 'Same underlying file',
                'evidence_type' => 'policy',
                'storage_disk' => $importDisk,
                'storage_path' => $sharedFilePath,
                'mime_type' => 'text/csv',
                'file_size' => 1300,
                'uploaded_by' => $user->id,
            ]);
            Evidence::query()->create([
                'organization_id' => $secondOrganization->id,
                'title' => 'Distinct evidence file',
                'evidence_type' => 'policy',
                'storage_disk' => 'public',
                'storage_path' => $distinctFilePath,
                'mime_type' => 'application/pdf',
                'file_size' => 500,
                'uploaded_by' => $user->id,
            ]);

            $summary = app(OrganizationStorageQuotaService::class)->platformUsageSummary();

            $this->assertSame($baseline['used_bytes'] + 1800, $summary['used_bytes']);
        } finally {
            ImportBatch::query()->whereIn('organization_id', $organizationIds)->delete();
            Evidence::query()->whereIn('organization_id', $organizationIds)->delete();
            Organization::query()->whereKey($organizationIds)->delete();
            $user->delete();
        }
    }

    public function test_organization_usage_counts_unique_files_and_reads_missing_import_size_from_disk(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = User::factory()->create();
        $currentImportDisk = 'opsdeck_objects';
        $legacyImportDisk = 'legacy_import_archive';
        config([
            'opsdeck.import.disk' => $currentImportDisk,
            'opsdeck.import.legacy_disk' => $legacyImportDisk,
        ]);
        Storage::fake($legacyImportDisk);

        $sharedPath = 'quota/shared-'.Str::uuid().'.pdf';
        $importFallbackPath = 'quota/import-fallback-'.Str::uuid().'.csv';
        Storage::disk($legacyImportDisk)->put($importFallbackPath, str_repeat('i', 120));

        foreach ([700, 600] as $knownSize) {
            ImportBatch::query()->create([
                'organization_id' => $organization->id,
                'source_type' => 'csv',
                'source_name' => 'same-source',
                'source_storage_disk' => null,
                'source_file_path' => $sharedPath,
                'source_file_size_bytes' => $knownSize,
                'status' => 'completed',
            ]);
        }

        ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => 'import-fallback',
            'source_storage_disk' => null,
            'source_file_path' => $importFallbackPath,
            'source_file_size_bytes' => null,
            'status' => 'completed',
        ]);

        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Same physical file',
            'evidence_type' => 'policy',
            'storage_disk' => $legacyImportDisk,
            'storage_path' => $sharedPath,
            'mime_type' => 'application/pdf',
            'file_size' => 650,
            'uploaded_by' => $user->id,
        ]);
        Evidence::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Distinct evidence file',
            'evidence_type' => 'policy',
            'storage_disk' => $legacyImportDisk,
            'storage_path' => 'quota/evidence-'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 80,
            'uploaded_by' => $user->id,
        ]);
        ImportBatch::query()->create([
            'organization_id' => $otherOrganization->id,
            'source_type' => 'csv',
            'source_name' => 'other-organization',
            'source_file_path' => 'quota/foreign-'.Str::uuid().'.csv',
            'source_file_size_bytes' => 50000,
            'status' => 'completed',
        ]);

        $summary = app(OrganizationStorageQuotaService::class)->summary($organization);

        $this->assertSame(900, $summary['used_bytes']);
        $this->assertSame('0.0 MiB', $summary['used_display']);
    }

    public function test_company_storage_summary_displays_binary_capacity_units(): void
    {
        $organization = Organization::factory()->create();
        $service = app(OrganizationStorageQuotaService::class);

        $this->assertSame('100 MiB', $service->summary($organization)['limit_display']);

        $organization->update([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'storage_addon_gib' => 2,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => now()->addMonth(),
        ]);
        $premiumSummary = $service->summary($organization);

        $this->assertSame('4 GiB', $premiumSummary['limit_display']);
        $this->assertSame('0.0 GiB', $premiumSummary['used_display']);
        $this->assertSame('4.0 GiB', $premiumSummary['remaining_display']);
        $this->assertSame(2, $premiumSummary['addon_gib']);
        $this->assertSame(3, $premiumSummary['pending_addon_gib']);
        $this->assertNotNull($premiumSummary['addon_effective_at_display']);

        $organization->update(['billing_status' => 'cancelled']);
        $freeSummary = $service->summary($organization);

        $this->assertSame('100 MiB', $freeSummary['limit_display']);
        $this->assertSame(0, $freeSummary['addon_gib']);
    }

    public function test_storage_addon_entitlement_is_ignored_when_outside_release_scope(): void
    {
        config([
            'opsdeck.release_features.storage_addon' => false,
            'opsdeck.storage_quotas.plan_limit_megabytes.premium' => 2,
        ]);
        $organization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'storage_addon_gib' => 1,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => now()->addMonth(),
        ]);
        $service = app(OrganizationStorageQuotaService::class);
        $summary = $service->summary($organization);

        $this->assertSame(2 * (int) config('opsdeck.storage_quotas.bytes_per_megabyte'), $summary['limit_bytes']);
        $this->assertSame(0, $summary['addon_limit_bytes']);
        $this->assertSame(0, $summary['addon_gib']);
        $this->assertNull($summary['pending_addon_gib']);
        $this->assertNull($summary['addon_effective_at_display']);
        $capacityService = app(StorageAddonCapacityService::class);
        $this->assertNull($capacityService->maximumQuantity());
        $this->assertFalse($capacityService->canSchedule($organization, 1));

        $storeWasInvoked = false;
        try {
            $service->storeWithinQuota(
                $organization,
                $summary['limit_bytes'] + 1,
                static function () use (&$storeWasInvoked): string {
                    $storeWasInvoked = true;

                    return 'stored';
                },
            );
            $this->fail('An upload above the Premium base quota must be rejected while the add-on is frozen.');
        } catch (StorageQuotaExceededException) {
            $this->assertFalse($storeWasInvoked);
        }
    }

    public function test_platform_storage_summary_displays_binary_capacity_units(): void
    {
        $organization = Organization::factory()->create();
        $service = app(OrganizationStorageQuotaService::class);
        $baseline = $service->platformUsageSummary();
        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $bytesPerGibibyte = $bytesPerMegabyte * self::MEBIBYTES_PER_GIBIBYTE;
        $minimumGiBibyteUsage = max($baseline['used_bytes'], $bytesPerGibibyte);
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'csv',
            'source_name' => '容量表示確認.csv',
            'source_file_path' => 'quota/platform-'.Str::uuid().'.csv',
            'source_storage_disk' => config('opsdeck.import.disk'),
            'source_file_size_bytes' => $minimumGiBibyteUsage - $baseline['used_bytes'],
            'status' => 'completed',
        ]);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => (int) ceil(($minimumGiBibyteUsage + $bytesPerGibibyte) / $bytesPerMegabyte),
        ]);

        try {
            $summary = $service->platformUsageSummary();

            $this->assertStringEndsWith(' GiB', $summary['used_display']);
            $this->assertStringEndsWith(' GiB', $summary['limit_display']);
            $this->assertStringEndsWith(' GiB', $summary['remaining_display']);
        } finally {
            $batch->delete();
            $organization->delete();
        }
    }

    public function test_platform_storage_limit_applies_across_organizations(): void
    {
        config(['opsdeck.storage_quotas.plan_limit_megabytes.free' => 100000]);
        $existingOrganization = Organization::factory()->create();
        $uploadOrganization = Organization::factory()->create();
        $batch = ImportBatch::query()->create([
            'organization_id' => $existingOrganization->id,
            'source_type' => 'csv',
            'source_name' => 'existing.csv',
            'source_file_path' => 'quota/existing-'.Str::uuid().'.csv',
            'source_storage_disk' => config('opsdeck.import.disk'),
            'source_file_size_bytes' => 900 * 1024,
            'status' => 'completed',
        ]);
        $service = app(OrganizationStorageQuotaService::class);
        $usedBytes = $service->platformUsageSummary()['used_bytes'];
        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $limitMegabytes = max(1, (int) ceil($usedBytes / $bytesPerMegabyte));
        config(['opsdeck.storage_quotas.platform_limit_megabytes' => $limitMegabytes]);
        $incomingBytes = ($limitMegabytes * $bytesPerMegabyte) - $usedBytes + 1;

        try {
            $this->expectException(StorageQuotaExceededException::class);

            $service->storeWithinQuota($uploadOrganization, $incomingBytes, static fn (): string => 'stored');
        } finally {
            $batch->delete();
            Organization::query()->whereKey([$existingOrganization->id, $uploadOrganization->id])->delete();
        }
    }

    public function test_storage_addon_sale_reserves_current_and_pending_premium_capacity(): void
    {
        $targetOrganization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);
        $otherOrganization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'storage_addon_gib' => 1,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => now()->addMonth(),
        ]);

        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $bytesPerGibibyte = self::MEBIBYTES_PER_GIBIBYTE * $bytesPerMegabyte;
        $basePremiumBytes = (int) config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code')) * $bytesPerMegabyte;
        $existingReservations = 0;
        $planAccessService = app(PlanAccessService::class);
        foreach (Organization::query()->where('plan_code', config('opsdeck.premium_plan_code'))->whereNotIn('id', [$targetOrganization->id, $otherOrganization->id])->cursor() as $existingOrganization) {
            if ($planAccessService->effectivePlanCode($existingOrganization) !== config('opsdeck.premium_plan_code')) {
                continue;
            }

            $currentQuantity = max(0, (int) $existingOrganization->storage_addon_gib);
            $pendingQuantity = $existingOrganization->storage_addon_pending_gib === null
                ? $currentQuantity
                : max($currentQuantity, (int) $existingOrganization->storage_addon_pending_gib);
            $existingReservations += $basePremiumBytes + ($pendingQuantity * $bytesPerGibibyte);
        }
        $acceptedReservationBytes = $existingReservations + (2 * $basePremiumBytes) + (4 * $bytesPerGibibyte);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => (int) ceil($acceptedReservationBytes / $bytesPerMegabyte),
            'opsdeck.storage_quotas.storage_addon_max_gib' => 10,
        ]);
        $service = app(StorageAddonCapacityService::class);

        $this->assertTrue($service->canSchedule($targetOrganization, 1));
        $this->assertFalse($service->canSchedule($targetOrganization, 2));
    }

    public function test_storage_addon_decrease_does_not_release_platform_capacity_before_renewal(): void
    {
        $targetOrganization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);
        $decreasingOrganization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'storage_addon_gib' => 3,
            'storage_addon_pending_gib' => 1,
            'storage_addon_effective_at' => now()->addMonth(),
        ]);

        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $bytesPerGibibyte = self::MEBIBYTES_PER_GIBIBYTE * $bytesPerMegabyte;
        $basePremiumBytes = (int) config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code')) * $bytesPerMegabyte;
        $existingReservations = 0;
        $planAccessService = app(PlanAccessService::class);
        foreach (Organization::query()->where('plan_code', config('opsdeck.premium_plan_code'))->whereNotIn('id', [$targetOrganization->id, $decreasingOrganization->id])->cursor() as $existingOrganization) {
            if ($planAccessService->effectivePlanCode($existingOrganization) !== config('opsdeck.premium_plan_code')) {
                continue;
            }

            $currentQuantity = max(0, (int) $existingOrganization->storage_addon_gib);
            $pendingQuantity = $existingOrganization->storage_addon_pending_gib === null
                ? $currentQuantity
                : max($currentQuantity, (int) $existingOrganization->storage_addon_pending_gib);
            $existingReservations += $basePremiumBytes + ($pendingQuantity * $bytesPerGibibyte);
        }
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => (int) ceil(($existingReservations + 2 * $basePremiumBytes + 3 * $bytesPerGibibyte) / $bytesPerMegabyte),
            'opsdeck.storage_quotas.storage_addon_max_gib' => 10,
        ]);

        $service = app(StorageAddonCapacityService::class);

        $this->assertTrue($service->canSchedule($targetOrganization, 0));
        $this->assertFalse($service->canSchedule($targetOrganization, 1));

        $summary = app(OrganizationStorageQuotaService::class)->summary($decreasingOrganization);
        $this->assertSame(3, $summary['addon_gib']);
        $this->assertSame(1, $summary['pending_addon_gib']);
        $this->assertSame(5 * $bytesPerGibibyte, $summary['limit_bytes']);
    }

    public function test_storage_addon_sale_stays_disabled_without_a_dedicated_capacity_limit(): void
    {
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => null,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 10,
        ]);
        $organization = Organization::factory()->create([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
        ]);

        $this->assertFalse(app(StorageAddonCapacityService::class)->canSchedule($organization, 1));
    }

    public function test_platform_storage_limit_allows_an_upload_that_exactly_fits(): void
    {
        config(['opsdeck.storage_quotas.plan_limit_megabytes.free' => 100000]);
        $organization = Organization::factory()->create();
        $service = app(OrganizationStorageQuotaService::class);
        $usedBytes = $service->platformUsageSummary()['used_bytes'];
        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $limitMegabytes = max(1, (int) ceil($usedBytes / $bytesPerMegabyte));
        config(['opsdeck.storage_quotas.platform_limit_megabytes' => $limitMegabytes]);
        $incomingBytes = ($limitMegabytes * $bytesPerMegabyte) - $usedBytes;

        try {
            $result = $service->storeWithinQuota($organization, $incomingBytes, static fn (): string => 'stored');

            $this->assertSame('stored', $result);
        } finally {
            $organization->delete();
        }
    }

    public function test_upload_fails_without_storing_a_file_when_the_organization_quota_lock_times_out(): void
    {
        $organization = Organization::factory()->create();
        $lockSeconds = (int) config('opsdeck.storage_quotas.lock_seconds');
        $lockWaitSeconds = (int) config('opsdeck.storage_quotas.lock_wait_seconds');
        $organizationLockKey = 'opsdeck:organization-storage-quota:'.hash('sha256', (string) $organization->getKey());
        $lock = $this->mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with($lockWaitSeconds, \Mockery::type(\Closure::class))
            ->andThrow(new LockTimeoutException('Lock unavailable.'));
        Cache::shouldReceive('lock')
            ->once()
            ->with($organizationLockKey, $lockSeconds)
            ->andReturn($lock);
        $storeWasInvoked = false;

        try {
            try {
                app(OrganizationStorageQuotaService::class)->storeWithinQuota($organization, 1, static function () use (&$storeWasInvoked): string {
                    $storeWasInvoked = true;

                    return 'stored';
                });
                $this->fail('A timed-out quota lock must reject the upload.');
            } catch (StorageQuotaExceededException $exception) {
                $this->assertStringStartsWith('アップロードが集中しています。少し待ってから、もう一度お試しください。', $exception->getMessage());
                $this->assertStringContainsString('すでに保存したファイルや登録済みの情報は削除されません。', $exception->getMessage());
            }

            $this->assertFalse($storeWasInvoked);
        } finally {
            $organization->delete();
        }
    }

    public function test_upload_fails_without_storing_a_file_when_the_platform_quota_lock_times_out(): void
    {
        config([
            'opsdeck.storage_quotas.plan_limit_megabytes.free' => 100000,
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000,
        ]);
        $organization = Organization::factory()->create();
        $lockSeconds = (int) config('opsdeck.storage_quotas.lock_seconds');
        $lockWaitSeconds = (int) config('opsdeck.storage_quotas.lock_wait_seconds');
        $organizationLockKey = 'opsdeck:organization-storage-quota:'.hash('sha256', (string) $organization->getKey());
        $organizationLock = $this->mock(Lock::class);
        $organizationLock->shouldReceive('block')
            ->once()
            ->with($lockWaitSeconds, \Mockery::type(\Closure::class))
            ->andReturnUsing(static fn (int $waitSeconds, \Closure $callback): mixed => $callback());
        $platformLock = $this->mock(Lock::class);
        $platformLock->shouldReceive('block')
            ->once()
            ->with($lockWaitSeconds, \Mockery::type(\Closure::class))
            ->andThrow(new LockTimeoutException('Lock unavailable.'));
        Cache::shouldReceive('lock')
            ->once()
            ->with($organizationLockKey, $lockSeconds)
            ->andReturn($organizationLock);
        Cache::shouldReceive('lock')
            ->once()
            ->with('opsdeck:platform-storage-quota', $lockSeconds)
            ->andReturn($platformLock);
        $storeWasInvoked = false;

        try {
            try {
                app(OrganizationStorageQuotaService::class)->storeWithinQuota($organization, 1, static function () use (&$storeWasInvoked): string {
                    $storeWasInvoked = true;

                    return 'stored';
                });
                $this->fail('A timed-out platform quota lock must reject the upload.');
            } catch (StorageQuotaExceededException $exception) {
                $this->assertStringStartsWith('アップロードが集中しています。少し待ってから、もう一度お試しください。', $exception->getMessage());
                $this->assertStringContainsString('すでに保存したファイルや登録済みの情報は削除されません。', $exception->getMessage());
            }

            $this->assertFalse($storeWasInvoked);
        } finally {
            $organization->delete();
        }
    }

    public function test_production_rejects_file_storage_when_the_platform_limit_is_not_configured(): void
    {
        $this->app['env'] = 'production';
        config(['opsdeck.storage_quotas.platform_limit_megabytes' => null]);
        $organization = Organization::factory()->create();

        try {
            $this->expectException(StorageQuotaExceededException::class);
            $this->expectExceptionMessage('サービス全体の保存容量が設定されていません');

            app(OrganizationStorageQuotaService::class)->storeWithinQuota($organization, 1, static fn (): string => 'stored');
        } finally {
            $organization->delete();
        }
    }

    public function test_platform_capacity_needs_attention_at_warning_threshold_or_when_unconfigured_in_production(): void
    {
        $service = app(OrganizationStorageQuotaService::class);
        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $warningPercentage = (int) config('opsdeck.storage_quotas.warning_percentage');
        $this->assertFalse($service->platformCapacityNeedsAttention(['limit_bytes' => $bytesPerMegabyte, 'used_percentage' => $warningPercentage - 1]));
        $this->assertTrue($service->platformCapacityNeedsAttention(['limit_bytes' => $bytesPerMegabyte, 'used_percentage' => $warningPercentage]));
        $this->assertTrue($service->platformCapacityNeedsAttention(['limit_bytes' => $bytesPerMegabyte, 'used_percentage' => 100]));
        $this->assertFalse($service->platformCapacityNeedsAttention(['limit_bytes' => null, 'used_percentage' => null]));

        $this->app['env'] = 'production';
        $this->assertTrue($service->platformCapacityNeedsAttention(['limit_bytes' => null, 'used_percentage' => null]));
    }
}
