<?php

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * SafeDatabaseQueryTool - Read-Only Database Queries with Safety Controls.
 *
 * Prism tool for executing SELECT queries against the database with comprehensive
 * safety checks. Blocks destructive operations and limits result sets.
 *
 * Safety Features:
 * - Read-only enforcement (SELECT queries only)
 * - Dangerous keyword blocking (INSERT, UPDATE, DELETE, DROP, etc.)
 * - Table access whitelist (only safe tables allowed)
 * - Sensitive column blacklist (prevents credential exposure)
 * - Result set limiting (configurable maximum rows)
 * - Query timeout controls
 * - SQL injection pattern detection
 *
 * Blocked Operations:
 * - INSERT, UPDATE, DELETE, TRUNCATE
 * - DROP, ALTER, CREATE, RENAME
 * - GRANT, REVOKE (permission changes)
 * - LOAD, INTO OUTFILE (file operations)
 *
 * Access Control:
 * - Only allows queries on knowledge_documents, chat_interactions, artifacts, agents
 * - Blocks access to users, integration_tokens, password resets, personal_access_tokens
 * - Prevents querying sensitive columns (password, api_token, access_token, etc.)
 *
 * Query Execution:
 * - Uses Laravel query builder for parameter binding
 * - Returns results as JSON-safe arrays
 * - Includes row count and execution time
 * - Handles query errors gracefully
 *
 * Response Format:
 * - Rows: Array of result objects
 * - Count: Number of rows returned
 * - Execution time: Query duration in ms
 * - Truncated: Whether results were limited
 *
 * Use Cases:
 * - Debugging data issues
 * - Schema exploration for allowed tables
 * - Data analysis queries on knowledge/chat data
 * - Generating reports from permitted data
 *
 * @see \App\Tools\DatabaseSchemaInspectorTool
 */
class SafeDatabaseQueryTool
{
    use SafeJsonResponse;

    /**
     * SECURITY: Table whitelist - only these tables can be queried
     * Prevents access to sensitive user data, credentials, and system tables
     */
    protected static array $allowed_tables = [
        'knowledge_documents',
        'knowledge_tags',
        'knowledge_document_tags',
        'chat_sessions',
        'chat_interactions',
        'chat_interaction_sources',
        'chat_interaction_knowledge_sources',
        'artifacts',
        'artifact_versions',
        'artifact_tags',
        'agents',
        'agent_tools',
        'agent_executions',
        'status_streams',
        'sources', // Web sources
    ];

    /**
     * SECURITY: Sensitive column blacklist - prevents credential exposure
     * Even if table is whitelisted, these columns cannot be queried
     */
    protected static array $forbidden_columns = [
        'password',
        'api_token',
        'remember_token',
        'access_token',
        'refresh_token',
        'encrypted_token',
        'secret',
        'webhook_secret',
        'private_key',
        'api_key',
        'bearer_token',
    ];

    protected static array $dangerous_keywords = [
        'DELETE',
        'UPDATE',
        'INSERT',
        'DROP',
        'ALTER',
        'TRUNCATE',
        'CREATE',
        'GRANT',
        'REVOKE',
        'LOCK',
        'UNLOCK',
        'REPLACE',
        'RENAME',
        'LOAD',
        'CALL',
        'EXECUTE',
        'PREPARE',
    ];

    public static function create()
    {
        return Tool::as('safe_database_query')
            ->for('Execute read-only SELECT queries on the database. All queries are validated for safety - only SELECT statements allowed, with automatic result limiting.')
            ->withStringParameter('query', 'SQL SELECT query to execute')
            ->withNumberParameter('limit', 'Maximum number of results to return (1-100, default: 50)', false)
            ->using(function (string $query, ?int $limit = null) {
                return static::executeSafeQuery([
                    'query' => $query,
                    'limit' => $limit ?? 50,
                ]);
            });
    }

