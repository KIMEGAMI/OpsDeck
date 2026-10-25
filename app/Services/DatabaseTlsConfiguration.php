<?php

namespace App\Services;

use Pdo\Mysql;
use RuntimeException;

class DatabaseTlsConfiguration
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    public function issues(array $configuration): array
    {
        if (! $this->isRemote($configuration)) {
            return [];
        }
        if (! extension_loaded('pdo_mysql')) {
            return ['remote_database_requires_verified_tls'];
        }

        $options = is_array($configuration['options'] ?? null) ? $configuration['options'] : [];
        $ca = $options[Mysql::ATTR_SSL_CA] ?? null;
        $verificationDisabled = array_key_exists(Mysql::ATTR_SSL_VERIFY_SERVER_CERT, $options)
            && filter_var($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT], FILTER_VALIDATE_BOOL) === false;

        return is_string($ca) && trim($ca) !== '' && ! $verificationDisabled
            ? []
            : ['remote_database_requires_verified_tls'];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    public function clientOptions(array $configuration, ?string $clientVendor = null): array
    {
        if (! extension_loaded('pdo_mysql')) {
            if ($this->isRemote($configuration)) {
                throw new RuntimeException('Remote database backup requires verified TLS.');
            }

            return [];
        }

        $options = is_array($configuration['options'] ?? null) ? $configuration['options'] : [];
        $ca = $options[Mysql::ATTR_SSL_CA] ?? null;
        if (! is_string($ca) || trim($ca) === '') {
            if ($this->isRemote($configuration)) {
                throw new RuntimeException('Remote database backup requires verified TLS.');
            }

            return [];
        }

        if ($this->issues($configuration) !== []) {
            throw new RuntimeException('Database TLS certificate verification is disabled.');
        }

        if (! in_array($clientVendor, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Database client vendor could not be identified.');
        }

        $verificationOption = $clientVendor === 'mariadb'
            ? '--ssl-verify-server-cert'
            : '--ssl-mode=VERIFY_IDENTITY';

        return [$verificationOption, '--ssl-ca='.$ca];
    }

    /** @param array<string, mixed> $configuration */
    private function isRemote(array $configuration): bool
    {
        if (is_string($configuration['unix_socket'] ?? null) && trim($configuration['unix_socket']) !== '') {
            return false;
        }

        $host = strtolower(trim((string) ($configuration['host'] ?? '')));

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
