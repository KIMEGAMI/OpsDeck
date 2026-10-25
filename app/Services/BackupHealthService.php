<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

class BackupHealthService
{
    private const BYTES_PER_MEGABYTE = 1048576;

    private const BYTES_PER_GIGABYTE = 1073741824;

    public function __construct(private BackupEncryptionService $backupEncryptionService) {}

    public function latestBackupPath(): ?string
    {
        $backupRoot = (string) config('opsdeck.backup.path');
        $latestBackupCreatedAt = null;
        $latestBackupDirectory = null;

        if (! File::isDirectory($backupRoot)) {
            return null;
        }

        foreach (File::directories($backupRoot) as $backupDirectory) {
            $manifestPath = $backupDirectory.DIRECTORY_SEPARATOR.'manifest.json';
            if (! File::exists($manifestPath)) {
                continue;
            }

            try {
                $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                $createdAtValue = is_array($manifest) ? ($manifest['created_at'] ?? null) : null;
                if (! is_string($createdAtValue)) {
                    continue;
                }

                $createdAt = CarbonImmutable::parse($createdAtValue);
            } catch (Throwable) {
                continue;
            }

            if ($latestBackupCreatedAt === null || $createdAt->greaterThan($latestBackupCreatedAt)) {
                $latestBackupCreatedAt = $createdAt;
                $latestBackupDirectory = $backupDirectory;
            }
        }

        return $latestBackupDirectory;
    }

    /** @return array{status: 'ok'|'missing'|'stale'|'invalid', latest_backup_at: string, backup_count: int, measured_backup_count: int, unmeasured_backup_count: int, backup_payload_bytes: int, backup_payload_size_display: string, disk_free_bytes: ?int, disk_free_display: string, disk_total_bytes: ?int, disk_total_display: string} */
    public function report(): array
    {
        $backupRoot = (string) config('opsdeck.backup.path');
        $latestBackupCreatedAt = null;
        $latestBackupDirectory = null;
        $latestManifest = null;
        $hasManifest = false;
        $backupCount = 0;
        $measuredBackupCount = 0;
        $unmeasuredBackupCount = 0;
        $backupPayloadBytes = 0;

        if (File::isDirectory($backupRoot)) {
            foreach (File::directories($backupRoot) as $backupDirectory) {
                $manifestPath = $backupDirectory.DIRECTORY_SEPARATOR.'manifest.json';
                if (! File::exists($manifestPath)) {
                    continue;
                }
                $hasManifest = true;

                try {
                    $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                    $createdAtValue = is_array($manifest) ? ($manifest['created_at'] ?? null) : null;
                    if (! is_string($createdAtValue)) {
                        continue;
                    }

                    $createdAt = CarbonImmutable::parse($createdAtValue);
                } catch (Throwable) {
                    continue;
                }

                $backupCount++;
                $payloadSizeBytes = is_array($manifest) ? ($manifest['payload_size_bytes'] ?? null) : null;
                if (is_array($manifest)
                    && is_int($payloadSizeBytes)
                    && $payloadSizeBytes >= 0
                    && $this->backupEncryptionService->hasValidManifestHmac($manifest)) {
                    $measuredBackupCount++;
                    $backupPayloadBytes += $payloadSizeBytes;
                } else {
                    $unmeasuredBackupCount++;
                }

                if ($latestBackupCreatedAt === null || $createdAt->greaterThan($latestBackupCreatedAt)) {
                    $latestBackupCreatedAt = $createdAt;
                    $latestBackupDirectory = $backupDirectory;
                    $latestManifest = $manifest;
                }
            }
        }

        $diskCapacity = $this->diskCapacity($backupRoot);
        $storageReport = [
            'backup_count' => $backupCount,
            'measured_backup_count' => $measuredBackupCount,
            'unmeasured_backup_count' => $unmeasuredBackupCount,
            'backup_payload_bytes' => $backupPayloadBytes,
            'backup_payload_size_display' => $this->formatBytes($backupPayloadBytes),
            'disk_free_bytes' => $diskCapacity['free_bytes'],
            'disk_free_display' => $this->formatBytes($diskCapacity['free_bytes']),
            'disk_total_bytes' => $diskCapacity['total_bytes'],
            'disk_total_display' => $this->formatBytes($diskCapacity['total_bytes']),
        ];

        if ($latestBackupCreatedAt === null) {
            return ['status' => $hasManifest ? 'invalid' : 'missing', 'latest_backup_at' => '未確認', ...$storageReport];
        }

        if ($latestBackupCreatedAt->greaterThan(now()) || ! $this->hasUsableBackupFiles($latestBackupDirectory, $latestManifest)) {
            $status = 'invalid';
        } else {
            $status = $latestBackupCreatedAt->greaterThan(now()->subHours((int) config('opsdeck.backup.max_age_hours'))) ? 'ok' : 'stale';
        }

        return [
            'status' => $status,
            'latest_backup_at' => $latestBackupCreatedAt->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            ...$storageReport,
        ];
    }

