<?php

namespace App\Services;

class BackupStorageIsolation
{
    public function allowsRemoteStorageInCurrentTest(): bool
    {
        return app()->environment('testing')
            && config('opsdeck.backup.allow_remote_storage_in_tests') === true;
    }

    /**
     * @param  array<string, mixed>  $backupDisk
     */
    public function isDedicated(array $backupDisk): bool
    {
        $backupBucket = $backupDisk['bucket'] ?? null;
        if (! is_string($backupBucket) || trim($backupBucket) === '') {
            return false;
        }

        $reservedBuckets = array_filter([
            config('opsdeck.storage.expected_production_bucket'),
            config('opsdeck.storage.expected_development_bucket'),
        ], static fn (mixed $bucket): bool => is_string($bucket) && trim($bucket) !== '');
        if (in_array($backupBucket, $reservedBuckets, true)) {
            return false;
        }

        foreach (['opsdeck_objects', 'opsdeck_objects_test'] as $diskName) {
            $applicationDisk = config('filesystems.disks.'.$diskName);
            if (! is_array($applicationDisk) || ($applicationDisk['driver'] ?? null) !== 's3') {
                continue;
            }

            if (($applicationDisk['bucket'] ?? null) === $backupBucket) {
                return false;
            }

            foreach (['key', 'secret'] as $credentialName) {
                $applicationCredential = $applicationDisk[$credentialName] ?? null;
                $backupCredential = $backupDisk[$credentialName] ?? null;
                if (is_string($applicationCredential)
                    && $applicationCredential !== ''
                    && is_string($backupCredential)
                    && $backupCredential !== ''
                    && hash_equals($applicationCredential, $backupCredential)) {
                    return false;
                }
            }
        }

        return true;
    }
}
