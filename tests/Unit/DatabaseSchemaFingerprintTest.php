<?php

namespace Tests\Unit;

use App\Services\DatabaseSchemaFingerprint;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Tests\TestCase;

class DatabaseSchemaFingerprintTest extends TestCase
{
    public function test_fingerprint_is_order_independent_and_accounts_for_tables_and_views(): void
    {
        $firstSchema = $this->mockSchema('opsdeck', ['users', 'migrations'], [['name' => 'active_users']]);
        $secondSchema = $this->mockSchema('opsdeck', ['migrations', 'users'], [['name' => 'active_users']]);

        $fingerprint = app(DatabaseSchemaFingerprint::class);

        $this->assertSame($fingerprint->fingerprint($firstSchema), $fingerprint->fingerprint($secondSchema));
        $this->assertTrue($fingerprint->matches($firstSchema, $fingerprint->fingerprint($secondSchema)));
        $this->assertFalse($fingerprint->matches($firstSchema, 'invalid'));
    }

    public function test_fingerprint_changes_when_a_database_table_is_missing(): void
    {
        $completeSchema = $this->mockSchema('opsdeck', ['migrations', 'users', 'assets'], []);
        $incompleteSchema = $this->mockSchema('opsdeck', ['migrations', 'users'], []);
        $differentViewsSchema = $this->mockSchema('opsdeck', ['migrations', 'users', 'assets'], [['name' => 'active_users']]);

        $fingerprint = app(DatabaseSchemaFingerprint::class);

        $this->assertNotSame($fingerprint->fingerprint($completeSchema), $fingerprint->fingerprint($incompleteSchema));
        $this->assertNotSame($fingerprint->fingerprint($completeSchema), $fingerprint->fingerprint($differentViewsSchema));
    }

    public function test_fingerprint_changes_when_columns_indexes_or_foreign_keys_differ(): void
    {
        $baseStructure = [
            'columns' => [
                ['name' => 'id', 'type' => 'bigint unsigned', 'type_name' => 'bigint', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => true, 'generation' => null],
            ],
            'indexes' => [],
            'foreign_keys' => [],
        ];
        $changedColumns = $baseStructure;
        $changedColumns['columns'][] = ['name' => 'email', 'type' => 'varchar(255)', 'type_name' => 'varchar', 'collation' => 'utf8mb4_unicode_ci', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'generation' => null];
        $changedIndexes = $baseStructure;
        $changedIndexes['indexes'][] = ['name' => 'users_email_unique', 'columns' => ['email'], 'type' => 'btree', 'unique' => true, 'primary' => false];
        $changedForeignKeys = $baseStructure;
        $changedForeignKeys['foreign_keys'][] = ['name' => 'users_organization_id_foreign', 'columns' => ['organization_id'], 'foreign_schema' => 'opsdeck', 'foreign_table' => 'organizations', 'foreign_columns' => ['id'], 'on_update' => 'no action', 'on_delete' => 'cascade'];

        $fingerprint = app(DatabaseSchemaFingerprint::class);
        $baseSchema = $this->mockSchema('opsdeck', ['users'], [], ['users' => $baseStructure]);

        $this->assertNotSame($fingerprint->fingerprint($baseSchema), $fingerprint->fingerprint($this->mockSchema('opsdeck', ['users'], [], ['users' => $changedColumns])));
        $this->assertNotSame($fingerprint->fingerprint($baseSchema), $fingerprint->fingerprint($this->mockSchema('opsdeck', ['users'], [], ['users' => $changedIndexes])));
        $this->assertNotSame($fingerprint->fingerprint($baseSchema), $fingerprint->fingerprint($this->mockSchema('opsdeck', ['users'], [], ['users' => $changedForeignKeys])));
    }

    public function test_fingerprint_matches_local_foreign_keys_after_restore_to_a_differently_named_database(): void
    {
        $foreignKey = [
            'name' => 'users_organization_id_foreign',
            'columns' => ['organization_id'],
            'foreign_table' => 'organizations',
            'foreign_columns' => ['id'],
            'on_update' => 'no action',
            'on_delete' => 'cascade',
        ];
        $originalForeignKey = [...$foreignKey, 'foreign_schema' => 'opsdeck'];
        $restoredForeignKey = [...$foreignKey, 'foreign_schema' => 'opsdeck_restore_test_local'];
        $differentExternalForeignKey = [...$foreignKey, 'foreign_schema' => 'shared_reference_data'];
        $originalStructure = ['columns' => [], 'indexes' => [], 'foreign_keys' => [$originalForeignKey]];
        $restoredStructure = ['columns' => [], 'indexes' => [], 'foreign_keys' => [$restoredForeignKey]];
        $externalStructure = ['columns' => [], 'indexes' => [], 'foreign_keys' => [$differentExternalForeignKey]];
        $fingerprint = app(DatabaseSchemaFingerprint::class);

        $original = $this->mockSchema('opsdeck', ['users'], [], ['users' => $originalStructure]);
        $restored = $this->mockSchema('opsdeck_restore_test_local', ['users'], [], ['users' => $restoredStructure]);
        $external = $this->mockSchema('opsdeck_restore_test_local', ['users'], [], ['users' => $externalStructure]);

        $this->assertSame($fingerprint->fingerprint($original), $fingerprint->fingerprint($restored));
        $this->assertNotSame($fingerprint->fingerprint($original), $fingerprint->fingerprint($external));
        $this->assertSame($fingerprint->legacyFingerprint($original), $fingerprint->legacyFingerprint($restored));
        $this->assertTrue($fingerprint->matchesLegacy($original, $fingerprint->legacyFingerprint($original)));
    }

    /**
     * @param  list<string>  $tableNames
     * @param  list<array{name: string}>  $views
     * @param  array<string, array{columns?: list<array<string, mixed>>, indexes?: list<array<string, mixed>>, foreign_keys?: list<array<string, mixed>>}>  $tableStructures
     */
    private function mockSchema(string $databaseName, array $tableNames, array $views, array $tableStructures = []): Builder
    {
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')->andReturn($databaseName);

        $schema = $this->mock(Builder::class);
        $schema->shouldReceive('getConnection')->andReturn($connection);
        $schema->shouldReceive('getTableListing')->with($databaseName, false)->andReturn($tableNames);
        $schema->shouldReceive('getViews')->with($databaseName)->andReturn($views);
        foreach ($tableNames as $tableName) {
            $structure = $tableStructures[$tableName] ?? ['columns' => [], 'indexes' => [], 'foreign_keys' => []];
            $schema->shouldReceive('getColumns')->with($tableName)->andReturn($structure['columns'] ?? []);
            $schema->shouldReceive('getIndexes')->with($tableName)->andReturn($structure['indexes'] ?? []);
            $schema->shouldReceive('getForeignKeys')->with($tableName)->andReturn($structure['foreign_keys'] ?? []);
        }

        return $schema;
    }
}