    /** @return array{free_bytes: ?int, total_bytes: ?int} */
    private function diskCapacity(string $backupRoot): array
    {
        $existingDirectory = $backupRoot;
        while (! File::isDirectory($existingDirectory)) {
            $parentDirectory = dirname($existingDirectory);
            if ($parentDirectory === $existingDirectory) {
                return ['free_bytes' => null, 'total_bytes' => null];
            }

            $existingDirectory = $parentDirectory;
        }

        try {
            $freeBytes = disk_free_space($existingDirectory);
            $totalBytes = disk_total_space($existingDirectory);
        } catch (Throwable) {
            return ['free_bytes' => null, 'total_bytes' => null];
        }

        return [
            'free_bytes' => is_float($freeBytes) || is_int($freeBytes) ? (int) $freeBytes : null,
            'total_bytes' => is_float($totalBytes) || is_int($totalBytes) ? (int) $totalBytes : null,
        ];
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '未確認';
        }

        if ($bytes >= self::BYTES_PER_GIGABYTE) {
            return number_format($bytes / self::BYTES_PER_GIGABYTE, 1).' GB';
        }

        return number_format($bytes / self::BYTES_PER_MEGABYTE, 1).' MB';
    }

    private function hasUsableBackupFiles(?string $backupDirectory, mixed $manifest): bool
    {
        if ($backupDirectory === null || ! is_array($manifest) || ! is_array($manifest['database'] ?? null) || ! is_array($manifest['evidence_files'] ?? null)) {
            return false;
        }

        $database = $manifest['database'];
        if (! in_array($database['driver'] ?? null, ['sqlite', 'mysql', 'mariadb'], true) || ! is_string($database['file'] ?? null) || ! $this->isSafeRelativePath($database['file'])) {
            return false;
        }

        $databasePath = $backupDirectory.DIRECTORY_SEPARATOR.$database['file'];
        if (! File::isFile($databasePath) || File::size($databasePath) === 0) {
            return false;
        }

        if (array_key_exists('database_sha256', $manifest)) {
            $expectedDatabaseChecksum = $manifest['database_sha256'];
            if (! is_string($expectedDatabaseChecksum) || preg_match('/\A[a-f0-9]{64}\z/', $expectedDatabaseChecksum) !== 1) {
                return false;
            }

            $actualDatabaseChecksum = hash_file('sha256', $databasePath);
            if (! is_string($actualDatabaseChecksum) || ! hash_equals($expectedDatabaseChecksum, $actualDatabaseChecksum)) {
                return false;
            }
        }

        $isEncrypted = array_key_exists('encryption', $manifest);
        if ($isEncrypted && (! is_array($manifest['encryption'])
            || ($manifest['encryption']['algorithm'] ?? null) !== BackupEncryptionService::ALGORITHM
            || ($manifest['encryption']['format_version'] ?? null) !== BackupEncryptionService::FORMAT_VERSION
            || ($manifest['evidence_index_file'] ?? null) !== BackupEncryptionService::EVIDENCE_INDEX_FILE
            || ! is_string($manifest['evidence_index_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $manifest['evidence_index_sha256']) !== 1
            || ! is_string($manifest['database_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $manifest['database_sha256']) !== 1)) {
            return false;
        }
        if ($isEncrypted && (! array_is_list($manifest['evidence_files'])
            || ! array_all($manifest['evidence_files'], fn (mixed $file): bool => is_string($file) && preg_match('/\A[0-9]{8}\z/', $file) === 1)
            || count(array_unique($manifest['evidence_files'])) !== count($manifest['evidence_files']))) {
            return false;
        }
        if ($isEncrypted || array_key_exists('evidence_index_file', $manifest)) {
            $indexPath = $backupDirectory.DIRECTORY_SEPARATOR.$manifest['evidence_index_file'];
            if (! File::isFile($indexPath)) {
                return false;
            }
            $indexChecksum = hash_file('sha256', $indexPath);
            if (! is_string($manifest['evidence_index_sha256'] ?? null)
                || ! is_string($indexChecksum)
                || ! hash_equals($manifest['evidence_index_sha256'], $indexChecksum)) {
                return false;
            }
        }

        $evidenceChecksums = $manifest['evidence_sha256'] ?? null;
        if (array_key_exists('evidence_sha256', $manifest)
            && (! is_array($evidenceChecksums)
                || ! array_is_list($evidenceChecksums)
                || count($evidenceChecksums) !== count($manifest['evidence_files'])
                || ! array_all($evidenceChecksums, fn (mixed $value): bool => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1))) {
            return false;
        }
        if ($isEncrypted && ! is_array($evidenceChecksums)) {
            return false;
        }

        foreach ($manifest['evidence_files'] as $index => $file) {
            if (! is_string($file) || ! $this->isSafeRelativePath($file)) {
                return false;
            }
            if ($isEncrypted && preg_match('/\A[0-9]{8}\z/', $file) !== 1) {
                return false;
            }
            $evidencePath = $backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.$file.($isEncrypted ? '.enc' : '');
            if (! File::isFile($evidencePath)) {
                return false;
            }
            if (is_array($evidenceChecksums)) {
                $actualChecksum = hash_file('sha256', $evidencePath);
                if (! is_string($actualChecksum) || ! hash_equals($evidenceChecksums[$index], $actualChecksum)) {
                    return false;
                }
            }
        }

        if (array_key_exists('import_files', $manifest)) {
            $importFiles = $manifest['import_files'];
            $importChecksums = $manifest['import_sha256'] ?? null;
            if (! is_array($importFiles)
                || ! array_is_list($importFiles)
                || ! array_all($importFiles, fn (mixed $file): bool => is_string($file) && preg_match('/\A[0-9]{8}\z/', $file) === 1)
                || count(array_unique($importFiles)) !== count($importFiles)
                || ! is_array($importChecksums)
                || ! array_is_list($importChecksums)
                || count($importChecksums) !== count($importFiles)
                || ! array_all($importChecksums, fn (mixed $checksum): bool => is_string($checksum) && preg_match('/\A[a-f0-9]{64}\z/', $checksum) === 1)
                || ($manifest['import_sources_index_file'] ?? null) !== ($isEncrypted ? 'import_sources_index.enc' : 'import_sources_index.json')
                || ! is_string($manifest['import_sources_index_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $manifest['import_sources_index_sha256']) !== 1) {
                return false;
            }

            $indexPath = $backupDirectory.DIRECTORY_SEPARATOR.$manifest['import_sources_index_file'];
            if (! File::isFile($indexPath)) {
                return false;
            }
            $indexChecksum = hash_file('sha256', $indexPath);
            if (! is_string($indexChecksum) || ! hash_equals($manifest['import_sources_index_sha256'], $indexChecksum)) {
                return false;
            }

            foreach ($importFiles as $index => $file) {
                $importPath = $backupDirectory.DIRECTORY_SEPARATOR.'imports'.DIRECTORY_SEPARATOR.$file.($isEncrypted ? '.enc' : '');
                if (! File::isFile($importPath)) {
                    return false;
                }
                $actualChecksum = hash_file('sha256', $importPath);
                if (! is_string($actualChecksum) || ! hash_equals($importChecksums[$index], $actualChecksum)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isSafeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return $normalized !== ''
            && ! str_starts_with($normalized, '/')
            && ! (isset($normalized[1]) && $normalized[1] === ':')
            && ! in_array('..', explode('/', $normalized), true);
    }
}
