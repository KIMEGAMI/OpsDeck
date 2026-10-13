<?php

namespace App\Console\Commands;

use App\Services\DatabaseDumpBinaryLocator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('opsdeck:backup')]
#[Description('Create a non-destructive database and evidence backup')]
class OpsDeckBackup extends Command
{
    private const RESTRICTIVE_UMASK = 0077;

    private const PRIVATE_DIRECTORY_MODE = 0700;

    /**
     * Execute the console command.
     */
    public function handle(DatabaseDumpBinaryLocator $dumpBinaryLocator): int
    {
        $backupRoot = rtrim((string) config('opsdeck.backup.path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_'.bin2hex(random_bytes(4));
        $previousUmask = DIRECTORY_SEPARATOR === '\\' ? null : umask(self::RESTRICTIVE_UMASK);
        try {
            File::ensureDirectoryExists($backupRoot);
            if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($backupRoot, self::PRIVATE_DIRECTORY_MODE)) {
                throw new RuntimeException('バックアップディレクトリのアクセス権を制限できません。');
            }

            $backupDisk = Storage::build(['driver' => 'local', 'root' => $backupRoot]);
            $databaseBackup = $this->backupDatabase($backupRoot, $dumpBinaryLocator);
            $databaseChecksum = hash_file('sha256', $backupRoot.DIRECTORY_SEPARATOR.$databaseBackup['file']);
            if (! is_string($databaseChecksum)) {
                throw new RuntimeException('DBバックアップのチェックサムを作成できません。');
            }
            $evidenceBackup = $this->backupEvidence($backupDisk);
            $manifest = [
                'created_at' => now()->toIso8601String(),
                'database' => $databaseBackup,
                'database_sha256' => $databaseChecksum,
                'evidence_files' => $evidenceBackup['files'],
                'evidence_sha256' => $evidenceBackup['sha256'],
            ];
            if (! $backupDisk->put('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('バックアップマニフェストを書き込めません。');
            }
            if ($this->call('opsdeck:backup:verify', ['path' => $backupRoot]) !== self::SUCCESS) {
                throw new RuntimeException('バックアップを検証できません。');
            }
        } catch (Throwable) {
            if (File::deleteDirectory($backupRoot)) {
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

    /** @return array{driver: string, file: string} */
    private function backupDatabase(string $backupRoot, DatabaseDumpBinaryLocator $dumpBinaryLocator): array
    {
        $connection = config('database.default');
        $driver = (string) config("database.connections.$connection.driver");
        if ($driver === 'sqlite') {
            $target = $backupRoot.DIRECTORY_SEPARATOR.'database.sqlite';
            $statement = DB::connection($connection)->getPdo()->prepare('VACUUM INTO ?');
            if (! $statement->execute([$target])) {
                throw new RuntimeException('SQLiteバックアップを作成できません。');
            }

            return ['driver' => 'sqlite', 'file' => 'database.sqlite'];
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('対応していないDBドライバーです。');
        }

        $target = $backupRoot.DIRECTORY_SEPARATOR.'database.sql';
        $handle = fopen($target, 'wb');
        if (! is_resource($handle)) {
            throw new RuntimeException('DBバックアップファイルを作成できません。');
        }

        $dumpBinary = $dumpBinaryLocator->find($driver);
        if ($dumpBinary === null) {
            fclose($handle);
            throw new RuntimeException('DBバックアップツールが見つかりません。');
        }
        $command = [$dumpBinary, '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--skip-triggers', '--skip-routines'];
        if ($driver === 'mysql') {
            $command[] = '--set-gtid-purged=OFF';
        }
        $command = [...$command, '--host='.(string) config("database.connections.$connection.host"), '--port='.(string) config("database.connections.$connection.port"), '--user='.(string) config("database.connections.$connection.username"), (string) config("database.connections.$connection.database")];
        $process = new Process($command);
        $process->setEnv(['MYSQL_PWD' => (string) config("database.connections.$connection.password")]);
        try {
            $process->run(function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldumpに失敗しました。');
        }

        return ['driver' => $driver, 'file' => 'database.sql'];
    }

    /** @return array{files: list<string>, sha256: list<string>} */
    private function backupEvidence(FilesystemAdapter $backupDisk): array
    {
        $disk = Storage::disk(config('opsdeck.evidence.disk'));
        $files = [];
        $checksums = [];
        foreach ($disk->allFiles() as $file) {
            $stream = $disk->readStream($file);
            if ($stream === false) {
                throw new RuntimeException('Evidenceバックアップの読み取りに失敗しました。');
            }
            if (! $backupDisk->put('evidence/'.$file, $stream)) {
                fclose($stream);
                throw new RuntimeException('Evidenceバックアップの書き込みに失敗しました。');
            }
            fclose($stream);
            $checksum = hash_file('sha256', $backupDisk->path('evidence/'.$file));
            if (! is_string($checksum)) {
                throw new RuntimeException('Evidenceバックアップの検証に失敗しました。');
            }
            $files[] = $file;
            $checksums[] = $checksum;
        }

        return ['files' => $files, 'sha256' => $checksums];
    }
}
