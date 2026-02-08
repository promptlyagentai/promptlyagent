<?php

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * CodeSearchTool - Codebase Pattern Search Using Ripgrep.
 *
 * Prism tool for searching codebase using ripgrep for fast pattern matching.
 * Supports regex patterns, file type filtering, and context lines.
 *
 * Search Capabilities:
 * - Regex pattern matching
 * - Case-sensitive and insensitive search
 * - File type filtering (e.g., only .php, .js files)
 * - Directory scoping
 * - Context lines (before/after matches)
 *
 * Powered by Ripgrep:
 * - Fast recursive search
 * - Respects .gitignore automatically
 * - Unicode support
 * - Multi-line pattern matching
 *
 * Response Format:
 * - File paths with matches
 * - Line numbers
 * - Matched content with context
 * - Match count per file
 *
 * Security:
 * - Project root restriction
 * - Pattern validation
 * - Result limiting to prevent overwhelming output
 *
 * Use Cases:
 * - Finding function definitions
 * - Searching for API usage
 * - Locating configuration values
 * - Code pattern analysis
 *
 * @see \App\Tools\SecureFileReaderTool
 */
class CodeSearchTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('code_search')
            ->for('Search for code patterns using grep. Supports regex patterns and file filtering by extension.')
            ->withStringParameter('pattern', 'Search pattern (supports regex)')
            ->withStringParameter('directory', 'Directory to search in (relative to project root, optional)', false)
            ->withStringParameter('file_extension', 'Filter by file extension (e.g., "php", "js", "blade.php"), optional', false)
            ->withBooleanParameter('case_sensitive', 'Case-sensitive search (default: false)', false)
            ->withNumberParameter('limit', 'Limit number of results (default: 100, max: 500)', false)
            ->using(function (
                string $pattern,
                ?string $directory = null,
                ?string $file_extension = null,
                ?bool $case_sensitive = null,
                ?int $limit = null
            ) {
                return static::executeCodeSearch([
                    'pattern' => $pattern,
                    'directory' => $directory,
                    'file_extension' => $file_extension,
                    'case_sensitive' => $case_sensitive ?? false,
                    'limit' => $limit ?? 100,
                ]);
            });
    }

    protected static function executeCodeSearch(array $arguments = []): string
    {
        // Get StatusReporter for progress updates
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

        try {
            // Validate input
            $validator = Validator::make($arguments, [
                'pattern' => 'required|string|max:500',
                'directory' => 'nullable|string|max:500',
                'file_extension' => 'nullable|string|max:50',
                'case_sensitive' => 'boolean',
                'limit' => 'integer|min:1|max:500',
            ]);

            if ($validator->fails()) {
                Log::warning('CodeSearchTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'CodeSearchTool');
            }

            $validated = $validator->validated();
            $pattern = $validated['pattern'];
            $directory = $validated['directory'] ? trim($validated['directory'], '/ ') : '';
            $fileExtension = $validated['file_extension'];
            $caseSensitive = $validated['case_sensitive'];
            $limit = $validated['limit'];

            $searchScope = $directory ?: 'entire project';
            $extFilter = $fileExtension ? " in *.{$fileExtension} files" : '';
            if ($statusReporter) {
                $statusReporter->report('code_search', "Searching for '{$pattern}' in {$searchScope}{$extFilter}...", true, false);
            }

            if ($directory && str_contains($directory, '..')) {
                Log::warning('CodeSearchTool: Path traversal attempt with .. blocked', [
                    'directory' => $directory,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid directory path: path traversal not allowed',
                ], 'CodeSearchTool');
            }

            // Validate directory path
            $basePath = base_path();
            $searchPath = $directory ? realpath($basePath.'/'.$directory) : $basePath;

            if ($searchPath === false) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Directory does not exist',
                    'directory' => $directory ?: '.',
                ], 'CodeSearchTool');
            }

            if (! str_starts_with($searchPath, $basePath)) {
                Log::warning('CodeSearchTool: Path traversal attempt blocked', [
                    'directory' => $directory,
                    'search_path' => $searchPath,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid directory path (path traversal detected)',
                ], 'CodeSearchTool');
            }

            // Build grep command
            $cmd = 'grep -r -n';

            // Case sensitivity
            if (! $caseSensitive) {
                $cmd .= ' -i';
            }

            // File extension filter
            if ($fileExtension) {
                $cleanExtension = preg_replace('/[^a-zA-Z0-9._-]/', '', $fileExtension);
                $cmd .= " --include='*.{$cleanExtension}'";
            }

            // Exclude common directories that should not be searched
            $cmd .= " --exclude-dir='.git'";
            $cmd .= " --exclude-dir='node_modules'";
            $cmd .= " --exclude-dir='vendor'";
            $cmd .= " --exclude-dir='storage'";
            $cmd .= " --exclude-dir='.idea'";

            // Add pattern and path
            $cmd .= ' '.escapeshellarg($pattern);
            $cmd .= ' '.escapeshellarg($searchPath);

            // Execute with timeout
            $startTime = microtime(true);
            $result = Process::timeout(30)->run($cmd);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Parse grep output
            $matches = static::parseGrepOutput($result->output(), $basePath, $limit);

            if ($statusReporter) {
                $statusReporter->report('code_search', 'Found '.count($matches)." matches ({$executionTime}ms)", false, false);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'pattern' => $pattern,
                    'directory' => $directory ?: '.',
                    'matches' => $matches,
                    'match_count' => count($matches),
                    'execution_time_ms' => $executionTime,
                    'case_sensitive' => $caseSensitive,
                    'file_extension' => $fileExtension,
                    'note' => count($matches) >= $limit ? "Results limited to {$limit} matches" : null,
                ],
            ], 'CodeSearchTool');

        } catch (\Exception $e) {
            Log::error('CodeSearchTool: Search failed', [
                'pattern' => $arguments['pattern'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Code search failed: '.$e->getMessage(),
            ], 'CodeSearchTool');
        }
    }

    protected static function parseGrepOutput(string $output, string $basePath, int $limit): array
    {
        if (empty(trim($output))) {
            return [];
        }

        $lines = explode("\n", trim($output));
        $matches = [];

        foreach ($lines as $line) {
            if (count($matches) >= $limit) {
                break;
            }

            if (empty($line)) {
                continue;
            }

            // Parse grep output format: file:line:content
            $parts = explode(':', $line, 3);
            if (count($parts) < 3) {
                continue;
            }

            [$filePath, $lineNumber, $content] = $parts;

            // Convert to relative path
            $relativePath = str_replace($basePath.'/', '', $filePath);

            $matches[] = [
                'file' => $relativePath,
                'line' => (int) $lineNumber,
                'content' => trim($content),
            ];
        }

        return $matches;
    }
}
