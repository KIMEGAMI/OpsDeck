<?php

namespace Tests\Unit;

use App\Services\DatabaseStorageUsageService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DatabaseStorageUsageServiceTest extends TestCase
{
    public function test_non_mysql_drivers_are_marked_not_applicable(): void
    {
        $connection = $this->mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $summary = app(DatabaseStorageUsageService::class)->summary();

        $this->assertSame('not_applicable', $summary['status']);
        $this->assertNull($summary['table_count']);
        $this->assertNull($summary['size_bytes']);
        $this->assertSame('対象外（MySQL/MariaDBのみ計測）', $summary['size_display']);
    }

    public function test_mysql_usage_is_reported_as_a_nonnegative_approximation(): void
    {
        $connection = $this->mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'information_schema.TABLES')), ['BASE TABLE'])
            ->andReturn((object) ['table_count' => '4', 'size_bytes' => '2097152']);
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $summary = app(DatabaseStorageUsageService::class)->summary();

        $this->assertSame('measured', $summary['status']);
        $this->assertSame(4, $summary['table_count']);
        $this->assertSame(2097152, $summary['size_bytes']);
        $this->assertSame('2.0 MiB', $summary['size_display']);
    }

    public function test_database_metadata_errors_do_not_leak_exception_details(): void
    {
        $connection = $this->mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->once()->andThrow(new \RuntimeException('sensitive database detail'));
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $summary = app(DatabaseStorageUsageService::class)->summary();

        $this->assertSame('unavailable', $summary['status']);
        $this->assertNull($summary['table_count']);
        $this->assertNull($summary['size_bytes']);
        $this->assertSame('未計測', $summary['size_display']);
        $this->assertStringNotContainsString('sensitive database detail', json_encode($summary));
    }

    public function test_database_connection_errors_are_reported_as_unavailable(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new \RuntimeException('sensitive connection detail'));

        $summary = app(DatabaseStorageUsageService::class)->summary();

        $this->assertSame('unavailable', $summary['status']);
        $this->assertNull($summary['table_count']);
        $this->assertNull($summary['size_bytes']);
        $this->assertSame('未計測', $summary['size_display']);
        $this->assertStringNotContainsString('sensitive connection detail', json_encode($summary));
    }
}
