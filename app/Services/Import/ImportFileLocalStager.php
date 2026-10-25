<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportFileLocalStager
{
    /**
     * @template TResult
     *
     * @param  callable(string): TResult  $callback
     * @return TResult
     */
    public function withLocalCopy(string $diskName, string $sourcePath, callable $callback): mixed
    {
        $source = Storage::disk($diskName)->readStream($sourcePath);
        if (! is_resource($source)) {
            throw new RuntimeException('取込ファイルを読み込めません。');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'opsdeck-import-');
        if (! is_string($temporaryPath)) {
            fclose($source);
            throw new RuntimeException('取込ファイルの一時領域を準備できません。');
        }

        try {
            $destination = fopen($temporaryPath, 'wb');
            if (! is_resource($destination)) {
                throw new RuntimeException('取込ファイルの一時領域を開けません。');
            }

            try {
                if (stream_copy_to_stream($source, $destination) === false) {
                    throw new RuntimeException('取込ファイルを一時領域へコピーできません。');
                }
            } finally {
                fclose($destination);
            }

            return $callback($temporaryPath);
        } finally {
            fclose($source);
            if (is_file($temporaryPath) && ! unlink($temporaryPath)) {
                throw new RuntimeException('取込ファイルの一時データを削除できません。');
            }
        }
    }
}
