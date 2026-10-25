<?php

namespace App\Console\Commands;

use App\Models\Evidence;
use App\Models\ImportBatch;
use App\Services\BackupEncryptionService;
use App\Services\BackupStorageIsolation;
use App\Services\DatabaseClientVersionDetector;
use App\Services\DatabaseDumpBinaryLocator;
use App\Services\DatabaseDumpProcessFactory;
use App\Services\DatabaseSchemaFingerprint;
use App\Services\DatabaseTlsConfiguration;
use App\Services\ResolvedDatabaseConfiguration;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('opsdeck:backup')]
#[Description('Create a local database, evidence, and import-source backup')]
class OpsDeckBackup extends Command
{
    private const LOCAL_RETENTION_POLICY_VERSION = 2;

    private const RESTRICTIVE_UMASK = 0077;

    private const PRIVATE_DIRECTORY_MODE = 0700;

    /**
     * Execute the console command.
     */
    public function handle(DatabaseDumpBinaryLocator $dumpBinaryLocator, DatabaseDumpProcessFactory $processFactory, DatabaseClientVersionDetector $clientVersionDetector, DatabaseSchemaFingerprint $schemaFingerprint, DatabaseTlsConfiguration $databaseTls, ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration, BackupEncryptionService $encryption): int
    {
        if (config('opsdeck.backup.remote_enabled') === true
            && ! app(BackupStorageIsolation::class)->allowsRemoteStorageInCurrentTest()) {
            $this->error('Backups are configured for local-only storage. Set OPSDECK_BACKUP_REMOTE_ENABLED=false.');

            return self::FAILURE;
        }

        $connection = (string) config('database.default');
        $encryptionEnabled = config('opsdeck.backup.encryption_enabled') === true;
        $backupRoot = rtrim((string) config('opsdeck.backup.path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_'.bin2hex(random_bytes(4));
        $backupVerified = false;
        $previousUmask = DIRECTORY_SEPARATOR === '\\' ? null : umask(self::RESTRICTIVE_UMASK);
        try {
            File::ensureDirectoryExists($backupRoot);
            if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($backupRoot, self::PRIVATE_DIRECTORY_MODE)) {
                throw new RuntimeException('バックアップディレクトリのアクセス権を制限できません。');
            }

            $backupDisk = Storage::build(['driver' => 'local', 'root' => $backupRoot]);
            $databaseSchemaHashBeforeBackup = null;
            if (in_array(DB::connection($connection)->getDriverName(), ['mysql', 'mariadb'], true)) {
                $databaseSchemaHashBeforeBackup = $schemaFingerprint->fingerprint(DB::connection($connection)->getSchemaBuilder());
            }
            $databaseBackup = $this->backupDatabase($backupRoot, $dumpBinaryLocator, $processFactory, $clientVersionDetector, $databaseTls, $resolvedDatabaseConfiguration, $encryption, $encryptionEnabled);
            if (is_string($databaseSchemaHashBeforeBackup)) {
                $databaseSchemaHashAfterBackup = $schemaFingerprint->fingerprint(DB::connection($connection)->getSchemaBuilder());
                if (! hash_equals($databaseSchemaHashBeforeBackup, $databaseSchemaHashAfterBackup)) {
                    throw new RuntimeException('DBスキーマがバックアップ中に変更されました。');
                }
                $databaseBackup['schema_sha256'] = $databaseSchemaHashBeforeBackup;
                $databaseBackup['schema_fingerprint_version'] = DatabaseSchemaFingerprint::VERSION;
            }
            $evidenceBackup = $this->backupEvidence($backupDisk, $encryption, $encryptionEnabled);
            $importBackup = $this->backupImportSources($backupDisk, $encryption, $encryptionEnabled);
            $databaseManifest = ['driver' => $databaseBackup['driver'], 'file' => $databaseBackup['file']];
            if (isset($databaseBackup['schema_sha256'])) {
                $databaseManifest['schema_sha256'] = $databaseBackup['schema_sha256'];
                $databaseManifest['schema_fingerprint_version'] = $databaseBackup['schema_fingerprint_version'];
            }
            $manifest = [
                'created_at' => now()->toIso8601String(),
                'database' => $databaseManifest,
                'database_sha256' => $databaseBackup['sha256'],
                'evidence_files' => $evidenceBackup['files'],
                'evidence_sha256' => $evidenceBackup['sha256'],
                'evidence_index_file' => $evidenceBackup['index_file'],
                'evidence_index_sha256' => $evidenceBackup['index_sha256'],
                'import_files' => $importBackup['files'],
                'import_sha256' => $importBackup['sha256'],
                'import_sources_index_file' => $importBackup['index_file'],
                'import_sources_index_sha256' => $importBackup['index_sha256'],
                'payload_size_bytes' => $databaseBackup['size_bytes'] + $evidenceBackup['size_bytes'] + $importBackup['size_bytes'],
                'local_retention_eligible' => true,
                'local_retention_policy_version' => self::LOCAL_RETENTION_POLICY_VERSION,
            ];
            if ($encryptionEnabled) {
                $manifest['encryption'] = [
                    'algorithm' => BackupEncryptionService::ALGORITHM,
                    'format_version' => BackupEncryptionService::FORMAT_VERSION,
                ];
            } else {
                $manifest['manifest_hmac_key_source'] = 'application_key_v1';
            }
            $manifest['manifest_hmac_sha256'] = $encryption->manifestHmac($manifest);
            if (! $backupDisk->put('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('バックアップマニフェストを書き込めません。');
            }
            if ($this->call('opsdeck:backup:verify', ['path' => $backupRoot]) !== self::SUCCESS) {
                throw new RuntimeException('バックアップを検証できません。');
            }
            $backupVerified = true;
            $this->publishRemoteBackup($backupRoot);
        } catch (Throwable) {
            if ($backupVerified) {
                $this->error('Backup failed after local verification. The verified local backup was preserved.');
            } elseif (File::deleteDirectory($backupRoot)) {
                $this->error('Backup failed. Incomplete backup data was removed.');
            } else {
                $this->error('Backup failed. Cleanup of incomplete backup data could not be confirmed.');
            }

            return self::FAILURE;
        } finally {
            if (is_int($previousUmask)) {
                umask($previousUmask);
            }
        }
        $this->info('Backup created: '.$backupRoot);

        return self::SUCCESS;
    }

    private function publishRemoteBackup(string $backupRoot): void
    {
        $remoteEnabled = (bool) config('opsdeck.backup.remote_enabled');
        $backupName = basename($backupRoot);
        if (! $remoteEnabled) {
            $this->pruneExpiredLocalBackups(
                null,
                '',
                (int) config('opsdeck.backup.local_retention_days', 30),
                $backupName,
            );

            return;
        }

        $diskName = trim((string) config('opsdeck.backup.remote_disk'));
        $prefix = trim((string) config('opsdeck.backup.remote_prefix'), '/');
        $retentionDays = config('opsdeck.backup.remote_retention_days');
        $diskConfiguration = config('filesystems.disks.'.$diskName);
        if ($diskName === ''
            || ! is_array($diskConfiguration)
            || ($diskConfiguration['driver'] ?? null) !== 's3'
            || ! is_string($diskConfiguration['key'] ?? null)
            || trim($diskConfiguration['key']) === ''
            || ! is_string($diskConfiguration['secret'] ?? null)
            || trim($diskConfiguration['secret']) === ''
            || ! is_string($diskConfiguration['bucket'] ?? null)
            || trim($diskConfiguration['bucket']) === ''
            || ! is_string($diskConfiguration['endpoint'] ?? null)
            || trim($diskConfiguration['endpoint']) === ''
            || filter_var($diskConfiguration['endpoint'], FILTER_VALIDATE_URL) === false
            || parse_url($diskConfiguration['endpoint'], PHP_URL_SCHEME) !== 'https'
            || ! app(BackupStorageIsolation::class)->isDedicated($diskConfiguration)
            || ! is_int($retentionDays)
            || $retentionDays < 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9\/_-]*\z/', $prefix) !== 1
            || str_contains($prefix, '//')
            || preg_match('/(?:\A|\/)\.{1,2}(?:\/|\z)/', $prefix) === 1
            || preg_match('/\A\d{8}_\d{6}_[a-f0-9]{8}\z/', $backupName) !== 1) {
            throw new RuntimeException('外部バックアップの設定が不足または不正です。');
        }

        $remoteDisk = Storage::disk($diskName);
        $remoteDirectory = $prefix.'/'.$backupName;
        $files = File::allFiles($backupRoot);
        usort($files, static function (SplFileInfo $left, SplFileInfo $right): int {
            if ($left->getFilename() === 'manifest.json') {
                return 1;
            }
            if ($right->getFilename() === 'manifest.json') {
                return -1;
            }

            return strcmp($left->getPathname(), $right->getPathname());
        });

        $uploaded = [];
        try {
            foreach ($files as $file) {
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                $remotePath = $remoteDirectory.'/'.$relativePath;
                $stream = fopen($file->getPathname(), 'rb');
                if (! is_resource($stream)) {
                    throw new RuntimeException('外部バックアップの読み取りに失敗しました。');
                }

                try {
                    if (! $remoteDisk->put($remotePath, $stream, ['visibility' => 'private'])) {
                        throw new RuntimeException('外部バックアップの保存に失敗しました。');
                    }
                } finally {
                    fclose($stream);
                }
                $uploaded[] = $remotePath;

                $remoteStream = $remoteDisk->readStream($remotePath);
                if (! is_resource($remoteStream)) {
                    throw new RuntimeException('外部バックアップの読戻しに失敗しました。');
                }
                $remoteChecksumContext = hash_init('sha256');
                try {
                    hash_update_stream($remoteChecksumContext, $remoteStream);
                } finally {
                    fclose($remoteStream);
                }
                $remoteChecksum = hash_final($remoteChecksumContext);
                $localChecksum = hash_file('sha256', $file->getPathname());
                if (! is_string($localChecksum) || ! hash_equals($localChecksum, $remoteChecksum)) {
                    throw new RuntimeException('外部バックアップの整合性を確認できません。');
                }
            }

            $this->pruneExpiredLocalBackups($remoteDisk, $prefix, (int) config('opsdeck.backup.local_retention_days', 30), $backupName);
            $this->pruneExpiredRemoteBackups($remoteDisk, $prefix, $retentionDays, $backupName);
        } catch (Throwable $exception) {
            foreach ($uploaded as $remotePath) {
                try {
                    $remoteDisk->delete($remotePath);
                } catch (Throwable) {
                    // Keep cleanup failures out of logs because provider errors may contain sensitive request details.
                }
            }

            throw $exception;
        }
    }

    private function pruneExpiredLocalBackups(?FilesystemAdapter $remoteDisk, string $prefix, int $retentionDays, string $currentBackupName): void
    {
        $backupRoot = (string) config('opsdeck.backup.path');
        if (! File::isDirectory($backupRoot) || is_link($backupRoot)) {
            return;
        }

        $cutoff = now()->subDays($retentionDays);
        foreach (File::directories($backupRoot) as $backupDirectory) {
            $backupName = basename($backupDirectory);
            if ($backupName === $currentBackupName
                || is_link($backupDirectory)
                || preg_match('/\A\d{8}_\d{6}_[a-f0-9]{8}\z/', $backupName) !== 1) {
                continue;
            }

            $createdAt = Carbon::createFromFormat('!Ymd_His', substr($backupName, 0, 15));
            if ($createdAt === false || ! $createdAt->lt($cutoff)) {
                continue;
            }

            try {
                $manifestPath = $backupDirectory.DIRECTORY_SEPARATOR.'manifest.json';
                if (! is_file($manifestPath) || is_link($manifestPath)) {
                    continue;
                }
                $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($manifest)
                    || ($manifest['local_retention_eligible'] ?? false) !== true
                    || ($manifest['local_retention_policy_version'] ?? null) !== self::LOCAL_RETENTION_POLICY_VERSION) {
                    continue;
                }
                if ($this->call('opsdeck:backup:verify', ['path' => $backupDirectory]) !== self::SUCCESS) {
                    $this->warn('An expired local backup was retained because local verification failed.');

                    continue;
                }

                if ($remoteDisk instanceof FilesystemAdapter) {
                    $files = File::allFiles($backupDirectory);
                    $remoteDirectory = trim($prefix, '/').'/'.$backupName;
                    $remoteCopyVerified = $files !== [];
                    foreach ($files as $file) {
                        if ($file->isLink() || ! $file->isFile()) {
                            $remoteCopyVerified = false;
                            break;
                        }

                        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                        $remoteStream = $remoteDisk->readStream($remoteDirectory.'/'.$relativePath);
                        if (! is_resource($remoteStream)) {
                            $remoteCopyVerified = false;
                            break;
                        }

                        $remoteChecksumContext = hash_init('sha256');
                        try {
                            hash_update_stream($remoteChecksumContext, $remoteStream);
                        } finally {
                            fclose($remoteStream);
                        }
                        $localChecksum = hash_file('sha256', $file->getPathname());
                        if (! is_string($localChecksum) || ! hash_equals($localChecksum, hash_final($remoteChecksumContext))) {
                            $remoteCopyVerified = false;
                            break;
                        }
                    }

                    if (! $remoteCopyVerified) {
                        $this->warn('An expired local backup was retained because its remote copy could not be verified.');

                        continue;
                    }
                }

                if (! File::deleteDirectory($backupDirectory)) {
                    $this->warn('An expired local backup was retained because local cleanup could not be confirmed.');
                }
            } catch (Throwable) {
                $this->warn('An expired local backup was retained because its safety checks could not be completed.');
            }
        }
    }

