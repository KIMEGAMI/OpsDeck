<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

class DatabaseMigrationHealthService
{
    public function __construct(private Migrator $migrator) {}

    public function pendingCount(): ?int
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return null;
            }

            $migrationPaths = array_merge([database_path('migrations')], $this->migrator->paths());
            $migrationFiles = $this->migrator->getMigrationFiles($migrationPaths);
            $ranMigrations = $this->migrator->getRepository()->getRan();

            return count(array_diff(array_keys($migrationFiles), $ranMigrations));
        } catch (Throwable) {
            return null;
        }
    }
}
