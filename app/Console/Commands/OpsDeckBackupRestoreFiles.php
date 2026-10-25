<?php

namespace App\Console\Commands;

use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Services\BackupEncryptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Signature('opsdeck:backup:restore-files {path : Verified local backup generation directory} {--apply : Restore missing files after review and confirmation}')]
#[Description('Restore missing OpsDeck evidence and import source files from a verified local backup')]
class OpsDeckBackupRestoreFiles extends Command
{
    public function handle(BackupEncryptionService $encryption): int
    {
        try {
            $backupPath = $this->resolveBackupPath((string) $this->argument('path'));
            if ($this->call('opsdeck:backup:verify', ['path' => $backupPath]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            $manifest = $this->readManifest($backupPath);
            if (! $encryption->hasValidManifestHmac($manifest)) {
                throw new RuntimeException('Backup manifest authentication failed.');
            }

            $encrypted = array_key_exists('encryption', $manifest);
            $entries = $this->sourceEntries($backupPath, $manifest, $encrypted, $encryption);
            $this->assertDatabaseReferencesMatch($entries);
            $plan = $this->planRestore($backupPath, $entries, $encrypted, $encryption);
        } catch (Throwable) {
            $this->error('File restore preflight failed. Check the verified backup, restored database, and OpsDeck private storage configuration. No files were written.');

            return self::FAILURE;
        }

        $existingCount = count(array_filter($plan, static fn (array $item): bool => $item['state'] === 'already_restored'));
        $missingItems = array_values(array_filter($plan, static fn (array $item): bool => $item['state'] === 'missing'));
        $this->info(sprintf('Backup files verified: %d. Already present and identical: %d. Missing: %d.', count($plan), $existingCount, count($missingItems)));

        if (! $this->option('apply')) {
            $this->comment('Dry-run only. No files were written. Add --apply to restore missing files.');

            return self::SUCCESS;
        }

        if (! app()->environment('testing') && ! app()->isDownForMaintenance()) {
            $this->error('Enable maintenance mode and stop queue workers before applying a file restore.');

            return self::FAILURE;
        }

        if ($missingItems === []) {
            $this->info('No missing files need restoration.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Restore the missing files to their recorded OpsDeck private storage locations? Existing files will not be overwritten.')) {
            $this->comment('File restore was cancelled.');

            return self::SUCCESS;
        }

        try {
            foreach ($missingItems as $item) {
                $this->restoreFile($backupPath, $item, $encrypted, $encryption);
            }
        } catch (Throwable) {
            $this->error('File restore stopped after an error. Previously restored files were preserved; rerun the preflight to resume safely.');

            return self::FAILURE;
        }

        $this->info(sprintf('Restored %d missing OpsDeck files. Existing files were not changed.', count($missingItems)));

        return self::SUCCESS;
    }

    private function resolveBackupPath(string $path): string
    {
        if (is_link($path)) {
            throw new RuntimeException('Backup directory must not be a symbolic link.');
        }

        $backupRoot = realpath((string) config('opsdeck.backup.path'));
        $backupPath = realpath($path);
        if (! is_string($backupRoot) || ! is_string($backupPath) || ! is_dir($backupPath)
            || strcasecmp(dirname($backupPath), $backupRoot) !== 0) {
            throw new RuntimeException('Backup must be a generation directly inside the configured local backup directory.');
        }

        return $backupPath;
    }

    /** @return array<string, mixed> */
    private function readManifest(string $backupPath): array
    {
        $manifestPath = $this->backupFilePath($backupPath, 'manifest.json');
        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new RuntimeException('Backup manifest is invalid.');
        }

        return $manifest;
    }

    /** @param array<string, mixed> $manifest
     * @return list<array{disk: string, path: string, payload: string, kind: string}>
     */
    private function sourceEntries(string $backupPath, array $manifest, bool $encrypted, BackupEncryptionService $encryption): array
    {
        $evidenceIndexFile = $encrypted ? BackupEncryptionService::EVIDENCE_INDEX_FILE : 'evidence-index.json';
        $importIndexFile = $encrypted ? 'import_sources_index.enc' : 'import_sources_index.json';
        if (($manifest['evidence_index_file'] ?? null) !== $evidenceIndexFile
            || ($manifest['import_sources_index_file'] ?? null) !== $importIndexFile
            || ! is_array($manifest['evidence_files'] ?? null)
            || ! array_is_list($manifest['evidence_files'])
            || ! is_array($manifest['import_files'] ?? null)
            || ! array_is_list($manifest['import_files'])) {
            throw new RuntimeException('Backup file indexes are missing or unsupported.');
        }

        $entries = [
            ...$this->entriesForKind($backupPath, $manifest['evidence_files'], $evidenceIndexFile, 'evidence', $encrypted, $encryption),
            ...$this->entriesForKind($backupPath, $manifest['import_files'], $importIndexFile, 'imports', $encrypted, $encryption),
        ];

        return $entries;
    }

    /** @param list<mixed> $fileIdentifiers
     * @return list<array{disk: string, path: string, payload: string, kind: string}>
     */
    private function entriesForKind(string $backupPath, array $fileIdentifiers, string $indexFile, string $kind, bool $encrypted, BackupEncryptionService $encryption): array
    {
        $indexPath = $this->backupFilePath($backupPath, $indexFile);
        $contents = '';
        if ($encrypted) {
            foreach ($encryption->decryptedChunks($indexPath) as $chunk) {
                $contents .= $chunk;
            }
        } else {
            $contents = File::get($indexPath);
        }

        $index = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($index) || ! array_is_list($index) || count($index) !== count($fileIdentifiers)) {
            throw new RuntimeException('Backup file index does not match its manifest.');
        }

        $entries = [];
        $seen = [];
        foreach ($index as $position => $source) {
            $identifier = $fileIdentifiers[$position] ?? null;
            if (! is_string($identifier) || preg_match('/\A[0-9]{8}\z/', $identifier) !== 1
                || ! is_array($source)
                || ! is_string($source['disk'] ?? null)
                || ! is_string($source['path'] ?? null)
                || ! $this->isSafeRelativePath($source['path'])) {
                throw new RuntimeException('Backup file index contains an invalid entry.');
            }

            $diskName = $source['disk'];
            $this->assertOpsDeckStorageDisk($diskName);
            $sourceKey = hash('sha256', $diskName."\0".$source['path']);
            if (isset($seen[$sourceKey])) {
                throw new RuntimeException('Backup file index contains duplicate storage references.');
            }
            $seen[$sourceKey] = true;

            $payload = $kind.'/'.$identifier.($encrypted ? '.enc' : '');
            $this->backupFilePath($backupPath, $payload);
            $entries[] = ['disk' => $diskName, 'path' => $source['path'], 'payload' => $payload, 'kind' => $kind];
        }

        return $entries;
    }