    private function pruneExpiredRemoteBackups(FilesystemAdapter $remoteDisk, string $prefix, int $retentionDays, string $currentBackupName): void
    {
        $cutoff = now()->subDays($retentionDays);
        foreach ($remoteDisk->directories($prefix) as $directory) {
            if (! str_starts_with($directory, $prefix.'/')) {
                continue;
            }
            $directoryName = substr($directory, strlen($prefix) + 1);
            if ($directoryName === $currentBackupName
                || preg_match('/\A\d{8}_\d{6}_[a-f0-9]{8}\z/', $directoryName) !== 1) {
                continue;
            }

            $createdAt = Carbon::createFromFormat('!Ymd_His', substr($directoryName, 0, 15));
            if ($createdAt !== false && $createdAt->lt($cutoff)) {
                $remoteDisk->deleteDirectory($directory);
            }
        }
    }

    /** @return array{driver: string, file: string, sha256: string, size_bytes: int, schema_sha256?: string, schema_fingerprint_version?: int} */
    private function backupDatabase(string $backupRoot, DatabaseDumpBinaryLocator $dumpBinaryLocator, DatabaseDumpProcessFactory $processFactory, DatabaseClientVersionDetector $clientVersionDetector, DatabaseTlsConfiguration $databaseTls, ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration, BackupEncryptionService $encryption, bool $encryptionEnabled): array
    {
        $connection = config('database.default');
        $connectionConfiguration = $resolvedDatabaseConfiguration->for((string) $connection);
        $driver = (string) ($connectionConfiguration['driver'] ?? '');

        if ($driver === 'sqlite') {
            $stagingRoot = $backupRoot.DIRECTORY_SEPARATOR.'.staging';
            File::ensureDirectoryExists($stagingRoot);
            if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($stagingRoot, self::PRIVATE_DIRECTORY_MODE)) {
                throw new RuntimeException('バックアップ一時領域のアクセス権を制限できません。');
            }
            $plaintextPath = $stagingRoot.DIRECTORY_SEPARATOR.'database.sqlite';
            $fileName = $encryptionEnabled ? 'database.sqlite.enc' : 'database.sqlite';
            $backupPath = $backupRoot.DIRECTORY_SEPARATOR.$fileName;
            try {
                $statement = DB::connection($connection)->getPdo()->prepare('VACUUM INTO ?');
                if (! $statement->execute([$plaintextPath])) {
                    throw new RuntimeException('SQLiteバックアップを作成できません。');
                }
                if ($encryptionEnabled) {
                    $checksum = $encryption->encryptFile($plaintextPath, $backupPath);
                } else {
                    if (! File::copy($plaintextPath, $backupPath)) {
                        throw new RuntimeException('SQLiteバックアップを作成できません。');
                    }
                    if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($backupPath, 0600)) {
                        throw new RuntimeException('SQLiteバックアップのアクセス権を制限できません。');
                    }
                    $checksum = hash_file('sha256', $backupPath);
                    if (! is_string($checksum)) {
                        throw new RuntimeException('SQLiteバックアップを検証できません。');
                    }
                }
            } finally {
                if (File::isDirectory($stagingRoot) && ! File::deleteDirectory($stagingRoot)) {
                    throw new RuntimeException('バックアップ一時データを削除できません。');
                }
            }

