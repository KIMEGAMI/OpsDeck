<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

class BackupHealthService
{
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

    /** @return array{status: 'ok'|'missing'|'stale'|'invalid', latest_backup_at: string} */
    public function report(): array
    {
        $backupRoot = (string) config('opsdeck.backup.path');
        $latestBackupCreatedAt = null;
        $latestBackupDirectory = null;
        $latestManifest = null;
        $hasManifest = false;

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

                if ($latestBackupCreatedAt === null || $createdAt->greaterThan($latestBackupCreatedAt)) {
                    $latestBackupCreatedAt = $createdAt;
                    $latestBackupDirectory = $backupDirectory;
                    $latestManifest = $manifest;
                }
            }
        }

        if ($latestBackupCreatedAt === null) {
            return ['status' => $hasManifest ? 'invalid' : 'missing', 'latest_backup_at' => '未確認'];
        }

        if ($latestBackupCreatedAt->greaterThan(now()) || ! $this->hasUsableBackupFiles($latestBackupDirectory, $latestManifest)) {
            $status = 'invalid';
        } else {
            $status = $latestBackupCreatedAt->greaterThan(now()->subHours((int) config('opsdeck.backup.max_age_hours'))) ? 'ok' : 'stale';
        }

        return [
            'status' => $status,
            'latest_backup_at' => $latestBackupCreatedAt->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];
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

        $evidenceChecksums = $manifest['evidence_sha256'] ?? null;
        if (array_key_exists('evidence_sha256', $manifest)
            && (! is_array($evidenceChecksums)
                || ! array_is_list($evidenceChecksums)
                || count($evidenceChecksums) !== count($manifest['evidence_files'])
                || ! array_all($evidenceChecksums, fn (mixed $value): bool => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1))) {
            return false;
        }

        foreach ($manifest['evidence_files'] as $index => $file) {
            if (! is_string($file) || ! $this->isSafeRelativePath($file)) {
                return false;
            }
            $evidencePath = $backupDirectory.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.$file;
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
