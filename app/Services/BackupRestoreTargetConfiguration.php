<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Pdo\Mysql;
use Throwable;

class BackupRestoreTargetConfiguration
{
    private const DATABASE_PREFIX = 'opsdeck_restore_test_';

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
        $restoreConfiguration = config('database.connections.'.$restoreConnectionName);

        if ($restoreConnectionName === ''
            || $restoreConnectionName === $defaultConnectionName
            || ! is_array($restoreConfiguration)) {
            return ['restore_connection_not_separate'];
        }

        $databaseName = $restoreConfiguration['database'] ?? null;
        $driver = $restoreConfiguration['driver'] ?? null;
        $defaultDriver = config('database.connections.'.$defaultConnectionName.'.driver');
        $username = $restoreConfiguration['username'] ?? null;
        $defaultUsername = config('database.connections.'.$defaultConnectionName.'.username');
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
        if ($databaseName === config('database.connections.'.$defaultConnectionName.'.database')) {
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
            $schema = $connection->getSchemaBuilder();

            return $schema->getTableListing() === [] && $schema->getViews() === []
                ? 'ready'
                : 'target_not_empty';
        } catch (Throwable) {
            return 'connection_unavailable';
        }
    }
}
