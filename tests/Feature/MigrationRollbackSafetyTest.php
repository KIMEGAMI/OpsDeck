<?php

namespace Tests\Feature;

use Tests\TestCase;

class MigrationRollbackSafetyTest extends TestCase
{
    public function test_rollback_failure_keeps_the_migration_record(): void
    {
        $migrationFiles = glob(base_path('database/migrations/*.php')) ?: [];
        sort($migrationFiles, SORT_STRING);
        $latestMigrationPath = end($migrationFiles);
        $this->assertIsString($latestMigrationPath);
        $migrationName = pathinfo($latestMigrationPath, PATHINFO_FILENAME);
        $latestMigration = require $latestMigrationPath;
        try {
            $latestMigration->down();
            $this->fail('The latest migration should be forward-only.');
        } catch (\LogicException $exception) {
            $expectedRollbackMessage = $exception->getMessage();
        }

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertDatabaseHas('migrations', ['migration' => $migrationName]);

        try {
            $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true]);
            $this->fail('The rollback command should stop when the latest migration is forward-only.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                $expectedRollbackMessage,
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('migrations', ['migration' => $migrationName]);
    }

    public function test_forward_only_migrations_refuse_rollback(): void
    {
        $forwardOnlyMessage = 'This migration is forward-only; apply a new migration to change the schema.';
        $migrationFiles = array_filter(
            glob(base_path('database/migrations/*.php')) ?: [],
            static fn (string $migrationFile): bool => str_contains((string) file_get_contents($migrationFile), $forwardOnlyMessage),
        );

        $this->assertNotEmpty($migrationFiles);

        foreach ($migrationFiles as $migrationFile) {
            $migration = require $migrationFile;

            try {
                $migration->down();
                $this->fail('Forward-only migration '.basename($migrationFile).' should refuse rollback.');
            } catch (\LogicException $exception) {
                $this->assertSame($forwardOnlyMessage, $exception->getMessage());
            }
        }
    }
}
