<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpsDeckStorageConnectivityCommandTest extends TestCase
{
    public function test_development_connection_check_writes_reads_and_deletes_one_temporary_object(): void
    {
        $this->configureDevelopmentDisk();
        $disk = Storage::disk('opsdeck_objects_test');

        $this->artisan('opsdeck:storage:connectivity-test', ['--environment' => 'development'])
            ->expectsOutput('Object storage connectivity verified: one temporary object was written, read back, and deleted.')
            ->assertExitCode(0);

        $this->assertSame([], $disk->allFiles());
    }

    public function test_storage_check_rejects_a_bucket_that_does_not_match_its_environment(): void
    {
        $this->configureDevelopmentDisk();
        config(['filesystems.disks.opsdeck_objects_test.bucket' => 'opsdeck-prod-files']);
        Storage::shouldReceive('disk')->never();

        $this->artisan('opsdeck:storage:connectivity-test', ['--environment' => 'development'])
            ->expectsOutput('The selected storage disk is not configured for its expected private bucket. No object was written.')
            ->assertExitCode(1);
    }

    public function test_production_storage_check_requires_a_production_application_environment(): void
    {
        $this->configureDevelopmentDisk();
        Storage::shouldReceive('disk')->never();

        $this->artisan('opsdeck:storage:connectivity-test', ['--environment' => 'production'])
            ->expectsOutput('Production storage checks require APP_ENV=production.')
            ->assertExitCode(1);
    }

    public function test_temporary_object_is_deleted_when_read_back_does_not_match(): void
    {
        $this->configureDevelopmentDisk();
        $disk = \Mockery::mock(Storage::disk('opsdeck_objects_test'))->makePartial();
        $disk->shouldReceive('get')->once()->andReturn('unexpected-content');
        Storage::set('opsdeck_objects_test', $disk);

        $this->artisan('opsdeck:storage:connectivity-test', ['--environment' => 'development'])
            ->expectsOutput('Object storage connectivity verification failed. Credentials and provider responses were not displayed.')
            ->assertExitCode(1);

        $this->assertSame([], Storage::disk('opsdeck_objects_test')->allFiles());
    }

    public function test_failed_cleanup_reports_the_temporary_object_location(): void
    {
        $this->configureDevelopmentDisk();
        $disk = \Mockery::mock(Storage::disk('opsdeck_objects_test'))->makePartial();
        $disk->shouldReceive('get')->once()->andReturn('unexpected-content');
        $disk->shouldReceive('delete')->once()->andReturnFalse();
        Storage::set('opsdeck_objects_test', $disk);

        $this->artisan('opsdeck:storage:connectivity-test', ['--environment' => 'development'])
            ->expectsOutputToContain('The temporary object could not be deleted. Remove it manually from opsdeck-dev-files at opsdeck-connectivity-checks/')
            ->assertExitCode(1);

        $remainingObjects = Storage::disk('opsdeck_objects_test')->allFiles();
        $this->assertCount(1, $remainingObjects);
        $this->assertStringStartsWith('opsdeck-connectivity-checks/', $remainingObjects[0]);
    }

    public function test_cleanup_fails_when_the_object_still_exists_after_delete_reports_success(): void
    {
        $this->configureDevelopmentDisk();
        $disk = \Mockery::mock(Storage::disk('opsdeck_objects_test'))->makePartial();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::set('opsdeck_objects_test', $disk);

        $this->artisan('opsdeck:storage:connectivity-test', ['--environment' => 'development'])
            ->expectsOutputToContain('The temporary object could not be deleted. Remove it manually from opsdeck-dev-files at opsdeck-connectivity-checks/')
            ->assertExitCode(1);

        $remainingObjects = Storage::disk('opsdeck_objects_test')->allFiles();
        $this->assertCount(1, $remainingObjects);
        $this->assertStringStartsWith('opsdeck-connectivity-checks/', $remainingObjects[0]);
    }

    private function configureDevelopmentDisk(): void
    {
        Storage::fake('opsdeck_objects_test');
        config([
            'opsdeck.storage.expected_development_bucket' => 'opsdeck-dev-files',
            'opsdeck.storage.expected_production_bucket' => 'opsdeck-prod-files',
            'filesystems.disks.opsdeck_objects_test' => [
                'driver' => 's3',
                'key' => 'test-access-key',
                'secret' => 'test-secret-key',
                'region' => 'auto',
                'bucket' => 'opsdeck-dev-files',
                'endpoint' => 'https://account.example.r2.cloudflarestorage.com',
                'use_path_style_endpoint' => true,
                'throw' => true,
            ],
        ]);
    }
}
