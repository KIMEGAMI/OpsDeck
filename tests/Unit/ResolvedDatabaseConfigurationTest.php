<?php

namespace Tests\Unit;

use App\Services\ResolvedDatabaseConfiguration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolvedDatabaseConfigurationTest extends TestCase
{
    public function test_it_returns_database_settings_resolved_from_the_connection_url(): void
    {
        config(['database.connections.url_config_test' => [
            'driver' => 'mysql',
            'url' => 'mysql://url_test_user@db.example.test:3307/url_test_database',
            'host' => 'fallback.example.test',
            'port' => 3306,
            'database' => 'fallback_database',
            'username' => 'fallback_user',
            'password' => null,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ]]);
        DB::purge('url_config_test');

        try {
            $configuration = app(ResolvedDatabaseConfiguration::class)->for('url_config_test');

            $this->assertSame('db.example.test', $configuration['host']);
            $this->assertSame(3307, $configuration['port']);
            $this->assertSame('url_test_database', $configuration['database']);
            $this->assertSame('url_test_user', $configuration['username']);
            $this->assertNull($configuration['password']);
            $this->assertArrayNotHasKey('url', $configuration);
        } finally {
            DB::purge('url_config_test');
        }
    }
}
