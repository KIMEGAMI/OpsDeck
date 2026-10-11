<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

#[Signature('opsdeck:backup:verify {path : Backup directory to verify}')]
#[Description('Verify a non-destructive OpsDeck backup')]
class OpsDeckBackupVerify extends Command
{
    public function handle(): int
    {
        $backupPath = rtrim((string) $this->argument('path'), DIRECTORY_SEPARATOR);
        $manifestPath = $backupPath.DIRECTORY_SEPARATOR.'manifest.json';
        if (! File::exists($manifestPath)) {
            $this->error('manifest.jsonがありません。');

            return self::FAILURE;
        }
        try {
            /** @var array{database: array{driver: string, file: string}, database_sha256?: string, evidence_files: list<string>, evidence_sha256?: list<string>} $manifest */
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->error('manifest.jsonを読み込めません。');

            return self::FAILURE;
        }
        if (! $this->hasValidManifestStructure($manifest)) {
            $this->error('manifest.jsonの形式が不正です。');

            return self::FAILURE;
        }
        if (! in_array($manifest['database']['driver'], ['sqlite', 'mysql', 'mariadb'], true)) {
            $this->error('DBドライバーが対応していません。');

            return self::FAILURE;
        }
        if (! $this->isSafeRelativePath($manifest['database']['file'])) {
            $this->error('DBバックアップのパスが不正です。');

            return self::FAILURE;
        }
        $databasePath = $backupPath.DIRECTORY_SEPARATOR.$manifest['database']['file'];
        if (! File::exists($databasePath) || File::size($databasePath) === 0) {
            $this->error('DBバックアップがありません。');

            return self::FAILURE;
        }
        if (isset($manifest['database_sha256'])) {
            $actualDatabaseChecksum = hash_file('sha256', $databasePath);
            if (! is_string($actualDatabaseChecksum) || ! hash_equals($manifest['database_sha256'], $actualDatabaseChecksum)) {
                $this->error('DBバックアップの整合性検証に失敗しました。');

                return self::FAILURE;
            }
        }
        if ($manifest['database']['driver'] === 'sqlite' && ! $this->sqliteIsValid($databasePath)) {
            $this->error('SQLiteバックアップの整合性検証に失敗しました。');

            return self::FAILURE;
        }
        foreach ($manifest['evidence_files'] as $index => $file) {
            if (! $this->isSafeRelativePath($file)) {
                $this->error('Evidenceバックアップのパスが不正です。');

                return self::FAILURE;
            }
            $evidencePath = $backupPath.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.$file;
            if (! File::exists($evidencePath)) {
                $this->error('Evidenceバックアップがありません。');

                return self::FAILURE;
            }
            if (isset($manifest['evidence_sha256'])) {
                $actualChecksum = hash_file('sha256', $evidencePath);
                if (! is_string($actualChecksum) || ! hash_equals($manifest['evidence_sha256'][$index], $actualChecksum)) {
                    $this->error('Evidenceバックアップの整合性検証に失敗しました。');

                    return self::FAILURE;
                }
            }
        }
        $this->info('Backup verification passed.');

        return self::SUCCESS;
    }

    private function isSafeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return $normalized !== ''
            && ! str_starts_with($normalized, '/')
            && ! (isset($normalized[1]) && $normalized[1] === ':')
            && ! in_array('..', explode('/', $normalized), true);
    }

    private function hasValidManifestStructure(mixed $manifest): bool
    {
        if (! is_array($manifest) || ! isset($manifest['database'], $manifest['evidence_files']) || ! is_array($manifest['database']) || ! is_array($manifest['evidence_files'])) {
            return false;
        }

        $hasValidEvidenceChecksums = ! array_key_exists('evidence_sha256', $manifest)
            || (is_array($manifest['evidence_sha256'])
                && array_is_list($manifest['evidence_sha256'])
                && count($manifest['evidence_sha256']) === count($manifest['evidence_files'])
                && array_all($manifest['evidence_sha256'], fn (mixed $value): bool => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1));
        $hasValidDatabaseChecksum = ! array_key_exists('database_sha256', $manifest)
            || (is_string($manifest['database_sha256']) && preg_match('/\A[a-f0-9]{64}\z/', $manifest['database_sha256']) === 1);

        return $hasValidDatabaseChecksum
            && $hasValidEvidenceChecksums
            && isset($manifest['database']['driver'], $manifest['database']['file'])
            && is_string($manifest['database']['driver'])
            && is_string($manifest['database']['file'])
            && array_all($manifest['evidence_files'], fn (mixed $value): bool => is_string($value));
    }

    private function sqliteIsValid(string $databasePath): bool
    {
        try {
            $pdo = new PDO('sqlite:'.$databasePath);
            $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();

            return $result === 'ok';
        } catch (Throwable) {
            return false;
        }
    }
}
