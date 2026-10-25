<?php

namespace Tests\Unit;

use App\Services\DatabaseClientVersionDetector;
use App\Services\DatabaseTlsConfiguration;
use Pdo\Mysql;
use RuntimeException;
use Tests\TestCase;

class DatabaseTlsConfigurationTest extends TestCase
{
    public function test_loopback_and_socket_connections_do_not_require_tls(): void
    {
        $tls = new DatabaseTlsConfiguration;

        $this->assertSame([], $tls->issues(['host' => 'localhost']));
        $this->assertSame([], $tls->issues(['host' => '127.0.0.1']));
        $this->assertSame([], $tls->issues(['host' => 'database.internal', 'unix_socket' => '/var/run/mysql.sock']));
        $this->assertSame([], $tls->clientOptions(['host' => '127.0.0.1']));
    }

    public function test_remote_database_requires_a_ca_and_enabled_certificate_verification(): void
    {
        $tls = new DatabaseTlsConfiguration;

        $this->assertSame(['remote_database_requires_verified_tls'], $tls->issues(['host' => 'mysql.example.test', 'options' => []]));
        $this->assertSame([], $tls->issues([
            'host' => 'mysql.example.test',
            'options' => [Mysql::ATTR_SSL_CA => 'C:\\certs\\database-ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true],
        ]));
        $this->assertSame(['remote_database_requires_verified_tls'], $tls->issues([
            'host' => 'mysql.example.test',
            'options' => [Mysql::ATTR_SSL_CA => 'C:\\certs\\database-ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false],
        ]));
    }

    public function test_backup_client_arguments_enforce_server_identity_with_vendor_syntax(): void
    {
        $tls = new DatabaseTlsConfiguration;
        $configuration = [
            'host' => 'mysql.example.test',
            'options' => [Mysql::ATTR_SSL_CA => 'C:\\certs\\database-ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true],
        ];

        $this->assertSame(
            ['--ssl-mode=VERIFY_IDENTITY', '--ssl-ca=C:\\certs\\database-ca.pem'],
            $tls->clientOptions($configuration, 'mysql'),
        );
        $this->assertSame(
            ['--ssl-verify-server-cert', '--ssl-ca=C:\\certs\\database-ca.pem'],
            $tls->clientOptions($configuration, 'mariadb'),
        );
        $this->assertSame(
            ['--ssl-verify-server-cert', '--ssl-ca=C:\\certs\\database-ca.pem'],
            $tls->clientOptions($configuration, 'mariadb'),
        );
    }

    public function test_database_client_version_detection_distinguishes_mysql_compatible_binary_names(): void
    {
        $detector = new DatabaseClientVersionDetector;

        $this->assertSame('mariadb', $detector->classifyVersion('mysqldump.exe', 'mysqldump Ver 10.11.5 Distrib 10.11.5-MariaDB'));
        $this->assertSame('mysql', $detector->classifyVersion('mysqldump.exe', 'mysqldump Ver 8.4.2 for Win64'));
        $this->assertNull($detector->classifyVersion('database-dump.exe', 'unknown client'));
    }

    public function test_remote_backup_fails_closed_without_verified_tls(): void
    {
        $this->expectException(RuntimeException::class);
        (new DatabaseTlsConfiguration)->clientOptions(['host' => 'mysql.example.test'], 'mysql');
    }
}
