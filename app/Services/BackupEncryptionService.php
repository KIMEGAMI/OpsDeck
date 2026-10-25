<?php

namespace App\Services;

use Closure;
use Generator;
use RuntimeException;
use Throwable;

class BackupEncryptionService
{
    public const ALGORITHM = 'libsodium-secretstream-xchacha20poly1305';

    public const FORMAT_VERSION = 1;

    public const EVIDENCE_INDEX_FILE = 'evidence-index.enc';

    public const CHUNK_BYTES = 65536;

    private const FRAME_LENGTH_BYTES = 4;

    private const MANIFEST_HMAC_KEY_INFO = 'opsdeck-backup-manifest-v1';

    private const APPLICATION_MANIFEST_HMAC_KEY_INFO = 'opsdeck-backup-manifest-app-key-v1';

    /** @param array<string, mixed> $manifest */
    public function manifestHmac(array $manifest): string
    {
        unset($manifest['manifest_hmac_sha256']);
        $encodedManifest = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $key = ($manifest['manifest_hmac_key_source'] ?? null) === 'application_key_v1'
            ? $this->applicationManifestHmacKey()
            : $this->manifestHmacKey();

        try {
            return hash_hmac('sha256', $encodedManifest, $key);
        } finally {
            sodium_memzero($key);
        }
    }

    /** @param array<string, mixed> $manifest */
    public function hasValidManifestHmac(array $manifest): bool
    {
        $signature = $manifest['manifest_hmac_sha256'] ?? null;
        $keySource = $manifest['manifest_hmac_key_source'] ?? null;
        if (! is_string($signature)
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
            || ($keySource !== null && ! in_array($keySource, ['application_key_v1', 'backup_encryption_key_v1'], true))) {
            return false;
        }

        try {
            return hash_equals($signature, $this->manifestHmac($manifest));
        } catch (Throwable) {
            return false;
        }
    }

