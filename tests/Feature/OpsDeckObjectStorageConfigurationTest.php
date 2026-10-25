<?php

namespace Tests\Feature;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpsDeckObjectStorageConfigurationTest extends TestCase
{
    public function test_documented_r2_environment_names_take_precedence_over_legacy_names(): void
    {
        $disk = $this->loadStorageDiskWithEnvironment([
            'OPSDECK_STORAGE_DRIVER' => 's3',
            'OPSDECK_STORAGE_KEY' => 'example-access-key',
            'OPSDECK_STORAGE_SECRET' => 'example-secret-key',
            'OPSDECK_STORAGE_REGION' => 'auto',
            'OPSDECK_STORAGE_BUCKET' => 'example-private-bucket',
            'OPSDECK_STORAGE_ENDPOINT' => 'https://account.example.r2.cloudflarestorage.com',
            'OPSDECK_STORAGE_PATH_STYLE' => 'true',
            'OPSDECK_OBJECT_STORAGE_KEY' => 'legacy-access-key',
            'OPSDECK_OBJECT_STORAGE_SECRET' => 'legacy-secret-key',
            'OPSDECK_OBJECT_STORAGE_REGION' => 'legacy-region',
            'OPSDECK_OBJECT_STORAGE_BUCKET' => 'legacy-bucket',
            'OPSDECK_OBJECT_STORAGE_ENDPOINT' => 'https://legacy.example',
            'OPSDECK_OBJECT_STORAGE_PATH_STYLE' => 'false',
        ]);

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('example-access-key', $disk['key']);
        $this->assertSame('example-secret-key', $disk['secret']);
        $this->assertSame('auto', $disk['region']);
        $this->assertSame('example-private-bucket', $disk['bucket']);
        $this->assertSame('https://account.example.r2.cloudflarestorage.com', $disk['endpoint']);
        $this->assertTrue($disk['use_path_style_endpoint']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertSame(PHP_INT_MAX, $disk['options']['mup_threshold']);

        $command = new Command('PutObject', ['ACL' => 'private']);
        $disk['options']['before_upload']($command);

        $this->assertFalse($command->hasParam('ACL'));
    }

    public function test_legacy_object_storage_environment_names_remain_supported(): void
    {
        $disk = $this->loadStorageDiskWithEnvironment([
            'OPSDECK_OBJECT_STORAGE_KEY' => 'legacy-access-key',
            'OPSDECK_OBJECT_STORAGE_SECRET' => 'legacy-secret-key',
            'OPSDECK_OBJECT_STORAGE_REGION' => 'auto',
            'OPSDECK_OBJECT_STORAGE_BUCKET' => 'legacy-private-bucket',
            'OPSDECK_OBJECT_STORAGE_ENDPOINT' => 'https://legacy.example.r2.cloudflarestorage.com',
            'OPSDECK_OBJECT_STORAGE_PATH_STYLE' => 'false',
        ]);

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('legacy-access-key', $disk['key']);
        $this->assertSame('legacy-secret-key', $disk['secret']);
        $this->assertSame('auto', $disk['region']);
        $this->assertSame('legacy-private-bucket', $disk['bucket']);
        $this->assertSame('https://legacy.example.r2.cloudflarestorage.com', $disk['endpoint']);
        $this->assertFalse($disk['use_path_style_endpoint']);
    }

    public function test_non_production_disk_uses_only_its_separate_environment_names(): void
    {
        $disk = $this->loadStorageDiskWithEnvironment([
            'OPSDECK_STORAGE_KEY' => 'production-access-key',
            'OPSDECK_STORAGE_SECRET' => 'production-secret-key',
            'OPSDECK_STORAGE_BUCKET' => 'opsdeck-prod-files',
            'OPSDECK_STORAGE_ENDPOINT' => 'https://production.example.r2.cloudflarestorage.com',
            'OPSDECK_TEST_STORAGE_KEY' => 'test-access-key',
            'OPSDECK_TEST_STORAGE_SECRET' => 'test-secret-key',
            'OPSDECK_TEST_STORAGE_REGION' => 'auto',
            'OPSDECK_TEST_STORAGE_BUCKET' => 'opsdeck-dev-files',
            'OPSDECK_TEST_STORAGE_ENDPOINT' => 'https://test.example.r2.cloudflarestorage.com',
            'OPSDECK_TEST_STORAGE_PATH_STYLE' => 'true',
        ], 'opsdeck_objects_test');

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('test-access-key', $disk['key']);
        $this->assertSame('test-secret-key', $disk['secret']);
        $this->assertSame('auto', $disk['region']);
        $this->assertSame('opsdeck-dev-files', $disk['bucket']);
        $this->assertSame('https://test.example.r2.cloudflarestorage.com', $disk['endpoint']);
        $this->assertTrue($disk['use_path_style_endpoint']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertSame(PHP_INT_MAX, $disk['options']['mup_threshold']);
    }

    public function test_non_production_opsdeck_objects_disk_does_not_load_production_r2_credentials(): void
    {
        $disk = $this->loadStorageDiskWithEnvironment([
            'APP_ENV' => 'local',
            'OPSDECK_STORAGE_KEY' => 'production-access-key',
            'OPSDECK_STORAGE_SECRET' => 'production-secret-key',
            'OPSDECK_STORAGE_BUCKET' => 'opsdeck-prod-files',
            'OPSDECK_STORAGE_ENDPOINT' => 'https://production.example.r2.cloudflarestorage.com',
        ]);

        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/private'), $disk['root']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertArrayNotHasKey('key', $disk);
        $this->assertArrayNotHasKey('secret', $disk);
        $this->assertArrayNotHasKey('bucket', $disk);
        $this->assertArrayNotHasKey('endpoint', $disk);
    }

    public function test_opsdeck_disk_does_not_load_furimadeck_r2_credentials_or_bucket(): void
    {
        $disk = $this->loadStorageDiskWithEnvironment([
            'APP_ENV' => 'production',
            'FURIMADECK_R2_ACCESS_KEY_ID' => 'furimadeck-access-key',
            'FURIMADECK_R2_SECRET_ACCESS_KEY' => 'furimadeck-secret-key',
            'FURIMADECK_R2_REGION' => 'auto',
            'FURIMADECK_R2_BUCKET' => 'furimadeck-product-images',
            'FURIMADECK_R2_ENDPOINT' => 'https://furimadeck.example.r2.cloudflarestorage.com',
        ]);

        $this->assertSame('s3', $disk['driver']);
        $this->assertNull($disk['key']);
        $this->assertNull($disk['secret']);
        $this->assertNull($disk['region']);
        $this->assertNull($disk['bucket']);
        $this->assertNull($disk['endpoint']);
    }

    public function test_s3_adapter_upload_read_and_delete_use_acl_free_s3_operations(): void
    {
        $handler = new MockHandler([
            new Result([]),
            new Result(['Body' => Utils::streamFor('test-content')]),
            new Result([]),
        ]);
        $disk = $this->loadStorageDiskWithEnvironment([
            'OPSDECK_STORAGE_DRIVER' => 's3',
            'OPSDECK_STORAGE_KEY' => 'test-access-key',
            'OPSDECK_STORAGE_SECRET' => 'test-secret-key',
            'OPSDECK_STORAGE_REGION' => 'auto',
            'OPSDECK_STORAGE_BUCKET' => 'example-private-bucket',
            'OPSDECK_STORAGE_ENDPOINT' => 'https://account.example.r2.cloudflarestorage.com',
            'OPSDECK_STORAGE_PATH_STYLE' => 'true',
        ]);

        $filesystem = Storage::build([
            ...$disk,
            'driver' => 's3',
            'handler' => $handler,
            'throw' => true,
        ]);

        $path = 'evidence/42/'.Str::uuid().'.pdf';

        $uploadedFile = UploadedFile::fake()->createWithContent('original-evidence.pdf', 'test-content');

        $this->assertSame($path, $filesystem->putFileAs('evidence/42', $uploadedFile, basename($path)));
        $this->assertSame('PutObject', $handler->getLastCommand()->getName());
        $this->assertSame('PUT', $handler->getLastRequest()->getMethod());
        $this->assertFalse($handler->getLastRequest()->hasHeader('x-amz-acl'));

        $this->assertSame('test-content', $filesystem->get($path));
        $this->assertSame('GetObject', $handler->getLastCommand()->getName());
        $this->assertSame('GET', $handler->getLastRequest()->getMethod());

        $this->assertTrue($filesystem->delete($path));
        $this->assertSame('DeleteObject', $handler->getLastCommand()->getName());
        $this->assertSame('DELETE', $handler->getLastRequest()->getMethod());
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function loadStorageDiskWithEnvironment(array $values, string $diskName = 'opsdeck_objects'): array
    {
        $variableNames = [
            'APP_ENV',
            'OPSDECK_STORAGE_DRIVER',
            'OPSDECK_STORAGE_KEY',
            'OPSDECK_STORAGE_SECRET',
            'OPSDECK_STORAGE_REGION',
            'OPSDECK_STORAGE_BUCKET',
            'OPSDECK_STORAGE_ENDPOINT',
            'OPSDECK_STORAGE_PATH_STYLE',
            'OPSDECK_OBJECT_STORAGE_KEY',
            'OPSDECK_OBJECT_STORAGE_SECRET',
            'OPSDECK_OBJECT_STORAGE_REGION',
            'OPSDECK_OBJECT_STORAGE_BUCKET',
            'OPSDECK_OBJECT_STORAGE_ENDPOINT',
            'OPSDECK_OBJECT_STORAGE_PATH_STYLE',
            'OPSDECK_TEST_STORAGE_DRIVER',
            'OPSDECK_TEST_STORAGE_KEY',
            'OPSDECK_TEST_STORAGE_SECRET',
            'OPSDECK_TEST_STORAGE_REGION',
            'OPSDECK_TEST_STORAGE_BUCKET',
            'OPSDECK_TEST_STORAGE_ENDPOINT',
            'OPSDECK_TEST_STORAGE_PATH_STYLE',
            'FURIMADECK_R2_ACCESS_KEY_ID',
            'FURIMADECK_R2_SECRET_ACCESS_KEY',
            'FURIMADECK_R2_REGION',
            'FURIMADECK_R2_BUCKET',
            'FURIMADECK_R2_ENDPOINT',
        ];
        $originalValues = [];
        $originalEnvironment = [];
        $originalServer = [];

        foreach ($variableNames as $name) {
            $originalValues[$name] = getenv($name);
            $originalEnvironment[$name] = $_ENV[$name] ?? null;
            $originalServer[$name] = $_SERVER[$name] ?? null;
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        foreach ($values as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        if (! array_key_exists('APP_ENV', $values)) {
            $appEnvironment = $diskName === 'opsdeck_objects' ? 'production' : 'testing';
            putenv('APP_ENV='.$appEnvironment);
            $_ENV['APP_ENV'] = $appEnvironment;
            $_SERVER['APP_ENV'] = $appEnvironment;
        }

        try {
            $configuration = require base_path('config/filesystems.php');

            return $configuration['disks'][$diskName];
        } finally {
            foreach ($variableNames as $name) {
                if ($originalValues[$name] === false) {
                    putenv($name);
                } else {
                    putenv($name.'='.$originalValues[$name]);
                }

                if ($originalEnvironment[$name] === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $originalEnvironment[$name];
                }

                if ($originalServer[$name] === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $originalServer[$name];
                }
            }
        }
    }
}
