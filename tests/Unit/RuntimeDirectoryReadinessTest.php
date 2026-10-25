<?php

namespace Tests\Unit;

use App\Services\RuntimeDirectoryReadiness;
use Tests\TestCase;

class RuntimeDirectoryReadinessTest extends TestCase
{
    private const PRIVATE_DIRECTORY_MODE = 0700;

    private const WORLD_WRITABLE_DIRECTORY_MODE = 0777;

    public function test_it_accepts_an_existing_writable_directory(): void
    {
        $readiness = new RuntimeDirectoryReadiness;
        $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-runtime-'.bin2hex(random_bytes(8));
        mkdir($temporaryDirectory, self::PRIVATE_DIRECTORY_MODE);

        try {
            $unavailableDirectories = $readiness->unavailableDirectories([
                'temporary_directory' => $temporaryDirectory,
            ]);

            $this->assertSame([], $unavailableDirectories);
        } finally {
            rmdir($temporaryDirectory);
        }
    }

    public function test_it_rejects_a_world_writable_directory(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows does not expose POSIX directory permission bits.');
        }

        $readiness = new RuntimeDirectoryReadiness;
        $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-world-writable-'.bin2hex(random_bytes(8));
        mkdir($temporaryDirectory, self::PRIVATE_DIRECTORY_MODE);

        try {
            chmod($temporaryDirectory, self::WORLD_WRITABLE_DIRECTORY_MODE);

            $unavailableDirectories = $readiness->unavailableDirectories([
                'world_writable_directory' => $temporaryDirectory,
            ]);

            $this->assertSame(['world_writable_directory'], $unavailableDirectories);
        } finally {
            chmod($temporaryDirectory, self::PRIVATE_DIRECTORY_MODE);
            rmdir($temporaryDirectory);
        }
    }

    public function test_it_reports_missing_paths_and_paths_that_are_not_directories(): void
    {
        $readiness = new RuntimeDirectoryReadiness;
        $temporaryFile = tempnam(sys_get_temp_dir(), 'opsdeck-runtime-');
        $this->assertNotFalse($temporaryFile);
        $missingDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-missing-runtime-'.bin2hex(random_bytes(8));

        try {
            $unavailableDirectories = $readiness->unavailableDirectories([
                'missing_directory' => $missingDirectory,
                'file_instead_of_directory' => $temporaryFile,
            ]);
        } finally {
            unlink($temporaryFile);
        }

        $this->assertSame(['missing_directory', 'file_instead_of_directory'], $unavailableDirectories);
    }
}
