<?php

namespace App\Console\Commands;

use App\Services\BackupEncryptionService;
use App\Services\DatabaseSchemaFingerprint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Throwable;

#[Signature('opsdeck:backup:verify {path : Backup directory to verify}')]
#[Description('Verify a non-destructive OpsDeck backup')]
class OpsDeckBackupVerify extends Command
{
    public function handle(BackupEncryptionService $encryption): int
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

        if (array_key_exists('manifest_hmac_sha256', $manifest) && ! $encryption->hasValidManifestHmac($manifest)) {
            $this->error('バックアップマニフェストの認証に失敗しました。');

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
        $isEncrypted = array_key_exists('encryption', $manifest);
        try {
            if ($isEncrypted) {
                if (! $this->hasValidEncryptionManifest($manifest)) {
                    $this->error('暗号化バックアップのマニフェストが不正です。');

                    return self::FAILURE;
                }
                $evidenceIndexPath = $backupPath.DIRECTORY_SEPARATOR.$manifest['evidence_index_file'];
                $evidenceIndexChecksum = hash_file('sha256', $evidenceIndexPath);
                if (! is_string($evidenceIndexChecksum) || ! hash_equals($manifest['evidence_index_sha256'], $evidenceIndexChecksum)) {
                    $this->error('Evidence一覧の整合性検証に失敗しました。');

                    return self::FAILURE;
                }
                $evidenceIndex = '';
                foreach ($encryption->decryptedChunks($evidenceIndexPath) as $chunk) {
                    $evidenceIndex .= $chunk;
                }
                $decodedEvidenceIndex = json_decode($evidenceIndex, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decodedEvidenceIndex)
                    || ! array_is_list($decodedEvidenceIndex)
                    || count($decodedEvidenceIndex) !== count($manifest['evidence_files'])
                    || ! array_all($decodedEvidenceIndex, fn (mixed $source): bool => $this->isValidEvidenceIndexEntry($source))) {
                    $this->error('Evidence一覧の形式が不正です。');

                    return self::FAILURE;
                }
                if ($manifest['database']['driver'] === 'sqlite') {
                    $sqliteVerification = $this->verifyEncryptedSqliteBackup($databasePath, $encryption);
                    if (! $sqliteVerification['cleanup_succeeded']) {
                        $this->error('復号した一時バックアップを削除できません。');

                        return self::FAILURE;
                    }
                    if (! $sqliteVerification['integrity_valid']) {
                        $this->error('SQLiteバックアップの整合性検証に失敗しました。');

                        return self::FAILURE;
                    }
                } else {
                    foreach ($encryption->decryptedChunks($databasePath) as $_chunk) {
                    }
                }
            } elseif ($manifest['database']['driver'] === 'sqlite' && ! $this->sqliteIsValid($databasePath)) {
                $this->error('SQLiteバックアップの整合性検証に失敗しました。');

                return self::FAILURE;
            }

            if (! $isEncrypted && isset($manifest['evidence_index_file'], $manifest['evidence_index_sha256'])) {
                $evidenceIndexPath = $backupPath.DIRECTORY_SEPARATOR.$manifest['evidence_index_file'];
                $actualEvidenceIndexChecksum = hash_file('sha256', $evidenceIndexPath);
                if (! is_string($actualEvidenceIndexChecksum) || ! hash_equals($manifest['evidence_index_sha256'], $actualEvidenceIndexChecksum)) {
                    $this->error('Evidence一覧の整合性検証に失敗しました。');

                    return self::FAILURE;
                }
                $decodedEvidenceIndex = json_decode(File::get($evidenceIndexPath), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decodedEvidenceIndex)
                    || ! array_is_list($decodedEvidenceIndex)
                    || count($decodedEvidenceIndex) !== count($manifest['evidence_files'])
                    || ! array_all($decodedEvidenceIndex, fn (mixed $source): bool => $this->isValidEvidenceIndexEntry($source))) {
                    $this->error('Evidence一覧の形式が不正です。');

                    return self::FAILURE;
                }
            }