    public function createVerifiedSnapshot(string $sourcePath, string $expectedChecksum): ?string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedChecksum) !== 1) {
            return null;
        }

        $snapshotPath = tempnam(sys_get_temp_dir(), 'opsdeck-restore-');
        if (! is_string($snapshotPath)) {
            return null;
        }

        $snapshotVerified = false;
        try {
            if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($snapshotPath, 0600)) {
                return null;
            }
            if (! @copy($sourcePath, $snapshotPath)) {
                return null;
            }

            $actualChecksum = hash_file('sha256', $snapshotPath);
            $snapshotVerified = is_string($actualChecksum) && hash_equals($expectedChecksum, $actualChecksum);

            return $snapshotVerified ? $snapshotPath : null;
        } finally {
            if (! $snapshotVerified) {
                @unlink($snapshotPath);
            }
        }
    }

    public function encryptFile(string $sourcePath, string $destinationPath): string
    {
        $source = @fopen($sourcePath, 'rb');
        if (! is_resource($source)) {
            throw new RuntimeException('暗号化対象を読み取れません。');
        }

        try {
            return $this->encryptStream($source, $destinationPath);
        } finally {
            fclose($source);
        }
    }

    public function encryptString(string $plaintext, string $destinationPath): string
    {
        $source = fopen('php://memory', 'w+b');
        if (! is_resource($source)) {
            throw new RuntimeException('暗号化対象を準備できません。');
        }

        try {
            if (fwrite($source, $plaintext) !== strlen($plaintext)) {
                throw new RuntimeException('暗号化対象を準備できません。');
            }
            rewind($source);

            return $this->encryptStream($source, $destinationPath);
        } finally {
            fclose($source);
        }
    }

    /** @return Generator<int, string> */
    public function decryptedChunks(string $sourcePath): Generator
    {
        $key = $this->key();
        $source = @fopen($sourcePath, 'rb');
        if (! is_resource($source)) {
            sodium_memzero($key);

            throw new RuntimeException('暗号化バックアップを読み取れません。');
        }

        $state = null;
        try {
            $header = $this->readExact($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            while (! feof($source)) {
                $frameLengthBytes = fread($source, self::FRAME_LENGTH_BYTES);
                if ($frameLengthBytes === '' && feof($source)) {
                    throw new RuntimeException('暗号化バックアップの終端がありません。');
                }
                if (! is_string($frameLengthBytes) || strlen($frameLengthBytes) !== self::FRAME_LENGTH_BYTES) {
                    throw new RuntimeException('暗号化バックアップの形式が不正です。');
                }
                $frameLength = unpack('Nlength', $frameLengthBytes)['length'] ?? 0;
                if (! is_int($frameLength)
                    || $frameLength < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $frameLength > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('暗号化バックアップの形式が不正です。');
                }

                $ciphertext = $this->readExact($source, $frameLength);
                $message = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
                if (! is_array($message) || count($message) !== 2) {
                    throw new RuntimeException('暗号化バックアップの認証に失敗しました。');
                }
                [$plaintext, $tag] = $message;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $trailingByte = fread($source, 1);
                    if ($plaintext !== '' || $trailingByte !== '') {
                        throw new RuntimeException('暗号化バックアップの終端が不正です。');
                    }

                    return;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw new RuntimeException('暗号化バックアップの形式が不正です。');
                }

                yield $plaintext;
            }
        } finally {
            if (is_string($state)) {
                sodium_memzero($state);
            }
            sodium_memzero($key);
            fclose($source);
        }

        throw new RuntimeException('暗号化バックアップの終端がありません。');
    }

    /** @return Generator<int, string> */
    public function plaintextChunks(string $sourcePath): Generator
    {
        $source = @fopen($sourcePath, 'rb');
        if (! is_resource($source)) {
            throw new RuntimeException('バックアップファイルを読み取れません。');
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if (! is_string($chunk)) {
                    throw new RuntimeException('バックアップファイルを読み取れません。');
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($source);
        }
    }

    public function decryptFile(string $sourcePath, string $destinationPath): void
    {
        $destination = @fopen($destinationPath, 'xb');
        if (! is_resource($destination)) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($destinationPath, 0600)) {
            fclose($destination);
            @unlink($destinationPath);

            throw new RuntimeException('一時ファイルのアクセス権を制限できません。');
        }

        try {
            foreach ($this->decryptedChunks($sourcePath) as $plaintext) {
                $this->writeAll($destination, $plaintext);
            }
            if (! fflush($destination)) {
                throw new RuntimeException('一時ファイルを書き込めません。');
            }
        } catch (Throwable $exception) {
            fclose($destination);
            @unlink($destinationPath);

            throw $exception;
        }

        fclose($destination);
    }

    public function encryptStream(mixed $source, string $destinationPath): string
    {
        return $this->encryptFromCallback($destinationPath, function (Closure $writeChunk) use ($source): void {
            while (! feof($source)) {
                $plaintext = fread($source, self::CHUNK_BYTES);
                if (! is_string($plaintext)) {
                    throw new RuntimeException('暗号化対象を読み取れません。');
                }
                if ($plaintext === '') {
                    break;
                }

                $writeChunk($plaintext);
            }
        });
    }

    public function encryptFromCallback(string $destinationPath, Closure $produceChunks): string
    {
        $key = $this->key();
        $destination = @fopen($destinationPath, 'xb');
        if (! is_resource($destination)) {
            sodium_memzero($key);

            throw new RuntimeException('暗号化バックアップを作成できません。');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($destinationPath, 0600)) {
            fclose($destination);
            @unlink($destinationPath);
            sodium_memzero($key);

            throw new RuntimeException('暗号化バックアップのアクセス権を制限できません。');
        }

        $state = null;
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($destination, $header);
            $writeChunk = function (string $plaintext) use (&$state, $destination): void {
                $plaintextLength = strlen($plaintext);
                for ($offset = 0; $offset < $plaintextLength; $offset += self::CHUNK_BYTES) {
                    $chunk = substr($plaintext, $offset, self::CHUNK_BYTES);
                    $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk);
                    $this->writeFrame($destination, $ciphertext);
                }
            };
            $produceChunks($writeChunk);
            $finalFrame = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->writeFrame($destination, $finalFrame);
            if (! fflush($destination)) {
                throw new RuntimeException('暗号化バックアップを書き込めません。');
            }
        } catch (Throwable $exception) {
            fclose($destination);
            @unlink($destinationPath);

            throw $exception;
        } finally {
            if (is_string($state)) {
                sodium_memzero($state);
            }
            sodium_memzero($key);
        }

        fclose($destination);
        $checksum = hash_file('sha256', $destinationPath);
        if (! is_string($checksum)) {
            @unlink($destinationPath);

            throw new RuntimeException('暗号化バックアップを検証できません。');
        }

        return $checksum;
    }

    private function key(): string
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('libsodium拡張が必要です。');
        }

        $encodedKey = config('opsdeck.backup.encryption_key');
        $key = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('バックアップ暗号化キーの設定が不正です。');
        }

        return $key;
    }

    private function manifestHmacKey(): string
    {
        $key = $this->key();

        try {
            return hash_hkdf('sha256', $key, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, self::MANIFEST_HMAC_KEY_INFO);
        } finally {
            sodium_memzero($key);
        }
    }

    private function applicationManifestHmacKey(): string
    {
        $applicationKey = config('app.key');
        if (! is_string($applicationKey) || trim($applicationKey) === '') {
            throw new RuntimeException('APP_KEY is required to authenticate a plaintext backup manifest.');
        }

        $keyMaterial = str_starts_with($applicationKey, 'base64:')
            ? base64_decode(substr($applicationKey, 7), true)
            : $applicationKey;
        if (! is_string($keyMaterial) || strlen($keyMaterial) < 32) {
            throw new RuntimeException('APP_KEY is invalid for backup manifest authentication.');
        }

        return hash_hkdf('sha256', $keyMaterial, 32, self::APPLICATION_MANIFEST_HMAC_KEY_INFO);
    }

    private function readExact(mixed $stream, int $length): string
    {
        $contents = '';
        while (strlen($contents) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($contents));
            if (! is_string($chunk) || $chunk === '') {
                break;
            }
            $contents .= $chunk;
        }
        if (strlen($contents) !== $length) {
            throw new RuntimeException('暗号化バックアップが途中で切れています。');
        }

        return $contents;
    }

    private function writeFrame(mixed $stream, string $ciphertext): void
    {
        $this->writeAll($stream, pack('N', strlen($ciphertext)).$ciphertext);
    }

    private function writeAll(mixed $stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if (! is_int($written) || $written === 0) {
                throw new RuntimeException('バックアップを書き込めません。');
            }
            $offset += $written;
        }
    }
}
