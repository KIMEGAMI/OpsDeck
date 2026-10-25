<?php

namespace App\Services;

use Illuminate\Database\Schema\Builder;

class DatabaseSchemaFingerprint
{
    public const PREVIOUS_VERSION = 1;

    public const VERSION = 2;

    public function fingerprint(Builder $schema): string
    {
        return $this->fingerprintWithLocalSchemaNormalization($schema);
    }

    public function legacyFingerprint(Builder $schema): string
    {
        $databaseName = $schema->getConnection()->getDatabaseName();
        $tableNames = $schema->getTableListing($databaseName, false);
        $viewNames = array_column($schema->getViews($databaseName), 'name');
        sort($tableNames, SORT_STRING);
        sort($viewNames, SORT_STRING);

        return hash('sha256', json_encode([
            'tables' => array_values($tableNames),
            'views' => array_values($viewNames),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function fingerprintWithLocalSchemaNormalization(Builder $schema): string
    {
        $databaseName = $schema->getConnection()->getDatabaseName();
        $tableNames = $schema->getTableListing($databaseName, false);
        $viewNames = array_column($schema->getViews($databaseName), 'name');
        sort($tableNames, SORT_STRING);
        sort($viewNames, SORT_STRING);
        $tables = [];

        foreach ($tableNames as $tableName) {
            $columns = array_map(static fn (array $column): array => array_intersect_key($column, array_flip([
                'name',
                'type',
                'type_name',
                'collation',
                'nullable',
                'default',
                'auto_increment',
                'generation',
            ])), $schema->getColumns($tableName));
            usort($columns, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

            $indexes = array_map(static fn (array $index): array => array_intersect_key($index, array_flip([
                'name',
                'columns',
                'type',
                'unique',
                'primary',
            ])), $schema->getIndexes($tableName));
            usort($indexes, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

            $foreignKeys = array_map(static function (array $foreignKey) use ($databaseName): array {
                $normalizedForeignKey = array_intersect_key($foreignKey, array_flip([
                    'name',
                    'columns',
                    'foreign_schema',
                    'foreign_table',
                    'foreign_columns',
                    'on_update',
                    'on_delete',
                ]));
                $foreignSchema = $normalizedForeignKey['foreign_schema'] ?? null;
                $normalizedForeignKey['foreign_schema_is_local'] = $foreignSchema === $databaseName;
                if ($normalizedForeignKey['foreign_schema_is_local']) {
                    $normalizedForeignKey['foreign_schema'] = null;
                }

                return $normalizedForeignKey;
            }, $schema->getForeignKeys($tableName));
            usort($foreignKeys, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

            $tables[$tableName] = [
                'columns' => array_values($columns),
                'indexes' => array_values($indexes),
                'foreign_keys' => array_values($foreignKeys),
            ];
        }

        return hash('sha256', json_encode([
            'tables' => $tables,
            'views' => array_values($viewNames),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function matches(Builder $schema, string $expectedFingerprint): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $expectedFingerprint) === 1
            && hash_equals($expectedFingerprint, $this->fingerprint($schema));
    }

    public function matchesLegacy(Builder $schema, string $expectedFingerprint): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $expectedFingerprint) === 1
            && hash_equals($expectedFingerprint, $this->legacyFingerprint($schema));
    }
}
