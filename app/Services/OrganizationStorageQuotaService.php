<?php

namespace App\Services;

use App\Exceptions\StorageQuotaExceededException;
use App\Models\Organization;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrganizationStorageQuotaService
{
    private const BYTES_PER_MEBIBYTE = 1048576;

    private const BYTES_PER_GIBIBYTE = 1073741824;

    private const MEBIBYTES_PER_GIBIBYTE = 1024;

    public function __construct(private PlanAccessService $planAccessService) {}

    /**
     * @return array{used_bytes: int, limit_bytes: int, base_limit_bytes: int, addon_limit_bytes: int, addon_gib: int, pending_addon_gib: int|null, addon_effective_at_display: string|null, remaining_bytes: int, used_percentage: int, warning_percentage: int, warning: bool, used_display: string, limit_display: string, remaining_display: string}
     */
    public function summary(Organization $organization): array
    {
        $planCode = $this->planAccessService->effectivePlanCode($organization);
        $planLimits = config('opsdeck.storage_quotas.plan_limit_megabytes');
        $limitMegabytes = (int) ($planLimits[$planCode] ?? $planLimits[config('opsdeck.free_plan_code')]);
        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $storageAddonEnabled = config('opsdeck.release_features.storage_addon') === true;
        $storageAddonGib = $storageAddonEnabled && $planCode === config('opsdeck.premium_plan_code')
            ? max(0, (int) $organization->storage_addon_gib)
            : 0;
        $pendingStorageAddonGib = $storageAddonEnabled && $planCode === config('opsdeck.premium_plan_code')
            && $organization->storage_addon_pending_gib !== null
            ? max(0, (int) $organization->storage_addon_pending_gib)
            : null;
        $addonLimitBytes = $storageAddonGib * self::BYTES_PER_GIBIBYTE;
        $limitBytes = ($limitMegabytes * $bytesPerMegabyte) + $addonLimitBytes;
        $usedBytes = $this->usedBytes($organization);
        $warningPercentage = (int) config('opsdeck.storage_quotas.warning_percentage');
        $usedPercentage = $limitBytes > 0 ? (int) ceil(($usedBytes / $limitBytes) * 100) : 100;
        $usesGibibytes = $limitBytes >= self::BYTES_PER_GIBIBYTE;
        $displayDivisor = $usesGibibytes ? $bytesPerMegabyte * self::MEBIBYTES_PER_GIBIBYTE : $bytesPerMegabyte;
        $displayUnit = $usesGibibytes ? 'GiB' : 'MiB';
        $remainingBytes = max(0, $limitBytes - $usedBytes);
        $addonEffectiveAt = $pendingStorageAddonGib === null ? null : $organization->storage_addon_effective_at;
        $addonEffectiveAtDisplay = $addonEffectiveAt?->copy()->timezone($organization->timezone)->format('Y/m/d');

        return [
            'used_bytes' => $usedBytes,
            'limit_bytes' => $limitBytes,
            'base_limit_bytes' => $limitMegabytes * $bytesPerMegabyte,
            'addon_limit_bytes' => $addonLimitBytes,
            'addon_gib' => $storageAddonGib,
            'pending_addon_gib' => $pendingStorageAddonGib,
            'addon_effective_at_display' => $addonEffectiveAtDisplay,
            'remaining_bytes' => $remainingBytes,
            'used_percentage' => $usedPercentage,
            'warning_percentage' => $warningPercentage,
            'warning' => $usedPercentage >= $warningPercentage,
            'used_display' => number_format($usedBytes / $displayDivisor, 1).' '.$displayUnit,
            'limit_display' => number_format($limitBytes / $displayDivisor, 0).' '.$displayUnit,
            'remaining_display' => number_format($remainingBytes / $displayDivisor, 1).' '.$displayUnit,
        ];
    }

    /** @return array{used_bytes: int, used_display: string, limit_bytes: int|null, limit_display: string, remaining_bytes: int|null, remaining_display: string, used_percentage: int|null} */
    public function platformUsageSummary(): array
    {
        $importFiles = DB::table('import_batches')
            ->selectRaw('COALESCE(source_storage_disk, ?) AS storage_disk', [(string) config('opsdeck.import.legacy_disk')])
            ->addSelect('source_file_path AS storage_path')
            ->selectRaw('MAX(source_file_size_bytes) AS file_size')
            ->whereIn('source_type', ['csv', 'xlsx'])
            ->whereNotNull('source_file_path')
            ->groupBy('source_storage_disk', 'source_file_path');

        $evidenceFiles = DB::table('evidence')
            ->select('storage_disk', 'storage_path')
            ->selectRaw('MAX(file_size) AS file_size')
            ->groupBy('storage_disk', 'storage_path');

        $storedFiles = DB::query()
            ->fromSub($importFiles->unionAll($evidenceFiles), 'stored_files')
            ->select('storage_disk', 'storage_path')
            ->selectRaw('MAX(file_size) AS file_size')
            ->groupBy('storage_disk', 'storage_path')
            ->cursor();

        $usedBytes = 0;
        foreach ($storedFiles as $storedFile) {
            $diskName = (string) $storedFile->storage_disk;
            $path = (string) $storedFile->storage_path;
            $knownSize = $storedFile->file_size === null ? null : (int) $storedFile->file_size;
            $fileSize = $knownSize;
            if ($fileSize === null) {
                $disk = Storage::disk($diskName);
                if ($disk->exists($path)) {
                    $fileSize = (int) $disk->size($path);
                }
            }

            $usedBytes += max(0, $fileSize ?? 0);
        }

        $platformLimitMegabytes = config('opsdeck.storage_quotas.platform_limit_megabytes');
        $bytesPerMegabyte = (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
        $platformLimitBytes = is_numeric($platformLimitMegabytes) && (int) $platformLimitMegabytes > 0
            ? (int) $platformLimitMegabytes * $bytesPerMegabyte
            : null;
        $remainingBytes = $platformLimitBytes === null ? null : max(0, $platformLimitBytes - $usedBytes);

        return [
            'used_bytes' => $usedBytes,
            'used_display' => $this->formatBytes($usedBytes),
            'limit_bytes' => $platformLimitBytes,
            'limit_display' => $platformLimitBytes === null ? '未設定' : $this->formatBytes($platformLimitBytes),
            'remaining_bytes' => $remainingBytes,
            'remaining_display' => $remainingBytes === null ? '未設定' : $this->formatBytes($remainingBytes),
            'used_percentage' => $platformLimitBytes === null ? null : (int) ceil(($usedBytes / $platformLimitBytes) * 100),
        ];
    }

    /**
     * @param  array{limit_bytes: int|null, used_percentage: int|null}  $summary
     */
    public function platformCapacityNeedsAttention(array $summary): bool
    {
        if ($summary['limit_bytes'] === null) {
            return app()->environment('production');
        }

        return $summary['used_percentage'] !== null
            && $summary['used_percentage'] >= (int) config('opsdeck.storage_quotas.warning_percentage');
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $store
     * @return TResult
     */
    public function storeWithinQuota(Organization $organization, int $incomingBytes, Closure $store): mixed
    {
        try {
            return Cache::lock(
                'opsdeck:organization-storage-quota:'.hash('sha256', (string) $organization->getKey()),
                (int) config('opsdeck.storage_quotas.lock_seconds'),
            )->block((int) config('opsdeck.storage_quotas.lock_wait_seconds'), function () use ($organization, $incomingBytes, $store): mixed {
                $currentOrganization = Organization::query()->findOrFail($organization->getKey());
                $summary = $this->summary($currentOrganization);
                if ($incomingBytes > $summary['remaining_bytes']) {
                    throw new StorageQuotaExceededException;
                }

                $platformLimitMegabytes = config('opsdeck.storage_quotas.platform_limit_megabytes');
                if ($platformLimitMegabytes === null && app()->environment('production')) {
                    throw new StorageQuotaExceededException('サービス全体の保存容量が設定されていません。運営管理者にお問い合わせください。');
                }

                if ($platformLimitMegabytes === null) {
                    return $store();
                }

                return Cache::lock(
                    'opsdeck:platform-storage-quota',
                    (int) config('opsdeck.storage_quotas.lock_seconds'),
                )->block((int) config('opsdeck.storage_quotas.lock_wait_seconds'), function () use ($platformLimitMegabytes, $incomingBytes, $store): mixed {
                    $platformUsage = $this->platformUsageSummary();
                    $platformLimitBytes = (int) $platformLimitMegabytes * (int) config('opsdeck.storage_quotas.bytes_per_megabyte');
                    if ($incomingBytes > max(0, $platformLimitBytes - $platformUsage['used_bytes'])) {
                        throw new StorageQuotaExceededException('OpsDeck全体の保存容量上限に達しています。新しいファイルは保存できません。運営管理者にお問い合わせください。');
                    }

                    return $store();
                });
            });
        } catch (LockTimeoutException) {
            throw new StorageQuotaExceededException('アップロードが集中しています。少し待ってから、もう一度お試しください。');
        }
    }

    private function usedBytes(Organization $organization): int
    {
        $defaultDisk = (string) config('opsdeck.import.legacy_disk');
        $importFiles = DB::table('import_batches')
            ->selectRaw('COALESCE(source_storage_disk, ?) AS storage_disk', [$defaultDisk])
            ->addSelect('source_file_path AS storage_path')
            ->selectRaw('MAX(source_file_size_bytes) AS file_size')
            ->where('organization_id', $organization->getKey())
            ->whereIn('source_type', ['csv', 'xlsx'])
            ->whereNotNull('source_file_path')
            ->groupBy('source_storage_disk', 'source_file_path');

        $evidenceFiles = DB::table('evidence')
            ->select('storage_disk', 'storage_path')
            ->selectRaw('MAX(file_size) AS file_size')
            ->where('organization_id', $organization->getKey())
            ->groupBy('storage_disk', 'storage_path');

        $storedFiles = DB::query()
            ->fromSub($importFiles->unionAll($evidenceFiles), 'stored_files')
            ->select('storage_disk', 'storage_path')
            ->selectRaw('MAX(file_size) AS file_size')
            ->groupBy('storage_disk', 'storage_path')
            ->cursor();

        $usedBytes = 0;
        foreach ($storedFiles as $storedFile) {
            $diskName = (string) $storedFile->storage_disk;
            $path = (string) $storedFile->storage_path;
            $fileSize = $storedFile->file_size === null ? null : (int) $storedFile->file_size;
            if ($fileSize === null) {
                $disk = Storage::disk($diskName);
                if ($disk->exists($path)) {
                    $fileSize = (int) $disk->size($path);
                }
            }

            $usedBytes += max(0, $fileSize ?? 0);
        }

        return $usedBytes;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= self::BYTES_PER_GIBIBYTE) {
            return number_format($bytes / self::BYTES_PER_GIBIBYTE, 1).' GiB';
        }

        if ($bytes >= self::BYTES_PER_MEBIBYTE) {
            return number_format($bytes / self::BYTES_PER_MEBIBYTE, 1).' MiB';
        }

        return number_format($bytes).' B';
    }
}
