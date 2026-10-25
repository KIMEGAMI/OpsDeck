<?php

namespace App\Console\Commands;

use App\Services\BackupEncryptionService;
use App\Services\BackupHealthService;
use App\Services\BackupRestoreCleanupService;
use App\Services\BackupRestoreTargetConfiguration;
use App\Services\BackupStorageIsolation;
use App\Services\DatabaseClientVersionDetector;
use App\Services\DatabaseDumpBinaryLocator;
use App\Services\DatabaseDumpProcessFactory;
use App\Services\DatabaseSchemaFingerprint;
use App\Services\DatabaseTlsConfiguration;
use App\Services\ResolvedDatabaseConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('opsdeck:backup:restore-test {path? : Backup directory to restore into the dedicated empty test database}')]
#[Description('Restore a MySQL or MariaDB backup into a dedicated empty database, verify it, and remove restored tables')]
class OpsDeckBackupRestoreTest extends Command
{
    private const PRIVATE_DIRECTORY_MODE = 0700;

    /** @var list<string> */
    private const REQUIRED_TABLES = ['migrations', 'users'];

    private ?string $stagedRemoteBackupRoot = null;

    public function handle(DatabaseDumpBinaryLocator $binaryLocator, DatabaseDumpProcessFactory $processFactory, DatabaseClientVersionDetector $clientVersionDetector, DatabaseSchemaFingerprint $schemaFingerprint, BackupHealthService $backupHealthService, BackupRestoreTargetConfiguration $restoreTargetConfiguration, DatabaseTlsConfiguration $databaseTls, ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration, BackupRestoreCleanupService $restoreCleanup, BackupEncryptionService $encryption, BackupStorageIsolation $backupStorageIsolation): int
    {
        try {
            $exitCode = $this->runRestoreTest($binaryLocator, $processFactory, $clientVersionDetector, $schemaFingerprint, $backupHealthService, $restoreTargetConfiguration, $databaseTls, $resolvedDatabaseConfiguration, $restoreCleanup, $encryption, $backupStorageIsolation);
        } catch (Throwable) {
            $this->error('Backup restore verification failed unexpectedly.');
            $exitCode = self::FAILURE;
        } finally {
            if (is_string($this->stagedRemoteBackupRoot)) {
                if (! File::deleteDirectory($this->stagedRemoteBackupRoot)) {
                    $this->error('Temporary remote backup data could not be removed.');
                    $exitCode = self::FAILURE;
                }
                $this->stagedRemoteBackupRoot = null;
            }
        }

        try {
            DB::table('backup_restore_checks')->insert([
                'status' => $exitCode === self::SUCCESS ? 'passed' : 'failed',
                'completed_at' => now(),
            ]);
        } catch (Throwable) {
            $this->error('Backup restore result could not be recorded.');

            return self::FAILURE;
        }

        return $exitCode;
    }