    /** @param list<array{disk: string, path: string, payload: string, kind: string}> $entries */
    private function assertDatabaseReferencesMatch(array $entries): void
    {
        $backupReferences = [];
        foreach ($entries as $entry) {
            $key = hash('sha256', $entry['disk']."\0".$entry['path']);
            $backupReferences[$key] = true;
        }

        $databaseReferences = [];
        foreach (Evidence::query()->where('storage_path', '<>', 'metadata-only')->cursor() as $evidence) {
            $disk = (string) $evidence->storage_disk;
            $path = (string) $evidence->storage_path;
            if (! $this->isSafeRelativePath($path)) {
                throw new RuntimeException('The restored database contains an unsafe evidence path.');
            }
            $databaseReferences[hash('sha256', $disk."\0".$path)] = true;
        }

        foreach (ImportBatch::query()->whereIn('source_type', ['csv', 'xlsx'])->whereNotNull('source_file_path')->cursor() as $batch) {
            $disk = $batch->sourceStorageDiskName();
            $path = (string) $batch->source_file_path;
            if (! $this->isSafeRelativePath($path)) {
                throw new RuntimeException('The restored database contains an unsafe import path.');
            }
            $databaseReferences[hash('sha256', $disk."\0".$path)] = true;
        }

        if (array_diff_key($backupReferences, $databaseReferences) !== []
            || array_diff_key($databaseReferences, $backupReferences) !== []) {
            throw new RuntimeException('The selected backup does not match the restored database file references.');
        }
    }

    /** @param list<array{disk: string, path: string, payload: string, kind: string}> $entries
     * @return list<array{disk: string, path: string, payload: string, kind: string, checksum: string, state: string}>
     */
    private function planRestore(string $backupPath, array $entries, bool $encrypted, BackupEncryptionService $encryption): array
    {
        $plan = [];
        foreach ($entries as $entry) {
            $sourceStream = $this->openPayloadStream($backupPath, $entry['payload'], $encrypted, $encryption);
            try {
                $checksum = $this->streamChecksum($sourceStream);
            } finally {
                fclose($sourceStream);
            }

            $disk = Storage::disk($entry['disk']);
            $state = 'missing';
            if ($disk->exists($entry['path'])) {
                $targetStream = $disk->readStream($entry['path']);
                if (! is_resource($targetStream)) {
                    throw new RuntimeException('Existing target file could not be read.');
                }
                try {
                    if (! hash_equals($checksum, $this->streamChecksum($targetStream))) {
                        throw new RuntimeException('Existing target file conflicts with the backup.');
                    }
                } finally {
                    fclose($targetStream);
                }
                $state = 'already_restored';
            }

            $plan[] = $entry + ['checksum' => $checksum, 'state' => $state];
        }

        return $plan;
    }

