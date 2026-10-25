<?php

namespace Tests\Unit;

use App\Services\PrivateUploadStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PrivateUploadStorageServiceTest extends TestCase
{
    public function test_successful_upload_uses_a_uuid_key_without_the_original_filename(): void
    {
        Storage::fake('upload-success');
        $originalFilename = 'confidential-evidence.pdf';

        $path = app(PrivateUploadStorageService::class)->store(
            UploadedFile::fake()->create($originalFilename, 100, 'application/pdf'),
            'evidence/42',
            'upload-success',
            ['pdf'],
        );

        $this->assertSame('evidence/42/'.basename($path), $path);
        $this->assertTrue(Str::isUuid(pathinfo($path, PATHINFO_FILENAME)));
        $this->assertSame('pdf', pathinfo($path, PATHINFO_EXTENSION));
        $this->assertStringNotContainsString($originalFilename, $path);
        Storage::disk('upload-success')->assertExists($path);
    }

    public function test_failed_upload_removes_the_preallocated_key_and_returns_a_safe_field_error(): void
    {
        Storage::fake('upload-failure');
        $disk = \Mockery::mock(Storage::disk('upload-failure'))->makePartial();
        Storage::set('upload-failure', $disk);
        $disk->shouldReceive('putFileAs')
            ->once()
            ->withArgs(static fn (string $directory, UploadedFile $file, string $filename, array $options): bool => $directory === 'evidence/42'
                && Str::isUuid(pathinfo($filename, PATHINFO_FILENAME))
                && pathinfo($filename, PATHINFO_EXTENSION) === 'pdf'
                && $options === [])
            ->andThrow(new RuntimeException('Sensitive storage endpoint details.'));
        $disk->shouldReceive('delete')
            ->once()
            ->withArgs(static fn (string $path): bool => preg_match('/\Aevidence\/42\/[0-9a-f-]{36}\.pdf\z/', $path) === 1)
            ->andReturnTrue();

        try {
            app(PrivateUploadStorageService::class)->store(
                UploadedFile::fake()->create('confidential-evidence.pdf', 100, 'application/pdf'),
                'evidence/42',
                'upload-failure',
                ['pdf'],
            );
            $this->fail('A failed storage write should return a field validation error.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'ファイルを保存できませんでした。通信状態を確認し、時間をおいてもう一度お試しください。',
                $exception->errors()['file'][0],
            );
            $this->assertStringNotContainsString('Sensitive storage endpoint details.', $exception->getMessage());
        }
    }

    public function test_cleanup_failure_is_logged_without_replacing_the_safe_upload_error(): void
    {
        Storage::fake('upload-cleanup-failure');
        $disk = \Mockery::mock(Storage::disk('upload-cleanup-failure'))->makePartial();
        Storage::set('upload-cleanup-failure', $disk);
        $disk->shouldReceive('putFileAs')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('Sensitive cleanup details.'));
        Log::shouldReceive('warning')
            ->once()
            ->with('Private upload cleanup failed after storage write failed.');

        try {
            app(PrivateUploadStorageService::class)->store(
                UploadedFile::fake()->create('confidential-import.csv', 10, 'text/csv'),
                'imports/42',
                'upload-cleanup-failure',
                ['csv'],
            );
            $this->fail('A failed storage write should return a field validation error.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'ファイルを保存できませんでした。通信状態を確認し、時間をおいてもう一度お試しください。',
                $exception->errors()['file'][0],
            );
            $this->assertStringNotContainsString('Sensitive cleanup details.', $exception->getMessage());
        }
    }
}
