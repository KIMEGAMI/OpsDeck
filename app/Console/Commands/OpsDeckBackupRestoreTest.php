<?php

namespace App\Console\Commands;

use App\Services\BackupHealthService;
use App\Services\BackupRestoreTargetConfiguration;
use App\Services\DatabaseDumpBinaryLocator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Pdo\Mysql;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('opsdeck:backup:restore-test {path? : Backup directory to restore into the dedicated empty test database}')]
#[Description('Restore a MySQL or MariaDB backup into a dedicated empty database, verify it, and remove restored tables')]
class OpsDeckBackupRestoreTest extends Command
{
    /** @var list<string> */
    private const REQUIRED_TABLES = ['migrations', 'users'];

    public function handle(DatabaseDumpBinaryLocator $binaryLocator, BackupHealthService $backupHealthService, BackupRestoreTargetConfiguration $restoreTargetConfiguration): int
    {
        try {
            $exitCode = $this->runRestoreTest($binaryLocator, $backupHealthService, $restoreTargetConfiguration);
        } catch (Throwable) {
            $this->error('Backup restore verification failed unexpectedly.');
            $exitCode = self::FAILURE;
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

    private function runRestoreTest(DatabaseDumpBinaryLocator $binaryLocator, BackupHealthService $backupHealthService, BackupRestoreTargetConfiguration $restoreTargetConfiguration): int
    {
        $backupPath = $this->argument('path');
        if (! is_string($backupPath) || trim($backupPath) === '') {
            $backupPath = $backupHealthService->latestBackupPath();
        }
        if (! is_string($backupPath) || $backupPath === '') {
            $this->error('No backup is available for the restore test.');

            return self::FAILURE;
        }

        $restoreConnectionName = (string) config('opsdeck.backup.restore_connection');
        $restoreConfiguration = config('database.connections.'.$restoreConnectionName);
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
            if ($schema->getTableListing() !== [] || $schema->getViews() !== []) {
                $this->error('The dedicated restore-test database must be empty.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('The dedicated restore-test database is not reachable.');

            return self::FAILURE;
        }

        return $this->restoreAndClean($mysqlClient, $restoreConfiguration, $restoreConnectionName, $backupPath, $manifest, $schema);
    }

    /**
     * @param  array<string, mixed>  $restoreConfiguration
     * @param  array{database: array{driver: string, file: string}}  $manifest
     */
    private function restoreAndClean(string $mysqlClient, array $restoreConfiguration, string $restoreConnectionName, string $backupPath, array $manifest, Builder $schema): int
    {
        $databaseName = (string) $restoreConfiguration['database'];
        $databasePath = $backupPath.DIRECTORY_SEPARATOR.$manifest['database']['file'];
        $input = @fopen($databasePath, 'rb');
        if (! is_resource($input)) {
            $this->error('The verified backup dump could not be opened.');

            return self::FAILURE;
        }

        $command = [$mysqlClient, '--user='.(string) $restoreConfiguration['username'], '--database='.$databaseName, '--default-character-set='.(string) ($restoreConfiguration['charset'] ?? 'utf8mb4')];
        $socket = (string) ($restoreConfiguration['unix_socket'] ?? '');
        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--host='.(string) $restoreConfiguration['host'];
            $command[] = '--port='.(string) $restoreConfiguration['port'];
        }

        $restoreOptions = is_array($restoreConfiguration['options'] ?? null) ? $restoreConfiguration['options'] : [];
        $sslCa = $restoreOptions[Mysql::ATTR_SSL_CA] ?? null;
        if (is_string($sslCa) && $sslCa !== '') {
            $command[] = '--ssl-mode=VERIFY_IDENTITY';
            $command[] = '--ssl-ca='.$sslCa;
        }

        $process = new Process($command);
        $process->setEnv(['MYSQL_PWD' => (string) ($restoreConfiguration['password'] ?? '')]);
        $process->setInput($input);
        $process->disableOutput();
        $process->setTimeout(null);
        $restoreStarted = false;
        $restoreSucceeded = false;
        $cleanupSucceeded = true;

        try {
            $restoreStarted = true;
            $process->run();
            if ($process->isSuccessful()) {
                $restoreConnection = DB::connection($restoreConnectionName);
                $hasRequiredTables = array_all(self::REQUIRED_TABLES, fn (string $table): bool => $schema->hasTable($table));
                $restoreSucceeded = $hasRequiredTables && $restoreConnection->table('migrations')->count() > 0;
            }
        } catch (Throwable) {
            $restoreSucceeded = false;
        } finally {
            fclose($input);
            if ($restoreStarted) {
                try {
                    $schema->dropAllViews();
                    $schema->dropAllTables();
                } catch (Throwable) {
                    $cleanupSucceeded = false;
                }
            }
        }

        DB::disconnect($restoreConnectionName);

        if (! $cleanupSucceeded) {
            $this->error('The restore test failed and cleanup of the dedicated database could not be confirmed.');

            return self::FAILURE;
        }

        if (! $restoreSucceeded) {
            $this->error('The backup could not be restored and verified in the dedicated database.');

            return self::FAILURE;
        }

        $this->info('MySQL backup restore test passed; the dedicated database was cleaned.');

        return self::SUCCESS;
    }
}