            return ['driver' => 'sqlite', 'file' => $fileName, 'sha256' => $checksum, 'size_bytes' => (int) File::size($backupPath)];
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('対応していないDBドライバーです。');
        }
        $dumpBinary = $dumpBinaryLocator->find($driver);
        if ($dumpBinary === null) {
            throw new RuntimeException('DBバックアップツールが見つかりません。');
        }
        $clientVendor = $clientVersionDetector->detect($dumpBinary);
        $command = [$dumpBinary, '--single-transaction', '--skip-lock-tables', '--skip-add-locks', '--no-tablespaces', '--skip-triggers', '--skip-routines'];
        $command = [...$command, ...$databaseTls->clientOptions($connectionConfiguration, $clientVendor)];
        if ($clientVendor === 'mysql') {
            $command[] = '--set-gtid-purged=OFF';
        }
        $command = [...$command, '--host='.(string) ($connectionConfiguration['host'] ?? ''), '--port='.(string) ($connectionConfiguration['port'] ?? ''), '--user='.(string) ($connectionConfiguration['username'] ?? ''), (string) ($connectionConfiguration['database'] ?? '')];
        $process = $processFactory->create($command);
        $process->setEnv(['MYSQL_PWD' => (string) ($connectionConfiguration['password'] ?? '')]);
        $fileName = $encryptionEnabled ? 'database.sql.enc' : 'database.sql';
        $backupPath = $backupRoot.DIRECTORY_SEPARATOR.$fileName;
        $produceDump = function (Closure $writeChunk) use ($process): void {
            $process->run(function (string $type, string $buffer) use ($writeChunk): void {
                if ($type === Process::OUT) {
                    $writeChunk($buffer);
                }
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException('mysqldumpに失敗しました。');
            }
        };
        $checksum = $encryptionEnabled
            ? $encryption->encryptFromCallback($backupPath, $produceDump)
            : $this->writePlaintextChunks($backupPath, $produceDump);

        return ['driver' => $driver, 'file' => $fileName, 'sha256' => $checksum, 'size_bytes' => (int) File::size($backupPath)];
    }

    /** @return array{files: list<string>, sha256: list<string>, index_file: string, index_sha256: string, size_bytes: int} */
    private function backupEvidence(FilesystemAdapter $backupDisk, BackupEncryptionService $encryption, bool $encryptionEnabled): array
    {
        $files = [];
        $checksums = [];
        $sourceIndex = [];
        $seenSources = [];
        $sizeBytes = 0;

        $evidenceRecords = Schema::hasTable('evidence')
            ? Evidence::query()->where('storage_path', '<>', 'metadata-only')->orderBy('id')->cursor()
            : [];
        foreach ($evidenceRecords as $evidence) {
            $diskName = (string) $evidence->storage_disk;
            $sourcePath = (string) $evidence->storage_path;
            $sourceKey = hash('sha256', $diskName."\0".$sourcePath);
            if (isset($seenSources[$sourceKey])) {
                continue;
            }
            $seenSources[$sourceKey] = true;

            $sourceDisk = Storage::disk($diskName);
            $stream = $sourceDisk->readStream($sourcePath);
            if (! is_resource($stream)) {
                throw new RuntimeException('Evidenceバックアップの読み取りに失敗しました。');
            }

            $identifier = sprintf('%08d', count($files) + 1);
            $destination = $backupDisk->path('evidence/'.$identifier.($encryptionEnabled ? '.enc' : ''));
            File::ensureDirectoryExists(dirname($destination));
            try {
                $checksum = $encryptionEnabled
                    ? $encryption->encryptStream($stream, $destination)
                    : $this->storePlaintextStream($stream, $destination);
            } finally {
                fclose($stream);
            }
            $files[] = $identifier;
            $checksums[] = $checksum;
            $sourceIndex[] = ['disk' => $diskName, 'path' => $sourcePath];
            $sizeBytes += (int) File::size($destination);
        }

        $indexFile = $encryptionEnabled ? BackupEncryptionService::EVIDENCE_INDEX_FILE : 'evidence-index.json';
        $indexPath = $backupDisk->path($indexFile);
        $indexContents = json_encode($sourceIndex, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $indexChecksum = $encryptionEnabled
            ? $encryption->encryptString($indexContents, $indexPath)
            : $this->writePlaintextChunks($indexPath, static function (Closure $writeChunk) use ($indexContents): void {
                $writeChunk($indexContents);
            });
        $sizeBytes += (int) File::size($indexPath);

        return ['files' => $files, 'sha256' => $checksums, 'index_file' => $indexFile, 'index_sha256' => $indexChecksum, 'size_bytes' => $sizeBytes];
    }

    /** @return array{files: list<string>, sha256: list<string>, index_file: string, index_sha256: string, size_bytes: int} */
    private function backupImportSources(FilesystemAdapter $backupDisk, BackupEncryptionService $encryption, bool $encryptionEnabled): array
    {
        $files = [];
        $checksums = [];
        $sourceIndex = [];
        $seenSources = [];
        $sizeBytes = 0;

        $batches = Schema::hasTable('import_batches')
            ? ImportBatch::query()->whereIn('source_type', ['csv', 'xlsx'])->whereNotNull('source_file_path')->orderBy('id')->cursor()
            : [];
        foreach ($batches as $batch) {
            $diskName = $batch->sourceStorageDiskName();
            $sourcePath = (string) $batch->source_file_path;
            $sourceKey = hash('sha256', $diskName."\0".$sourcePath);
            if (isset($seenSources[$sourceKey])) {
                continue;
            }
            $seenSources[$sourceKey] = true;

            $sourceDisk = Storage::disk($diskName);
            $stream = $sourceDisk->readStream($sourcePath);
            if (! is_resource($stream)) {
                throw new RuntimeException('Import元ファイルのバックアップ読み取りに失敗しました。');
            }

            $identifier = sprintf('%08d', count($files) + 1);
            $destination = $backupDisk->path('imports/'.$identifier.($encryptionEnabled ? '.enc' : ''));
            File::ensureDirectoryExists(dirname($destination));
            try {
                $checksum = $encryptionEnabled
                    ? $encryption->encryptStream($stream, $destination)
                    : $this->storePlaintextStream($stream, $destination);
            } finally {
                fclose($stream);
            }

            $files[] = $identifier;
            $checksums[] = $checksum;
            $sourceIndex[] = ['disk' => $diskName, 'path' => $sourcePath];
            $sizeBytes += (int) File::size($destination);
        }

        $indexFile = $encryptionEnabled ? 'import_sources_index.enc' : 'import_sources_index.json';
        $indexPath = $backupDisk->path($indexFile);
        $indexContents = json_encode($sourceIndex, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $indexChecksum = $encryptionEnabled
            ? $encryption->encryptString($indexContents, $indexPath)
            : $this->writePlaintextChunks($indexPath, static function (Closure $writeChunk) use ($indexContents): void {
                $writeChunk($indexContents);
            });
        $sizeBytes += (int) File::size($indexPath);

        return ['files' => $files, 'sha256' => $checksums, 'index_file' => $indexFile, 'index_sha256' => $indexChecksum, 'size_bytes' => $sizeBytes];
    }

    private function storePlaintextStream(mixed $source, string $destinationPath): string
    {
        return $this->writePlaintextChunks($destinationPath, function (Closure $writeChunk) use ($source): void {
            while (! feof($source)) {
                $chunk = fread($source, BackupEncryptionService::CHUNK_BYTES);
                if (! is_string($chunk)) {
                    throw new RuntimeException('バックアップ対象を読み取れません。');
                }
                if ($chunk === '') {
                    break;
                }

                $writeChunk($chunk);
            }
        });
    }

    private function writePlaintextChunks(string $destinationPath, Closure $produceChunks): string
    {
        $destination = @fopen($destinationPath, 'xb');
        if (! is_resource($destination)) {
            throw new RuntimeException('平文バックアップを作成できません。');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($destinationPath, 0600)) {
            fclose($destination);
            @unlink($destinationPath);

            throw new RuntimeException('平文バックアップのアクセス権を制限できません。');
        }

        $writeChunk = function (string $contents) use ($destination): void {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($destination, substr($contents, $offset));
                if (! is_int($written) || $written === 0) {
                    throw new RuntimeException('平文バックアップを書き込めません。');
                }
                $offset += $written;
            }
        };

        try {
            $produceChunks($writeChunk);
            if (! fflush($destination)) {
                throw new RuntimeException('平文バックアップを書き込めません。');
            }
        } catch (Throwable $exception) {
            fclose($destination);
            @unlink($destinationPath);

            throw $exception;
        }
        fclose($destination);

        $checksum = hash_file('sha256', $destinationPath);
        if (! is_string($checksum)) {
            @unlink($destinationPath);

            throw new RuntimeException('平文バックアップを検証できません。');
        }

        return $checksum;
    }
}
