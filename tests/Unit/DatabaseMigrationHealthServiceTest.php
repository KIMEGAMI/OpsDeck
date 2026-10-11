<?php

namespace Tests\Unit;

use App\Services\DatabaseMigrationHealthService;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use RuntimeException;
use Tests\TestCase;

class DatabaseMigrationHealthServiceTest extends TestCase
{
    public function test_it_returns_zero_when_every_migration_is_applied(): void
    {
        $migrationPaths = [database_path('migrations'), database_path('package-migrations')];
        $migrationFiles = [
            '2026_01_01_000000_create_users_table' => database_path('migrations/2026_01_01_000000_create_users_table.php'),
            '2026_01_02_000000_create_cache_table' => database_path('package-migrations/2026_01_02_000000_create_cache_table.php'),
        ];
        $migrator = $this->createMigrator($migrationPaths, $migrationFiles, array_keys($migrationFiles));

        $this->assertSame(0, (new DatabaseMigrationHealthService($migrator))->pendingCount());
    }

    public function test_it_counts_pending_migrations(): void
    {
        $migrationFiles = [
            '2026_01_01_000000_create_users_table' => database_path('migrations/2026_01_01_000000_create_users_table.php'),
            '2026_01_02_000000_create_cache_table' => database_path('migrations/2026_01_02_000000_create_cache_table.php'),
        ];
        $migrator = $this->createMigrator([database_path('migrations')], $migrationFiles, ['2026_01_01_000000_create_users_table']);

        $this->assertSame(1, (new DatabaseMigrationHealthService($migrator))->pendingCount());
    }

    public function test_it_returns_unknown_when_the_migration_repository_is_missing(): void
    {
        $migrator = $this->mock(Migrator::class);
        $migrator->shouldReceive('repositoryExists')->once()->andReturnFalse();

        $this->assertNull((new DatabaseMigrationHealthService($migrator))->pendingCount());
    }

    public function test_it_returns_unknown_when_migration_status_cannot_be_read(): void
    {
        $migrator = $this->mock(Migrator::class);
        $migrator->shouldReceive('repositoryExists')->once()->andThrow(new RuntimeException('Database access failed.'));

        $this->assertNull((new DatabaseMigrationHealthService($migrator))->pendingCount());
    }

    /**
     * @param  array<string, string>  $migrationFiles
     * @param  list<string>  $ranMigrations
     * @param  list<string>  $migrationPaths
     */
    private function createMigrator(array $migrationPaths, array $migrationFiles, array $ranMigrations): Migrator
    {
        $migrator = $this->mock(Migrator::class);
        $migrator->shouldReceive('repositoryExists')->once()->andReturnTrue();
        $migrator->shouldReceive('paths')->once()->andReturn(array_slice($migrationPaths, 1));
        $migrator->shouldReceive('getMigrationFiles')->once()->with($migrationPaths)->andReturn($migrationFiles);
        $migrationRepository = $this->mock(MigrationRepositoryInterface::class);
        $migrationRepository->shouldReceive('getRan')->once()->andReturn($ranMigrations);
        $migrator->shouldReceive('getRepository')->once()->andReturn($migrationRepository);

        return $migrator;
    }
}
