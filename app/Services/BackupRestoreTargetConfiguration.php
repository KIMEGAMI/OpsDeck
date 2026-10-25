<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;
use Throwable;

class BackupRestoreTargetConfiguration
{
    private const DATABASE_PREFIX = 'opsdeck_restore_test_';

    private const MYSQL_ACCESS_DENIED_ERROR = 1044;

    private const MYSQL_UNKNOWN_DATABASE_ERROR = 1049;

    private const MYSQL_USER_AUTHENTICATION_ERROR = 1045;

    private const MYSQL_TABLE_ACCESS_DENIED_ERROR = 1142;

    public function __construct(private ResolvedDatabaseConfiguration $resolvedDatabaseConfiguration) {}

    /**
     * Confirm that the restore target is dedicated, driver-compatible, and isolated from the application DB.
     */
    public function isSafelyConfigured(): bool
    {
        return $this->configurationIssues() === [];
    }

    /** @return list<string> */
    public function configurationIssues(): array
    {
        $restoreConnectionName = (string) config('opsdeck.backup.restore_connection');
        $defaultConnectionName = (string) config('database.default');
        try {
            $restoreConfiguration = $this->resolvedDatabaseConfiguration->for($restoreConnectionName);
            $defaultConfiguration = $this->resolvedDatabaseConfiguration->for($defaultConnectionName);
        } catch (Throwable) {
            return ['restore_connection_not_separate'];
        }

        if ($restoreConnectionName === ''
            || $restoreConnectionName === $defaultConnectionName
            || ! is_array($restoreConfiguration)) {
            return ['restore_connection_not_separate'];
        }

        $databaseName = $restoreConfiguration['database'] ?? null;
        $driver = $restoreConfiguration['driver'] ?? null;
        $defaultDriver = $defaultConfiguration['driver'] ?? null;
        $username = $restoreConfiguration['username'] ?? null;
        $defaultUsername = $defaultConfiguration['username'] ?? null;
        $host = $restoreConfiguration['host'] ?? null;
        $socket = $restoreConfiguration['unix_socket'] ?? null;
        $port = $restoreConfiguration['port'] ?? null;
        $options = is_array($restoreConfiguration['options'] ?? null) ? $restoreConfiguration['options'] : [];
        $sslCaOption = extension_loaded('pdo_mysql') ? Mysql::ATTR_SSL_CA : null;
        $sslCa = $sslCaOption !== null ? ($options[$sslCaOption] ?? null) : null;
        $sslVerifyOption = extension_loaded('pdo_mysql') ? Mysql::ATTR_SSL_VERIFY_SERVER_CERT : null;
        $sslVerificationDisabled = $sslVerifyOption !== null
            && array_key_exists($sslVerifyOption, $options)
            && filter_var($options[$sslVerifyOption], FILTER_VALIDATE_BOOL) === false;
        $hasSocket = is_string($socket) && trim($socket) !== '';
        $hasValidPort = $hasSocket
            || (is_scalar($port) && filter_var($port, FILTER_VALIDATE_INT) !== false && (int) $port >= 1 && (int) $port <= 65535);
        $isLocalTarget = $hasSocket
            || (is_string($host) && in_array(strtolower(trim($host)), ['127.0.0.1', '::1', 'localhost'], true));
        $hasVerifiedTls = is_string($sslCa) && trim($sslCa) !== '';
        $isValidHost = is_string($host)
            && (filter_var($host, FILTER_VALIDATE_IP) !== false
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);

        $issues = [];
        if (! in_array($driver, ['mysql', 'mariadb'], true) || $driver !== $defaultDriver) {
            $issues[] = 'restore_driver_must_match_mysql_application_driver';
        }
        if (! is_string($databaseName)
            || strlen($databaseName) > 64
            || ! str_starts_with($databaseName, self::DATABASE_PREFIX)
            || preg_match('/\Aopsdeck_restore_test_[a-z0-9_]+\z/', $databaseName) !== 1) {
            $issues[] = 'restore_database_name_must_use_reserved_prefix';
        }
        if ($databaseName === ($defaultConfiguration['database'] ?? null)) {
            $issues[] = 'restore_database_must_differ_from_application_database';
        }
        if (! is_string($username) || trim($username) === '' || $username === $defaultUsername) {
            $issues[] = 'restore_database_user_must_be_dedicated';
        }
        if (! $hasSocket && ! $isValidHost) {
            $issues[] = 'restore_database_host_or_socket_is_invalid';
        }
        if (! $hasValidPort) {
            $issues[] = 'restore_database_port_is_invalid';
        }
        if (! $isLocalTarget && (! $hasVerifiedTls || $sslVerificationDisabled)) {
            $issues[] = 'remote_restore_database_requires_verified_tls';
        }

        return $issues;
    }

    /**
     * Confirm that the dedicated target is reachable and empty without modifying it.
     */
    public function isReadyForRestoreTest(): bool
    {
        return $this->readinessStatus() === 'ready';
    }

    /**
     * Return a secret-free reason for restore-target readiness.
     */
    public function readinessStatus(): string
    {
        if (! $this->isSafelyConfigured()) {
            return 'unsafe_configuration';
        }

        try {
            $connectionName = (string) config('opsdeck.backup.restore_connection');
            $connection = DB::connection($connectionName);
            $connection->getPdo();

            return $this->targetIsEmpty($connection)
                ? 'ready'
                : 'target_not_empty';
        } catch (Throwable $exception) {
            return $this->connectionFailureStatus($exception);
        }
    }

    private function connectionFailureStatus(Throwable $exception): string
    {
        $currentException = $exception;
        while ($currentException !== null) {
            if ($currentException instanceof \PDOException) {
                $driverErrorCode = is_array($currentException->errorInfo)
                    && isset($currentException->errorInfo[1])
                    && is_numeric($currentException->errorInfo[1])
                    ? (int) $currentException->errorInfo[1]
                    : (is_numeric($currentException->getCode()) ? (int) $currentException->getCode() : null);

                return match ($driverErrorCode) {
                    self::MYSQL_USER_AUTHENTICATION_ERROR => 'credentials_rejected',
                    self::MYSQL_ACCESS_DENIED_ERROR, self::MYSQL_TABLE_ACCESS_DENIED_ERROR => 'database_access_denied',
                    self::MYSQL_UNKNOWN_DATABASE_ERROR => 'database_not_found',
                    default => 'connection_unavailable',
                };
            }

            $currentException = $currentException->getPrevious();
        }

        return 'connection_unavailable';
    }

    /**
     * Confirm that the dedicated restore target has no tables, views, routines, or scheduled events.
     */
    public function targetIsEmpty(ConnectionInterface $connection): bool
    {
        $databaseName = $connection->getDatabaseName();
        $schema = $connection->getSchemaBuilder();

        if ($schema->getTableListing($databaseName, false) !== [] || $schema->getViews($databaseName) !== []) {
            return false;
        }

        $hasRoutines = $connection->selectOne(
            'SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? LIMIT 1',
            [$databaseName],
        ) !== null;
        if ($hasRoutines) {
            return false;
        }

        return $connection->selectOne(
            'SELECT 1 FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? LIMIT 1',
            [$databaseName],
        ) === null;
    }
}