    private function runRestoreTest(DatabaseDumpBinaryLocator $binaryLocator, DatabaseDumpProcessFactory $processFactory, DatabaseClientVersionDetector $clientVersionDetector, DatabaseSchemaFingerprint $schemaFingerprint, BackupHealthService $backupHealthService, BackupRestoreTargetConfiguration $restoreTargetConfiguration, DatabaseTlsConfiguration $databaseTls, ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration, BackupRestoreCleanupService $restoreCleanup, BackupEncryptionService $encryption, BackupStorageIsolation $backupStorageIsolation): int
    {
        $backupPath = $this->argument('path');
        $useRemoteBackup = false;
        if (config('opsdeck.backup.remote_enabled') === true
            && ! $backupStorageIsolation->allowsRemoteStorageInCurrentTest()) {
            $this->error('Backups are configured for local-only storage. Set OPSDECK_BACKUP_REMOTE_ENABLED=false.');

            return self::FAILURE;
        }

        if (! is_string($backupPath) || trim($backupPath) === '') {
            if (config('opsdeck.backup.remote_enabled') === true) {
                $useRemoteBackup = true;
            } else {
                $backupPath = $backupHealthService->latestBackupPath();
            }
        }
        if (! $useRemoteBackup && (! is_string($backupPath) || $backupPath === '')) {
            $this->error('No complete backup is available for the restore test.');

            return self::FAILURE;
        }

        $restoreConnectionName = (string) config('opsdeck.backup.restore_connection');
        try {
            $restoreConfiguration = $resolvedDatabaseConfiguration->for($restoreConnectionName);
        } catch (Throwable) {
            $this->error('復元先DB接続設定を読み取れません。');

            return self::FAILURE;
        }
        $defaultConnectionName = (string) config('database.default');

        if (! is_array($restoreConfiguration)
            || $restoreConnectionName === ''
            || $restoreConnectionName === $defaultConnectionName
            || ! $restoreTargetConfiguration->isSafelyConfigured()) {
            $this->error('A dedicated restore-test database is not safely configured.');

            return self::FAILURE;
        }

        try {
            $defaultDatabaseName = (string) DB::connection($defaultConnectionName)->getDatabaseName();
        } catch (Throwable) {
            $this->error('The application database connection could not be confirmed.');

            return self::FAILURE;
        }

        if ($restoreConfiguration['database'] === $defaultDatabaseName) {
            $this->error('A dedicated restore-test database is not safely configured.');

            return self::FAILURE;
        }

        if ($useRemoteBackup) {
            $backupPath = $this->stageLatestRemoteBackup();
            if (! is_string($backupPath) || $backupPath === '') {
                $this->error('No complete remote backup is available for the restore test.');

                return self::FAILURE;
            }
        }

        $backupPath = rtrim($backupPath, DIRECTORY_SEPARATOR);
        if ($this->call('opsdeck:backup:verify', ['path' => $backupPath]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        try {
            $manifest = json_decode(File::get($backupPath.DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->error('The backup manifest could not be read.');

            return self::FAILURE;
        }

        if (! is_array($manifest)
            || ! is_array($manifest['database'] ?? null)
            || ! is_string($manifest['database']['driver'] ?? null)
            || ! is_string($manifest['database']['file'] ?? null)) {
            $this->error('The backup manifest is not valid for a MySQL restore test.');

            return self::FAILURE;
        }

        $isEncrypted = array_key_exists('encryption', $manifest);
        $expectedDatabaseFile = $isEncrypted ? 'database.sql.enc' : 'database.sql';
        if ($manifest['database']['file'] !== $expectedDatabaseFile || ! $encryption->hasValidManifestHmac($manifest)) {
            $this->error('The backup manifest is not authenticated for a MySQL restore test. Create a new verified backup and retry.');

            return self::FAILURE;
        }

        $driver = (string) ($restoreConfiguration['driver'] ?? '');
        if (! in_array($driver, ['mysql', 'mariadb'], true) || ($manifest['database']['driver'] ?? null) !== $driver) {
            $this->error('The restore-test database must use the same MySQL-compatible driver as the backup.');

            return self::FAILURE;
        }

        $mysqlClient = $binaryLocator->findRestoreClient($driver);
        if ($mysqlClient === null) {
            $this->error('The MySQL restore client is not available.');

            return self::FAILURE;
        }

        try {
            $connection = DB::connection($restoreConnectionName);
            $connection->getPdo();
            $schema = $connection->getSchemaBuilder();
            if (! $restoreTargetConfiguration->targetIsEmpty($connection)) {
                $this->error('The dedicated restore-test database must be empty.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('The dedicated restore-test database is not reachable.');

            return self::FAILURE;
        }

        $applicationSchema = DB::connection($defaultConnectionName)->getSchemaBuilder();

        return $this->restoreAndClean($mysqlClient, $restoreConfiguration, $restoreConnectionName, $backupPath, $manifest, $schema, $applicationSchema, $processFactory, $clientVersionDetector, $schemaFingerprint, $databaseTls, $restoreCleanup, $encryption, $isEncrypted);
    }

    private function stageLatestRemoteBackup(): ?string
    {
        $diskName = trim((string) config('opsdeck.backup.remote_disk'));
        $prefix = trim((string) config('opsdeck.backup.remote_prefix'), '/');
        $diskConfiguration = config('filesystems.disks.'.$diskName);
        $retentionDays = config('opsdeck.backup.remote_retention_days');
        if (config('opsdeck.backup.remote_enabled') !== true
            || $diskName === ''
            || ! is_array($diskConfiguration)
            || ($diskConfiguration['driver'] ?? null) !== 's3'
            || ! is_string($diskConfiguration['key'] ?? null)
            || trim($diskConfiguration['key']) === ''
            || ! is_string($diskConfiguration['secret'] ?? null)
            || trim($diskConfiguration['secret']) === ''
            || ! is_string($diskConfiguration['bucket'] ?? null)
            || trim($diskConfiguration['bucket']) === ''
            || ! is_string($diskConfiguration['endpoint'] ?? null)
            || filter_var($diskConfiguration['endpoint'], FILTER_VALIDATE_URL) === false
            || parse_url($diskConfiguration['endpoint'], PHP_URL_SCHEME) !== 'https'
            || ! app(BackupStorageIsolation::class)->isDedicated($diskConfiguration)
            || ! is_int($retentionDays)
            || $retentionDays < 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9\/_-]*\z/', $prefix) !== 1
            || str_contains($prefix, '//')
            || preg_match('/(?:\A|\/)\.{1,2}(?:\/|\z)/', $prefix) === 1) {
            throw new RuntimeException('外部バックアップ設定を確認できません。');
        }

        $remoteDisk = Storage::disk($diskName);
        $remoteDirectories = array_values(array_filter(
            $remoteDisk->directories($prefix),
            static fn (string $directory): bool => str_starts_with($directory, $prefix.'/')
                && preg_match('/\A\d{8}_\d{6}_[a-f0-9]{8}\z/', substr($directory, strlen($prefix) + 1)) === 1,
        ));
        rsort($remoteDirectories, SORT_STRING);
        $remoteDirectory = null;
        foreach ($remoteDirectories as $directory) {
            if ($remoteDisk->exists($directory.'/manifest.json')) {
                $remoteDirectory = $directory;

                break;
            }
        }
        if (! is_string($remoteDirectory)) {
            return null;
        }

        $stagingRoot = storage_path('framework/restore-test-staging/'.Str::uuid());
        File::ensureDirectoryExists($stagingRoot);
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($stagingRoot, self::PRIVATE_DIRECTORY_MODE)) {
            File::deleteDirectory($stagingRoot);

            throw new RuntimeException('復元テスト用一時領域のアクセス権を制限できません。');
        }
        $this->stagedRemoteBackupRoot = $stagingRoot;

        $remoteFiles = $remoteDisk->allFiles($remoteDirectory);
        if (! in_array($remoteDirectory.'/manifest.json', $remoteFiles, true)) {
            throw new RuntimeException('外部バックアップのマニフェストが見つかりません。');
        }

        foreach ($remoteFiles as $remotePath) {
            if (! str_starts_with($remotePath, $remoteDirectory.'/')) {
                throw new RuntimeException('外部バックアップのキーが不正です。');
            }
            $relativePath = substr($remotePath, strlen($remoteDirectory) + 1);
            if (! $this->isAllowedBackupRelativePath($relativePath)) {
                throw new RuntimeException('外部バックアップのファイル名が不正です。');
            }

            $destinationPath = $stagingRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            File::ensureDirectoryExists(dirname($destinationPath));
            $sourceStream = $remoteDisk->readStream($remotePath);
            if (! is_resource($sourceStream)) {
                throw new RuntimeException('外部バックアップを読み取れません。');
            }
            $destinationStream = fopen($destinationPath, 'xb');
            if (! is_resource($destinationStream)) {
                fclose($sourceStream);

                throw new RuntimeException('復元テスト用一時ファイルを作成できません。');
            }

            try {
                if (stream_copy_to_stream($sourceStream, $destinationStream) === false) {
                    throw new RuntimeException('外部バックアップを一時領域へコピーできません。');
                }
            } finally {
                fclose($sourceStream);
                fclose($destinationStream);
            }
        }

        return $stagingRoot;
    }

    private function isAllowedBackupRelativePath(string $path): bool
    {
        return in_array($path, [
            'database.sqlite.enc',
            'database.sqlite',
            'database.sql.enc',
            'database.sql',
            BackupEncryptionService::EVIDENCE_INDEX_FILE,
            'evidence-index.json',
            'import_sources_index.enc',
            'import_sources_index.json',
            'manifest.json',
        ], true)
            || preg_match('/\A(?:evidence|imports)\/[0-9]{8}(?:\.enc)?\z/', $path) === 1;
    }

    /**
     * @param  array<string, mixed>  $restoreConfiguration
     * @param  array{database: array{driver: string, file: string, schema_sha256?: string}}  $manifest
     */
    private function restoreAndClean(string $mysqlClient, array $restoreConfiguration, string $restoreConnectionName, string $backupPath, array $manifest, Builder $schema, Builder $applicationSchema, DatabaseDumpProcessFactory $processFactory, DatabaseClientVersionDetector $clientVersionDetector, DatabaseSchemaFingerprint $schemaFingerprint, DatabaseTlsConfiguration $databaseTls, BackupRestoreCleanupService $restoreCleanup, BackupEncryptionService $encryption, bool $isEncrypted): int
    {
        $databaseName = (string) $restoreConfiguration['database'];
        $databasePath = $backupPath.DIRECTORY_SEPARATOR.$manifest['database']['file'];
        $command = [$mysqlClient, '--user='.(string) $restoreConfiguration['username'], '--database='.$databaseName, '--default-character-set='.(string) ($restoreConfiguration['charset'] ?? 'utf8mb4')];
        $socket = (string) ($restoreConfiguration['unix_socket'] ?? '');
        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--host='.(string) $restoreConfiguration['host'];
            $command[] = '--port='.(string) $restoreConfiguration['port'];
        }

        $clientVendor = $clientVersionDetector->detect($mysqlClient);
        $command = [...$command, ...$databaseTls->clientOptions($restoreConfiguration, $clientVendor)];

        $snapshotPath = $encryption->createVerifiedSnapshot($databasePath, (string) ($manifest['database_sha256'] ?? ''));
        if (! is_string($snapshotPath)) {
            $this->error('The verified backup dump could not be snapshotted.');

            return self::FAILURE;
        }

        $input = null;
        $process = null;
        $restoreStarted = false;
        $restoreSucceeded = false;
        $cleanupSucceeded = true;
        $failureCategory = null;

        try {
            $input = $isEncrypted
                ? $encryption->decryptedChunks($snapshotPath)
                : $encryption->plaintextChunks($snapshotPath);
            $process = $processFactory->create($command);
            $process->setEnv(['MYSQL_PWD' => (string) ($restoreConfiguration['password'] ?? '')]);
            $process->setInput($input);
            $process->disableOutput();
            $restoreStarted = true;
            $process->run();
            if ($process->isSuccessful()) {
                $restoreConnection = DB::connection($restoreConnectionName);
                $hasRequiredTables = array_all(self::REQUIRED_TABLES, fn (string $table): bool => $schema->hasTable($table));
                $hasMigrationRecords = $hasRequiredTables && $restoreConnection->table('migrations')->count() > 0;
                $expectedSchemaFingerprint = $manifest['database']['schema_sha256'] ?? null;
                $schemaFingerprintVersion = $manifest['database']['schema_fingerprint_version'] ?? DatabaseSchemaFingerprint::PREVIOUS_VERSION;
                $hasMatchingSchema = true;
                if (is_string($expectedSchemaFingerprint)) {
                    if ($schemaFingerprintVersion === DatabaseSchemaFingerprint::VERSION) {
                        $hasMatchingSchema = $schemaFingerprint->matches($schema, $expectedSchemaFingerprint);
                        if (! $hasMatchingSchema) {
                            $failureCategory = 'schema_fingerprint_mismatch';
                        }
                    } elseif ($schemaFingerprintVersion === DatabaseSchemaFingerprint::PREVIOUS_VERSION) {
                        $backupMatchesApplicationSchema = $schemaFingerprint->matchesLegacy($applicationSchema, $expectedSchemaFingerprint);
                        $restoreMatchesApplicationSchema = hash_equals($schemaFingerprint->fingerprint($applicationSchema), $schemaFingerprint->fingerprint($schema));
                        $hasMatchingSchema = $backupMatchesApplicationSchema && $restoreMatchesApplicationSchema;
                        if (! $backupMatchesApplicationSchema) {
                            $failureCategory = 'legacy_backup_schema_differs_from_application';
                        } elseif (! $restoreMatchesApplicationSchema) {
                            $failureCategory = 'restored_schema_differs_from_application';
                        }
                    } else {
                        $hasMatchingSchema = false;
                        $failureCategory = 'unsupported_schema_fingerprint_version';
                    }
                }
                $restoreSucceeded = $hasMigrationRecords && $hasMatchingSchema;
                if (! $hasRequiredTables) {
                    $failureCategory = 'required_tables_missing';
                } elseif (! $hasMigrationRecords) {
                    $failureCategory = 'migration_records_missing';
                }
            } else {
                $exitCode = $process->getExitCode();
                $failureCategory = 'database_client_failed_exit_'.($exitCode ?? 'unknown');
            }
        } catch (Throwable) {
            $restoreSucceeded = false;
            $failureCategory = 'verification_query_failed';
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            $snapshotCleanupSucceeded = @unlink($snapshotPath);
            if ($restoreStarted) {
                $cleanupSucceeded = $restoreCleanup->clean($schema) && $snapshotCleanupSucceeded;
            } else {
                $cleanupSucceeded = $snapshotCleanupSucceeded;
            }
        }

        DB::disconnect($restoreConnectionName);

        if (! $cleanupSucceeded) {
            $this->error('The restore test failed and cleanup of temporary restore data or the dedicated database could not be confirmed.');

            return self::FAILURE;
        }

        if (! $restoreSucceeded) {
            $this->error('Backup restore verification failed: '.($failureCategory ?? 'unknown').'. Raw database output is hidden to protect imported data.');

            return self::FAILURE;
        }

        $this->info('MySQL backup restore test passed; the dedicated database was cleaned.');

        return self::SUCCESS;
    }
}
