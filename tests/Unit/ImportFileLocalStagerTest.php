<?php

namespace Tests\Unit;

use App\Services\Import\ImportFileLocalStager;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ImportFileLocalStagerTest extends TestCase
{
    public function test_it_stages_a_storage_stream_to_a_local_path_and_removes_it_after_use(): void
    {
        Storage::fake('staging-test');
        Storage::disk('staging-test')->put('tenant/import.xlsx', 'xlsx-content');
        $stager = new ImportFileLocalStager;
        $temporaryPath = null;

        $result = $stager->withLocalCopy('staging-test', 'tenant/import.xlsx', function (string $path) use (&$temporaryPath): string {
            $temporaryPath = $path;

            return file_get_contents($path);
        });

        $this->assertSame('xlsx-content', $result);
        $this->assertIsString($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
    }

    public function test_it_removes_the_staged_file_when_processing_fails(): void
    {
        Storage::fake('staging-test');
        Storage::disk('staging-test')->put('tenant/import.xlsx', 'xlsx-content');
        $stager = new ImportFileLocalStager;
        $temporaryPath = null;

        try {
            $stager->withLocalCopy('staging-test', 'tenant/import.xlsx', function (string $path) use (&$temporaryPath): never {
                $temporaryPath = $path;
                throw new RuntimeException('Expected test failure.');
            });
            $this->fail('The callback exception should be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Expected test failure.', $exception->getMessage());
        }

        $this->assertIsString($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
    }
}
