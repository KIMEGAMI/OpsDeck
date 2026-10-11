<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;

class DatabaseDumpBinaryLocator
{
    public function __construct(private ExecutableFinder $executableFinder) {}

    public function find(string $driver): ?string
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return null;
        }

        $configuredDirectory = config('opsdeck.backup.database_dump_directory');
        $extraDirectories = is_string($configuredDirectory) && trim($configuredDirectory) !== ''
            ? [$configuredDirectory]
            : [];

        $binaryNames = $driver === 'mariadb' ? ['mariadb-dump', 'mysqldump'] : ['mysqldump'];
        foreach ($binaryNames as $binaryName) {
            $binaryPath = $this->executableFinder->find($binaryName, null, $extraDirectories);
            if ($binaryPath !== null) {
                return $binaryPath;
            }
        }

        return null;
    }

    public function findRestoreClient(string $driver): ?string
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return null;
        }

        $configuredDirectory = config('opsdeck.backup.database_dump_directory');
        $extraDirectories = is_string($configuredDirectory) && trim($configuredDirectory) !== ''
            ? [$configuredDirectory]
            : [];
        $binaryNames = $driver === 'mariadb' ? ['mariadb', 'mysql'] : ['mysql'];

        foreach ($binaryNames as $binaryName) {
            $binaryPath = $this->executableFinder->find($binaryName, null, $extraDirectories);
            if ($binaryPath !== null) {
                return $binaryPath;
            }
        }

        return null;
    }
}
