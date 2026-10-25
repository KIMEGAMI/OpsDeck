<?php

namespace App\Services;

class RuntimeDirectoryReadiness
{
    private const WORLD_WRITE_PERMISSION_MASK = 0002;

    /**
     * @param  array<string, string>  $directories
     * @return list<string>
     */
    public function unavailableDirectories(array $directories): array
    {
        $unavailableDirectories = [];

        foreach ($directories as $name => $path) {
            $isDirectory = is_dir($path);
            // Windows exposes synthetic POSIX mode bits, so permission checks are reliable only on POSIX hosts.
            $permissions = $isDirectory && PHP_OS_FAMILY !== 'Windows' ? fileperms($path) : false;
            $hasUnsafePermissions = PHP_OS_FAMILY !== 'Windows'
                && ($permissions === false || ($permissions & self::WORLD_WRITE_PERMISSION_MASK) !== 0);

            if (! $isDirectory || ! is_writable($path) || $hasUnsafePermissions) {
                $unavailableDirectories[] = $name;
            }
        }

        return $unavailableDirectories;
    }
}
