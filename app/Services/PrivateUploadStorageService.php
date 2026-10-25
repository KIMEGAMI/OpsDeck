<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PrivateUploadStorageService
{
    /**
     * @param  list<string>  $allowedExtensions
     */
    public function store(UploadedFile $file, string $directory, string $diskName, array $allowedExtensions): string
    {
        $extension = strtolower((string) $file->guessExtension());
        $safeExtension = in_array($extension, array_map('strtolower', $allowedExtensions), true) ? '.'.$extension : '';
        $filename = Str::uuid()->toString().$safeExtension;
        $expectedPath = trim($directory, '/').'/'.$filename;

        try {
            $path = $file->storeAs($directory, $filename, $diskName);
            if (is_string($path) && trim($path) !== '') {
                return $path;
            }
        } catch (Throwable) {
            // A remote write can complete even if the client does not receive its response.
        }

        $this->removeFailedUpload($diskName, $expectedPath);

        throw ValidationException::withMessages([
            'file' => 'ファイルを保存できませんでした。通信状態を確認し、時間をおいてもう一度お試しください。',
        ]);
    }

    private function removeFailedUpload(string $diskName, string $path): void
    {
        try {
            if (! Storage::disk($diskName)->delete($path)) {
                throw new \RuntimeException('Uploaded object cleanup failed.');
            }
        } catch (Throwable) {
            try {
                Log::warning('Private upload cleanup failed after storage write failed.');
            } catch (Throwable) {
                // Preserve the safe validation error shown to the user.
            }
        }
    }
}