            foreach ($manifest['evidence_files'] as $index => $file) {
                if (! $this->isSafeRelativePath($file)) {
                    $this->error('Evidenceバックアップのパスが不正です。');

                    return self::FAILURE;
                }
                $evidencePath = $backupPath.DIRECTORY_SEPARATOR.'evidence'.DIRECTORY_SEPARATOR.($isEncrypted ? $file.'.enc' : $file);
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
                if ($isEncrypted) {
                    foreach ($encryption->decryptedChunks($evidencePath) as $_chunk) {
                    }
                }
            }
            if (isset($manifest['import_files'])) {
                $importIndexPath = $backupPath.DIRECTORY_SEPARATOR.$manifest['import_sources_index_file'];
                $actualImportIndexChecksum = hash_file('sha256', $importIndexPath);
                if (! is_string($actualImportIndexChecksum) || ! hash_equals($manifest['import_sources_index_sha256'], $actualImportIndexChecksum)) {
                    $this->error('Import元ファイル一覧の整合性検証に失敗しました。');

                    return self::FAILURE;
                }
                $importIndexContents = $this->readBackupContents($importIndexPath, $isEncrypted, $encryption);
                $importIndex = json_decode($importIndexContents, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($importIndex)
                    || ! array_is_list($importIndex)
                    || count($importIndex) !== count($manifest['import_files'])
                    || ! array_all($importIndex, fn (mixed $source): bool => is_array($source)
                        && is_string($source['disk'] ?? null)
                        && $source['disk'] !== ''
                        && is_string($source['path'] ?? null)
                        && $this->isSafeRelativePath($source['path']))) {
                    $this->error('Import元ファイル一覧の形式が不正です。');

                    return self::FAILURE;
                }
                foreach ($manifest['import_files'] as $index => $file) {
                    if (! $this->isSafeRelativePath($file)) {
                        $this->error('Import元ファイルのパスが不正です。');

                        return self::FAILURE;
                    }
                    $importPath = $backupPath.DIRECTORY_SEPARATOR.'imports'.DIRECTORY_SEPARATOR.$file.($isEncrypted ? '.enc' : '');
                    $actualChecksum = hash_file('sha256', $importPath);
                    if (! is_string($actualChecksum) || ! hash_equals($manifest['import_sha256'][$index], $actualChecksum)) {
                        $this->error('Import元ファイルの整合性検証に失敗しました。');

                        return self::FAILURE;
                    }
                    if ($isEncrypted) {
                        foreach ($encryption->decryptedChunks($importPath) as $_chunk) {
                        }
                    }
                }
            }
        } catch (Throwable) {
            $this->error($isEncrypted ? 'バックアップの暗号化認証に失敗しました。' : 'バックアップ内容の検証に失敗しました。');

            return self::FAILURE;
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

    private function isValidEvidenceIndexEntry(mixed $source): bool
    {
        if (is_string($source)) {
            return $this->isSafeRelativePath($source);
        }

        return is_array($source)
            && is_string($source['disk'] ?? null)
            && preg_match('/\A[a-zA-Z0-9_.-]+\z/', $source['disk']) === 1
            && is_string($source['path'] ?? null)
            && $this->isSafeRelativePath($source['path']);
    }

    private function readBackupContents(string $path, bool $isEncrypted, BackupEncryptionService $encryption): string
    {
        if (! $isEncrypted) {
            return File::get($path);
        }

        $contents = '';
        foreach ($encryption->decryptedChunks($path) as $chunk) {
            $contents .= $chunk;
        }

        return $contents;
    }

    /** @return array{integrity_valid: bool, cleanup_succeeded: bool} */
    private function verifyEncryptedSqliteBackup(string $databasePath, BackupEncryptionService $encryption): array
    {
        $temporaryPlaintextPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-verify-'.Str::uuid().'.sqlite';
        $integrityValid = false;
        $cleanupSucceeded = true;

        try {
            $encryption->decryptFile($databasePath, $temporaryPlaintextPath);
            $integrityValid = $this->sqliteIsValid($temporaryPlaintextPath);
        } finally {
            if (File::exists($temporaryPlaintextPath) && ! File::delete($temporaryPlaintextPath)) {
                $cleanupSucceeded = false;
            }
        }

        return ['integrity_valid' => $integrityValid, 'cleanup_succeeded' => $cleanupSucceeded];
    }

    private function hasValidManifestStructure(mixed $manifest): bool
    {
        if (! is_array($manifest) || ! isset($manifest['database'], $manifest['evidence_files']) || ! is_array($manifest['database']) || ! is_array($manifest['evidence_files'])) {
            return false;
        }

        $isEncrypted = array_key_exists('encryption', $manifest);
        $hasValidEvidenceChecksums = ! array_key_exists('evidence_sha256', $manifest)
            || (is_array($manifest['evidence_sha256'])
                && array_is_list($manifest['evidence_sha256'])
                && count($manifest['evidence_sha256']) === count($manifest['evidence_files'])
                && array_all($manifest['evidence_sha256'], fn (mixed $value): bool => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1));
        $hasValidDatabaseChecksum = ! array_key_exists('database_sha256', $manifest)
            || (is_string($manifest['database_sha256']) && preg_match('/\A[a-f0-9]{64}\z/', $manifest['database_sha256']) === 1);
        $hasValidImportFiles = ! array_key_exists('import_files', $manifest)
            || (is_array($manifest['import_files'])
                && array_is_list($manifest['import_files'])
                && array_all($manifest['import_files'], fn (mixed $file): bool => is_string($file) && preg_match('/\A[0-9]{8}\z/', $file) === 1)
                && count(array_unique($manifest['import_files'])) === count($manifest['import_files'])
                && is_array($manifest['import_sha256'] ?? null)
                && array_is_list($manifest['import_sha256'])
                && count($manifest['import_sha256']) === count($manifest['import_files'])
                && array_all($manifest['import_sha256'], fn (mixed $value): bool => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1)
                && ($manifest['import_sources_index_file'] ?? null) === (array_key_exists('encryption', $manifest) ? 'import_sources_index.enc' : 'import_sources_index.json')
                && is_string($manifest['import_sources_index_sha256'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/', $manifest['import_sources_index_sha256']) === 1);
        $hasValidEvidenceIndex = ! array_key_exists('evidence_index_file', $manifest)
            || (($manifest['evidence_index_file'] ?? null) === ($isEncrypted ? BackupEncryptionService::EVIDENCE_INDEX_FILE : 'evidence-index.json')
                && is_string($manifest['evidence_index_sha256'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/', $manifest['evidence_index_sha256']) === 1);
        $requiresDatabaseSchemaChecksum = $isEncrypted
            && in_array($manifest['database']['driver'] ?? null, ['mysql', 'mariadb'], true);
        $hasValidDatabaseSchemaChecksum = (! $requiresDatabaseSchemaChecksum && ! array_key_exists('schema_sha256', $manifest['database']))
            || (is_string($manifest['database']['schema_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $manifest['database']['schema_sha256']) === 1);
        $hasValidSchemaFingerprintVersion = ! array_key_exists('schema_fingerprint_version', $manifest['database'])
            || in_array($manifest['database']['schema_fingerprint_version'], [
                DatabaseSchemaFingerprint::PREVIOUS_VERSION,
                DatabaseSchemaFingerprint::VERSION,
            ], true);

        return $hasValidDatabaseChecksum
            && $hasValidDatabaseSchemaChecksum
            && $hasValidSchemaFingerprintVersion
            && $hasValidEvidenceChecksums
            && $hasValidEvidenceIndex
            && $hasValidImportFiles
            && isset($manifest['database']['driver'], $manifest['database']['file'])
            && is_string($manifest['database']['driver'])
            && is_string($manifest['database']['file'])
            && array_all($manifest['evidence_files'], fn (mixed $value): bool => is_string($value));
    }

    /** @param array<string, mixed> $manifest */
    private function hasValidEncryptionManifest(array $manifest): bool
    {
        return is_array($manifest['encryption'] ?? null)
            && ($manifest['encryption']['algorithm'] ?? null) === BackupEncryptionService::ALGORITHM
            && ($manifest['encryption']['format_version'] ?? null) === BackupEncryptionService::FORMAT_VERSION
            && ($manifest['evidence_index_file'] ?? null) === BackupEncryptionService::EVIDENCE_INDEX_FILE
            && is_string($manifest['evidence_index_sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $manifest['evidence_index_sha256']) === 1
            && is_string($manifest['database_sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $manifest['database_sha256']) === 1
            && isset($manifest['evidence_sha256'])
            && count(array_unique($manifest['evidence_files'])) === count($manifest['evidence_files'])
            && array_is_list($manifest['evidence_files'])
            && array_all($manifest['evidence_files'], fn (mixed $value): bool => is_string($value) && preg_match('/\A[0-9]{8}\z/', $value) === 1);
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
