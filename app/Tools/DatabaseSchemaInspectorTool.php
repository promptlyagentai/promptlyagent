<?php

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * DatabaseSchemaInspectorTool - Database Schema and Structure Inspection.
 *
 * Prism tool for inspecting database schema, tables, columns, and relationships.
 * Provides detailed information about database structure without executing queries.
 *
 * Inspection Capabilities:
 * - List all tables in database
 * - Get table column definitions
 * - View column types and constraints
 * - Inspect indexes and keys
 * - Identify foreign key relationships
 *
 * Response Format:
 * - Table names and row counts
 * - Column specifications (name, type, nullable, default)
 * - Index information
 * - Primary and foreign key constraints
 * - Table relationships
 *
 * Use Cases:
 * - Understanding database structure
 * - Planning queries before execution
 * - Documenting schema
 * - Debugging data model issues
 *
 * @see \App\Tools\SafeDatabaseQueryTool
 */
class DatabaseSchemaInspectorTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('database_schema_inspector')
            ->for('Inspect database schema including tables, columns, indexes, and foreign key relationships. Read-only schema introspection.')
            ->withStringParameter('action', 'Action to perform: list_tables, describe_table, get_indexes, get_foreign_keys, list_migrations')
            ->withStringParameter('table_name', 'Table name (required for describe_table, get_indexes, get_foreign_keys)', false)
            ->withNumberParameter('limit', 'Limit number of results (default: 50, max: 100)', false)
            ->using(function (string $action, ?string $table_name = null, ?int $limit = null) {
                return static::executeSchemaInspection([
                    'action' => $action,
                    'table_name' => $table_name,
                    'limit' => $limit ?? 50,
                ]);
            });
    }

    protected static function executeSchemaInspection(array $arguments = []): string
    {
        // Get StatusReporter for progress updates
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

        try {
            // Validate input
            $validator = Validator::make($arguments, [
                'action' => 'required|string|in:list_tables,describe_table,get_indexes,get_foreign_keys,list_migrations',
                'table_name' => 'nullable|string|max:255',
                'limit' => 'integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                Log::warning('DatabaseSchemaInspectorTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'DatabaseSchemaInspectorTool');
            }

            $validated = $validator->validated();
            $action = $validated['action'];
            $tableName = $validated['table_name'] ?? null;
            $limit = $validated['limit'];

            // Report what we're doing
            if ($statusReporter) {
                $message = match ($action) {
                    'list_tables' => 'Inspecting database tables...',
                    'describe_table' => "Analyzing table structure: {$tableName}",
                    'get_indexes' => "Examining indexes for: {$tableName}",
                    'get_foreign_keys' => "Checking foreign key relationships: {$tableName}",
                    'list_migrations' => 'Reviewing migration history...',
                    default => 'Inspecting database schema...',
                };
                $statusReporter->report('database_schema_inspector', $message, true, false);
            }

            // Route to appropriate action
            $result = match ($action) {
                'list_tables' => static::listTables($limit, $statusReporter),
                'describe_table' => static::describeTable($tableName, $statusReporter),
                'get_indexes' => static::getIndexes($tableName, $statusReporter),
                'get_foreign_keys' => static::getForeignKeys($tableName, $statusReporter),
                'list_migrations' => static::listMigrations($limit, $statusReporter),
                default => ['success' => false, 'error' => 'Unknown action'],
            };

            return static::safeJsonEncode($result, 'DatabaseSchemaInspectorTool');

        } catch (\Exception $e) {
            Log::error('DatabaseSchemaInspectorTool: Exception during execution', [
                'action' => $arguments['action'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Schema inspection failed: '.$e->getMessage(),
            ], 'DatabaseSchemaInspectorTool');
        }
    }

    protected static function listTables(int $limit, $statusReporter = null): array
    {
        try {
            $tables = Schema::getTableListing();
            $tableData = [];

            if ($statusReporter) {
                $statusReporter->report('database_schema_inspector', 'Found '.count($tables).' tables, analyzing...', false, false);
            }

            foreach ($tables as $table) {
                try {
                    $rowCount = DB::table($table)->count();
                    $tableData[] = [
                        'name' => $table,
                        'row_count' => $rowCount,
                    ];
                } catch (\Exception $e) {
                    // If we can't count rows, still include the table
                    $tableData[] = [
                        'name' => $table,
                        'row_count' => null,
                        'note' => 'Could not retrieve row count',
                    ];
                }
            }

            // Sort by name
            usort($tableData, fn ($a, $b) => strcmp($a['name'], $b['name']));

            // Apply limit
            if (count($tableData) > $limit) {
                $tableData = array_slice($tableData, 0, $limit);
            }

            if ($statusReporter) {
                $statusReporter->report('database_schema_inspector', 'Table inspection complete', false, false);
            }

            return [
                'success' => true,
                'data' => [
                    'tables' => $tableData,
                    'total_count' => count($tables),
                    'showing_count' => count($tableData),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to list tables: '.$e->getMessage(),
            ];
        }
    }

    protected static function describeTable(?string $tableName, $statusReporter = null): array
    {
        if (! $tableName) {
            return [
                'success' => false,
                'error' => 'table_name parameter is required for describe_table action',
            ];
        }

        try {
            if (! Schema::hasTable($tableName)) {
                return [
                    'success' => false,
                    'error' => "Table '{$tableName}' does not exist",
                ];
            }

            $columns = Schema::getColumns($tableName);
            $columnData = [];

            foreach ($columns as $column) {
                $columnData[] = [
                    'name' => $column['name'],
                    'type' => $column['type_name'],
                    'type_full' => $column['type'],
                    'nullable' => $column['nullable'],
                    'default' => $column['default'],
                    'auto_increment' => $column['auto_increment'] ?? false,
                    'collation' => $column['collation'] ?? null,
                ];
            }

            if ($statusReporter) {
                $statusReporter->report('database_schema_inspector', 'Analyzed '.count($columnData).' columns', false, false);
            }

            return [
                'success' => true,
                'data' => [
                    'table' => $tableName,
                    'columns' => $columnData,
                    'column_count' => count($columnData),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to describe table '{$tableName}': ".$e->getMessage(),
            ];
        }
    }

    protected static function getIndexes(?string $tableName, $statusReporter = null): array
    {
        if (! $tableName) {
            return [
                'success' => false,
                'error' => 'table_name parameter is required for get_indexes action',
            ];
        }

        try {
            if (! Schema::hasTable($tableName)) {
                return [
                    'success' => false,
                    'error' => "Table '{$tableName}' does not exist",
                ];
            }

            $indexes = Schema::getIndexes($tableName);
            $indexData = [];

            foreach ($indexes as $index) {
                $indexData[] = [
                    'name' => $index['name'],
                    'columns' => $index['columns'],
                    'type' => $index['type'] ?? 'index',
                    'unique' => $index['unique'] ?? false,
                    'primary' => $index['primary'] ?? false,
                ];
            }

            if ($statusReporter) {
                $statusReporter->report('database_schema_inspector', 'Found '.count($indexData).' indexes', false, false);
            }

            return [
                'success' => true,
                'data' => [
                    'table' => $tableName,
                    'indexes' => $indexData,
                    'index_count' => count($indexData),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to get indexes for table '{$tableName}': ".$e->getMessage(),
            ];
        }
    }

    protected static function getForeignKeys(?string $tableName, $statusReporter = null): array
    {
        if (! $tableName) {
            return [
                'success' => false,
                'error' => 'table_name parameter is required for get_foreign_keys action',
            ];
        }

        try {
            if (! Schema::hasTable($tableName)) {
                return [
                    'success' => false,
                    'error' => "Table '{$tableName}' does not exist",
                ];
            }

            $foreignKeys = Schema::getForeignKeys($tableName);
            $fkData = [];

            foreach ($foreignKeys as $fk) {
                $fkData[] = [
                    'name' => $fk['name'],
                    'columns' => $fk['columns'],
                    'foreign_table' => $fk['foreign_table'],
                    'foreign_columns' => $fk['foreign_columns'],
                    'on_update' => $fk['on_update'] ?? null,
                    'on_delete' => $fk['on_delete'] ?? null,
                ];
            }

            if ($statusReporter) {
                $statusReporter->report('database_schema_inspector', 'Found '.count($fkData).' foreign key relationships', false, false);
            }

            return [
                'success' => true,
                'data' => [
                    'table' => $tableName,
                    'foreign_keys' => $fkData,
                    'foreign_key_count' => count($fkData),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to get foreign keys for table '{$tableName}': ".$e->getMessage(),
            ];
        }
    }

    protected static function listMigrations(int $limit, $statusReporter = null): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return [
                    'success' => false,
                    'error' => 'Migrations table does not exist',
                ];
            }

            $migrations = DB::table('migrations')
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get(['id', 'migration', 'batch'])
                ->map(function ($migration) {
                    return [
                        'id' => $migration->id,
                        'migration' => $migration->migration,
                        'batch' => $migration->batch,
                    ];
                })
                ->toArray();

            if ($statusReporter) {
                $statusReporter->report('database_schema_inspector', 'Retrieved '.count($migrations).' recent migrations', false, false);
            }

            return [
                'success' => true,
                'data' => [
                    'migrations' => $migrations,
                    'count' => count($migrations),
                    'note' => 'Showing most recent migrations',
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to list migrations: '.$e->getMessage(),
            ];
        }
    }
}
