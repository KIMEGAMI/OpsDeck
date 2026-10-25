<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseClientVersionDetector
{
    private const VERSION_CHECK_TIMEOUT_SECONDS = 5;

    /** @return 'mysql'|'mariadb' */
    public function detect(string $binaryPath): string
    {
        $binaryName = strtolower(basename(str_replace('\\', '/', $binaryPath)));
        try {
            $process = new Process([$binaryPath, '--version']);
            $process->setTimeout(self::VERSION_CHECK_TIMEOUT_SECONDS);
            $process->run();
        } catch (Throwable) {
            throw new RuntimeException('Database client vendor could not be identified.');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Database client vendor could not be identified.');
        }

        $vendor = $this->classifyVersion($binaryName, $process->getOutput().' '.$process->getErrorOutput());
        if ($vendor === null) {
            throw new RuntimeException('Database client vendor could not be identified.');
        }

        return $vendor;
    }

    /** @return 'mysql'|'mariadb'|null */
    public function classifyVersion(string $binaryName, string $versionOutput): ?string
    {
        if (str_starts_with(strtolower($binaryName), 'mariadb')) {
            return 'mariadb';
        }

        if (str_contains(strtolower($versionOutput), 'mariadb')) {
            return 'mariadb';
        }

        if (str_starts_with(strtolower($binaryName), 'mysql') || str_starts_with(strtolower($binaryName), 'mysqldump')) {
            return 'mysql';
        }

        return null;
    }
}
