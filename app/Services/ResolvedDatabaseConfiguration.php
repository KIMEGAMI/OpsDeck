<?php

namespace App\Services;

use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;

class ResolvedDatabaseConfiguration
{
    /**
     * Return the connection settings after Laravel has applied any database URL.
     *
     * @return array<string, mixed>
     */
    public function for(string $connectionName): array
    {
        $configuration = config('database.connections.'.$connectionName);

        if (! is_array($configuration)) {
            throw new RuntimeException('Database connection configuration is unavailable.');
        }

        return app(ConfigurationUrlParser::class)->parseConfiguration($configuration);
    }
}