    /** @param array{disk: string, path: string, payload: string, kind: string, checksum: string, state: string} $item */
    private function restoreFile(string $backupPath, array $item, bool $encrypted, BackupEncryptionService $encryption): void
    {
        $stream = $this->openPayloadStream($backupPath, $item['payload'], $encrypted, $encryption);
        try {
            if (! Storage::disk($item['disk'])->writeStream($item['path'], $stream)) {
                throw new RuntimeException('Restoring file failed.');
            }
        } finally {
            fclose($stream);
        }

        $restoredStream = Storage::disk($item['disk'])->readStream($item['path']);
        if (! is_resource($restoredStream)) {
            throw new RuntimeException('Restored file could not be verified.');
        }
        try {
            if (! hash_equals($item['checksum'], $this->streamChecksum($restoredStream))) {
                throw new RuntimeException('Restored file integrity check failed.');
            }
        } finally {
            fclose($restoredStream);
        }
    }

    /** @return resource */
    private function openPayloadStream(string $backupPath, string $relativePath, bool $encrypted, BackupEncryptionService $encryption)
    {
        $path = $this->backupFilePath($backupPath, $relativePath);
        if (! $encrypted) {
            $stream = @fopen($path, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('Backup payload could not be opened.');
            }

            return $stream;
        }

        $stream = tmpfile();
        if (! is_resource($stream)) {
            throw new RuntimeException('Temporary restore stream could not be created.');
        }

        try {
            foreach ($encryption->decryptedChunks($path) as $chunk) {
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($stream, substr($chunk, $offset));
                    if (! is_int($written) || $written < 1) {
                        throw new RuntimeException('Decrypted backup payload could not be staged.');
                    }
                    $offset += $written;
                }
            }
            if (! rewind($stream)) {
                throw new RuntimeException('Temporary restore stream could not be rewound.');
            }
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }

        return $stream;
    }

    /** @param resource $stream */
    private function streamChecksum($stream): string
    {
        $context = hash_init('sha256');
        if (hash_update_stream($context, $stream) === false) {
            throw new RuntimeException('File checksum could not be calculated.');
        }

        return hash_final($context);
    }

    private function backupFilePath(string $backupPath, string $relativePath): string
    {
        if (! $this->isSafeRelativePath($relativePath)) {
            throw new RuntimeException('Backup path is invalid.');
        }

        $candidate = $backupPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolved = realpath($candidate);
        if (! is_string($resolved) || is_link($candidate)
            || ! str_starts_with(strtolower($resolved), strtolower($backupPath.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Backup file is missing or outside the generation directory.');
        }

        return $resolved;
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function assertOpsDeckStorageDisk(string $diskName): void
    {
        $isProduction = app()->environment('production');
        $allowedDiskNames = $isProduction ? ['local', 'opsdeck_objects'] : ['local', 'opsdeck_objects_test'];
        if (! in_array($diskName, $allowedDiskNames, true)) {
            throw new RuntimeException('Backup references a storage disk outside the OpsDeck allowlist.');
        }

        $disk = config('filesystems.disks.'.$diskName);
        if (! is_array($disk) || ($disk['visibility'] ?? null) === 'public') {
            throw new RuntimeException('OpsDeck restore target must be configured as private storage.');
        }

        if ($diskName === 'local') {
            if (($disk['driver'] ?? null) !== 'local' || ! is_string($disk['root'] ?? null)) {
                throw new RuntimeException('OpsDeck local storage is not configured.');
            }
            $publicPath = realpath(public_path()) ?: public_path();
            $rootPath = realpath($disk['root']) ?: $disk['root'];
            if (str_starts_with(strtolower(rtrim($rootPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR), strtolower(rtrim($publicPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('OpsDeck local storage must remain outside the public directory.');
            }

            return;
        }

        $expectedBucket = config($isProduction ? 'opsdeck.storage.expected_production_bucket' : 'opsdeck.storage.expected_development_bucket');
        if (($disk['driver'] ?? null) !== 's3'
            || ! is_string($expectedBucket)
            || ! is_string($disk['bucket'] ?? null)
            || trim($disk['bucket']) !== trim($expectedBucket)) {
            throw new RuntimeException('OpsDeck restore target is not its dedicated storage bucket.');
        }
    }
}
