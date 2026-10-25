<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ImportSourceIntegrityService
{
    public function hashUploadedFile(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! is_string($path)) {
            throw new RuntimeException('アップロードファイルを検証できません。');
        }

        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw new RuntimeException('アップロードファイルを検証できません。');
        }

        return $sha256;
    }

    public function assertUnchanged(ImportBatch $importBatch): void
    {
        if (! in_array($importBatch->source_type, ['csv', 'xlsx'], true)
            || $importBatch->source_file_path === null
            || $importBatch->source_file_sha256 === null) {
            return;
        }

        try {
            $sourceStream = Storage::disk($importBatch->sourceStorageDiskName())->readStream($importBatch->source_file_path);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => '取込元ファイルを検証できません。再アップロードしてください。',
            ]);
        }

        if (! is_resource($sourceStream)) {
            throw ValidationException::withMessages([
                'file' => '取込元ファイルを検証できません。再アップロードしてください。',
            ]);
        }

        $context = hash_init('sha256');
        try {
            hash_update_stream($context, $sourceStream);
        } finally {
            fclose($sourceStream);
        }

        if (! hash_equals($importBatch->source_file_sha256, hash_final($context))) {
            throw ValidationException::withMessages([
                'file' => '取込元ファイルがアップロード後に変更されています。安全のため処理を停止しました。再アップロードしてください。',
            ]);
        }
    }
}