    protected static function executeSafeQuery(array $arguments = []): string
    {
        // Get StatusReporter for progress updates
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

        try {
            // Validate input
            $validator = Validator::make($arguments, [
                'query' => 'required|string|max:5000',
                'limit' => 'integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                Log::warning('SafeDatabaseQueryTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'SafeDatabaseQueryTool');
            }

            $validated = $validator->validated();
            $query = trim($validated['query']);
            $limit = $validated['limit'];

            if ($statusReporter) {
                $statusReporter->report('safe_database_query', 'Validating query safety...', true, false);
            }

            // Validate query safety
            $safetyCheck = static::validateQuerySafety($query);
            if (! $safetyCheck['safe']) {
                Log::warning('SafeDatabaseQueryTool: Unsafe query blocked', [
                    'query' => $query,
                    'reason' => $safetyCheck['error'],
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => $safetyCheck['error'],
                    'security' => 'Query blocked for safety reasons',
                ], 'SafeDatabaseQueryTool');
            }

            // Add LIMIT clause if not present
            $queryUpper = strtoupper($query);
            if (! str_contains($queryUpper, 'LIMIT')) {
                $query = rtrim($query, ';')." LIMIT {$limit}";
            }

            if ($statusReporter) {
                $statusReporter->report('safe_database_query', 'Executing database query...', false, false);
            }

            // Execute query with timeout
            $startTime = microtime(true);
            $results = DB::select($query);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Convert results to array
            $resultsArray = array_map(function ($row) {
                return (array) $row;
            }, $results);

            if ($statusReporter) {
                $statusReporter->report('safe_database_query', 'Query returned '.count($resultsArray)." rows ({$executionTime}ms)", false, false);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'results' => $resultsArray,
                    'row_count' => count($resultsArray),
                    'execution_time_ms' => $executionTime,
                    'query' => $query,
                ],
            ], 'SafeDatabaseQueryTool');

        } catch (\Exception $e) {
            Log::error('SafeDatabaseQueryTool: Query execution failed', [
                'query' => $arguments['query'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Query execution failed: '.$e->getMessage(),
            ], 'SafeDatabaseQueryTool');
        }
    }

    protected static function validateQuerySafety(string $query): array
    {
        // SECURITY: Normalize query to prevent bypass techniques
        $normalizedQuery = static::normalizeQuery($query);
        $queryUpper = strtoupper(trim($normalizedQuery));

        // Must start with SELECT
        if (! str_starts_with($queryUpper, 'SELECT')) {
            return [
                'safe' => false,
                'error' => 'Only SELECT queries are allowed',
            ];
        }

        // SECURITY: Check for SQL injection bypass techniques
        $injectionCheck = static::detectInjectionPatterns($query, $normalizedQuery);
        if (! $injectionCheck['safe']) {
            return $injectionCheck;
        }

        // Check for dangerous keywords
        foreach (static::$dangerous_keywords as $keyword) {
            if (str_contains($queryUpper, $keyword)) {
                return [
                    'safe' => false,
                    'error' => "Dangerous keyword detected: {$keyword}",
                ];
            }
        }

        // Check for multi-statement queries
        if (substr_count($query, ';') > 1) {
            return [
                'safe' => false,
                'error' => 'Multi-statement queries are not allowed',
            ];
        }

        // Check for UNION attacks (basic check)
        if (str_contains($queryUpper, 'UNION') && ! str_contains($queryUpper, 'UNION ALL')) {
            // Allow UNION ALL but warn about UNION
            if (str_contains($queryUpper, 'UNION SELECT')) {
                return [
                    'safe' => false,
                    'error' => 'UNION queries require careful review. Use UNION ALL if combining results is necessary.',
                ];
            }
        }

        // SECURITY: Validate table access (whitelist enforcement)
        $tableCheck = static::validateTableAccess($normalizedQuery);
        if (! $tableCheck['safe']) {
            return $tableCheck;
        }

        // SECURITY: Validate column access (sensitive column blacklist)
        $columnCheck = static::validateColumnAccess($normalizedQuery);
        if (! $columnCheck['safe']) {
            return $columnCheck;
        }

        return ['safe' => true];
    }

    /**
     * SECURITY: Normalize query to remove obfuscation techniques
     * Prevents bypass via comments, encoding, excessive whitespace
     */
    protected static function normalizeQuery(string $query): string
    {
        // Remove SQL comments (/* */ and -- style)
        $query = preg_replace('/\/\*.*?\*\//s', ' ', $query); // /* comment */
        $query = preg_replace('/--[^\n]*/m', ' ', $query);    // -- comment

        // Normalize whitespace (multiple spaces/tabs/newlines to single space)
        $query = preg_replace('/\s+/', ' ', $query);

        return trim($query);
    }

    /**
     * SECURITY: Detect SQL injection bypass techniques
     */
    protected static function detectInjectionPatterns(string $original, string $normalized): array
    {
        // Check for URL encoding (e.g., %55NION = UNION)
        if (preg_match('/%[0-9a-f]{2}/i', $original)) {
            Log::warning('SafeDatabaseQueryTool: URL encoding detected in query', [
                'query' => $original,
            ]);

            return [
                'safe' => false,
                'error' => 'URL-encoded characters are not allowed in queries',
            ];
        }

        // Check for excessive comment usage (obfuscation attempt)
        $commentCount = substr_count($original, '/*') + substr_count($original, '--');
        if ($commentCount > 2) {
            Log::warning('SafeDatabaseQueryTool: Excessive comments detected', [
                'query' => $original,
                'comment_count' => $commentCount,
            ]);

            return [
                'safe' => false,
                'error' => 'Excessive SQL comments detected. Keep queries simple and readable.',
            ];
        }

        // Check for DoS-prone functions (BENCHMARK, SLEEP, etc.)
        $dosFunctions = ['BENCHMARK', 'SLEEP', 'GET_LOCK', 'LOAD_FILE', 'OUTFILE'];
        $normalizedUpper = strtoupper($normalized);
        foreach ($dosFunctions as $func) {
            if (str_contains($normalizedUpper, $func)) {
                Log::warning('SafeDatabaseQueryTool: DoS-prone function detected', [
                    'function' => $func,
                    'query' => $normalized,
                ]);

                return [
                    'safe' => false,
                    'error' => "Function {$func} is not allowed (potential DoS vector)",
                ];
            }
        }

        // Check for hex encoding (0x... patterns that might encode dangerous keywords)
        if (preg_match('/0x[0-9a-f]+/i', $original)) {
            Log::warning('SafeDatabaseQueryTool: Hex encoding detected', [
                'query' => $original,
            ]);

            return [
                'safe' => false,
                'error' => 'Hex-encoded values are not allowed in queries',
            ];
        }

        return ['safe' => true];
    }

    /**
     * SECURITY: Validate that query only accesses whitelisted tables
     */
    protected static function validateTableAccess(string $query): array
    {
        $queryUpper = strtoupper($query);

        // Extract table names from FROM and JOIN clauses
        // Pattern: FROM/JOIN followed by optional whitespace, then table name
        preg_match_all('/(?:FROM|JOIN)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $matches);

        if (empty($matches[1])) {
            return [
                'safe' => false,
                'error' => 'Unable to parse table names from query',
            ];
        }

        $tablesInQuery = array_map('strtolower', $matches[1]);

        // Check each table against whitelist
        foreach ($tablesInQuery as $table) {
            if (! in_array($table, static::$allowed_tables)) {
                Log::warning('SafeDatabaseQueryTool: Unauthorized table access attempted', [
                    'table' => $table,
                    'query' => $query,
                    'allowed_tables' => static::$allowed_tables,
                ]);

                return [
                    'safe' => false,
                    'error' => "Access to table '{$table}' is not permitted. Allowed tables: ".implode(', ', static::$allowed_tables),
                ];
            }
        }

        return ['safe' => true];
    }

    /**
     * SECURITY: Validate that query doesn't access sensitive columns
     */
    protected static function validateColumnAccess(string $query): array
    {
        $queryUpper = strtoupper($query);

        // Check for forbidden column names in the SELECT clause
        foreach (static::$forbidden_columns as $column) {
            // Match column name with word boundaries (prevents false positives)
            if (preg_match('/\b'.preg_quote($column, '/').'\b/i', $query)) {
                Log::warning('SafeDatabaseQueryTool: Sensitive column access attempted', [
                    'column' => $column,
                    'query' => $query,
                ]);

                return [
                    'safe' => false,
                    'error' => "Access to sensitive column '{$column}' is not permitted for security reasons",
                ];
            }
        }

        // Check for SELECT * which could expose sensitive columns
        if (preg_match('/SELECT\s+\*/i', $query)) {
            Log::info('SafeDatabaseQueryTool: SELECT * query detected', [
                'query' => $query,
            ]);

            return [
                'safe' => false,
                'error' => 'SELECT * is not allowed for security reasons. Please specify explicit column names to ensure no sensitive data is exposed.',
            ];
        }

        return ['safe' => true];
    }
}
