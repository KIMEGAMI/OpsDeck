<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

#[Signature('opsdeck:storage:connectivity-test {--environment= : Explicitly select production or development storage}')]
#[Description('Write, read, and delete one temporary object to verify an OpsDeck object storage connection')]
class OpsDeckStorageConnectivityTest extends Command
{
    public function handle(): int
    {
        $environment = (string) $this->option('environment');
        if (! in_array($environment, ['production', 'development'], true)) {
            $this->error('Specify --environment=production or --environment=development.');

            return self::FAILURE;
        }

        if ($environment === 'production' && ! app()->environment('production')) {
            $this->error('Production storage checks require APP_ENV=production.');

            return self::FAILURE;
        }

        $diskName = $environment === 'production' ? 'opsdeck_objects' : 'opsdeck_objects_test';
        $diskConfiguration = config('filesystems.disks.'.$diskName);
        $expectedBucket = (string) config('opsdeck.storage.'.($environment === 'production' ? 'expected_production_bucket' : 'expected_development_bucket'));
        $bucket = is_array($diskConfiguration) ? (string) ($diskConfiguration['bucket'] ?? '') : '';
        $endpoint = is_array($diskConfiguration) ? (string) ($diskConfiguration['endpoint'] ?? '') : '';
        $endpointHost = parse_url($endpoint, PHP_URL_HOST);

        if (! is_array($diskConfiguration)
            || ($diskConfiguration['driver'] ?? null) !== 's3'
            || $bucket === ''
            || $bucket !== $expectedBucket
            || ! is_string($endpointHost)
            || parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
            || ! is_string($diskConfiguration['key'] ?? null)
            || ($diskConfiguration['key'] ?? '') === ''
            || ! is_string($diskConfiguration['secret'] ?? null)
            || ($diskConfiguration['secret'] ?? '') === '') {
            $this->error('The selected storage disk is not configured for its expected private bucket. No object was written.');

            return self::FAILURE;
        }

        if ($environment === 'production'
            && $bucket === (string) config('opsdeck.storage.expected_development_bucket')) {
            $this->error('Production storage cannot use the development bucket. No object was written.');

            return self::FAILURE;
        }

        $objectKey = 'opsdeck-connectivity-checks/'.Str::uuid().'.txt';
        $payload = Str::random(64);
        $objectMayExist = false;
        $cleanupSucceeded = true;
        $verificationSucceeded = false;

        try {
            $objectMayExist = true;
            if (! Storage::disk($diskName)->put($objectKey, $payload, ['visibility' => 'private'])) {
                throw new \RuntimeException('Temporary object write failed.');
            }

            $contents = Storage::disk($diskName)->get($objectKey);
            if (! is_string($contents) || ! hash_equals(hash('sha256', $payload), hash('sha256', $contents))) {
                throw new \RuntimeException('Temporary object read-back did not match.');
            }
            $verificationSucceeded = true;
        } catch (Throwable) {
        } finally {
            if ($objectMayExist) {
                try {
                    $cleanupSucceeded = Storage::disk($diskName)->delete($objectKey);
                    if ($cleanupSucceeded) {
                        $cleanupSucceeded = ! Storage::disk($diskName)->exists($objectKey);
                    }
                } catch (Throwable) {
                    $cleanupSucceeded = false;
                }
            }
        }

        if (! $cleanupSucceeded) {
            $this->error('The temporary object could not be deleted. Remove it manually from '.$bucket.' at '.$objectKey.'.');

            return self::FAILURE;
        }

        if (! $verificationSucceeded) {
            $this->error('Object storage connectivity verification failed. Credentials and provider responses were not displayed.');

            return self::FAILURE;
        }

        $this->info('Object storage connectivity verified: one temporary object was written, read back, and deleted.');

        return self::SUCCESS;
    }
}
