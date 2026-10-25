<?php

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestingEnvironmentIsolationTest extends TestCase
{
    public function test_tests_use_isolated_database_and_service_drivers(): void
    {
        $databasePath = config('database.connections.sqlite.database');

        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertIsString($databasePath);
        $this->assertStringStartsWith(sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-phpunit-', $databasePath);
        $this->assertStringEndsWith('.sqlite', $databasePath);
        $this->assertFileExists($databasePath);
        $this->assertNotSame(base_path('database/database.sqlite'), $databasePath);
        $this->assertFalse(config('app.debug'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertTrue(config('opsdeck.ai.enabled'));
        $this->assertSame('openai', config('opsdeck.ai.provider'));
        $this->assertSame('', config('opsdeck.ai.api_key'));
        $this->assertSame('', config('services.stripe.secret'));
    }

    public function test_unmatched_http_requests_are_rejected_before_network_access(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://api.openai.com/v1/models');
    }
}
