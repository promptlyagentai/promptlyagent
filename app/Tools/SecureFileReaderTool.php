<?php

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * SecureFileReaderTool - Secure File Access with Automatic Secret Redaction.
 *
 * Prism tool for reading project files with multi-layer security controls.
 * Blocks sensitive files, prevents path traversal, and automatically redacts
 * API keys, tokens, and credentials before returning content.
 *
 * Security Features:
 * - Path traversal prevention (dual validation: ".." check + realpath verification)
 * - Blacklist enforcement (.env, credentials, keys, certificates)
 * - Automatic secret redaction (API keys, tokens, passwords)
 * - File size limits (1MB maximum)
 * - Directory restriction (project root only)
 *
 * Blacklist Patterns:
 * - Environment files (.env, .env.*)
 * - Credentials (credentials.json, secrets/)
 * - Private keys (*.pem, *.key, id_rsa, *.p12, *.pfx)
 * - Password files (.password)
 *
 * Redaction Patterns:
 * - API keys (OpenAI: sk-*, AWS: AKIA*, Slack: xox*, GitHub: ghp_*, GitLab: glpat-*)
 * - Bearer tokens
 * - Password fields
 * - JSON embedded private keys
 * - Secret environment variables
 *
 * Features:
 * - Optional line numbering for code review
 * - UTF-8/ASCII/ISO-8859-1 encoding detection
 * - File metadata (size, line count, encoding)
 * - Status reporting integration
 *
 * Use Cases:
 * - Code review and analysis
 * - Configuration inspection
 * - Documentation reading
 * - Template examination
 *
 * @see \App\Tools\Concerns\SafeJsonResponse
 */
class SecureFileReaderTool
{
    use SafeJsonResponse;

    protected static array $blacklist_patterns = [
        '.env',
        '.env.*',
        'credentials.json',
        'secrets',
        'private-key',
        'api-keys',
        '.password',
        '*.pem',
        '*.key',
        'id_rsa',
        'id_dsa',
        '*.p12',
        '*.pfx',
    ];

    protected static array $sensitive_patterns = [
        '/[A-Z0-9_]+_API_KEY\s*=\s*["\']?([^"\'\s]+)["\']?/i',
        '/[A-Z0-9_]+_SECRET\s*=\s*["\']?([^"\'\s]+)["\']?/i',
        '/[A-Z0-9_]+_PASSWORD\s*=\s*["\']?([^"\'\s]+)["\']?/i',
        '/password\s*=\s*["\']?([^"\'\s]+)["\']?/i',
        '/bearer\s+([a-zA-Z0-9\-._~+\/]+=*)/i',
        '/sk-[a-zA-Z0-9]{48}/i', // OpenAI API keys
        '/xox[baprs]-[a-zA-Z0-9-]+/i', // Slack tokens
        '/ghp_[a-zA-Z0-9]{36}/i', // GitHub tokens
        '/gho_[a-zA-Z0-9]{36}/i', // GitHub OAuth tokens
        '/glpat-[a-zA-Z0-9\-_]{20}/i', // GitLab tokens
        '/AKIA[0-9A-Z]{16}/i', // AWS Access Key
        '/["\']private_key["\']\s*:\s*["\']([^"\']+)["\']/i', // JSON private keys
    ];

    public static function create()
    {
        return Tool::as('secure_file_reader')
            ->for('Read project files with automatic security filtering. Blocks .env and credential files, redacts API keys and secrets automatically.')
            ->withStringParameter('file_path', 'Relative path from project root (e.g., "app/Models/User.php")')
            ->withBooleanParameter('include_line_numbers', 'Include line numbers in output (default: true)', false)
            ->using(function (string $file_path, ?bool $include_line_numbers = null) {
                return static::executeSecureFileRead([
                    'file_path' => $file_path,
                    'include_line_numbers' => $include_line_numbers ?? true,
                ]);
            });
    }

    protected static function executeSecureFileRead(array $arguments = []): string
    {
        // Get StatusReporter for progress updates
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

        try {
            // Validate input
            $validator = Validator::make($arguments, [
                'file_path' => 'required|string|max:500',
                'include_line_numbers' => 'boolean',
            ]);

            if ($validator->fails()) {
                Log::warning('SecureFileReaderTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'SecureFileReaderTool');
            }

            $validated = $validator->validated();
            $filePath = trim($validated['file_path'], '/ ');
            $includeLineNumbers = $validated['include_line_numbers'];

            if ($statusReporter) {
                $statusReporter->report('secure_file_reader', "Reading file: {$filePath}", true, false);
            }

            if (str_contains($filePath, '..')) {
                Log::warning('SecureFileReaderTool: Path traversal attempt with .. blocked', [
                    'file_path' => $filePath,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid file path: path traversal not allowed',
                ], 'SecureFileReaderTool');
            }

            // Check blacklist
            if (static::isBlacklisted($filePath)) {
                Log::warning('SecureFileReaderTool: Blacklisted file access blocked', [
                    'file_path' => $filePath,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Access to this file is restricted for security reasons',
                    'file_path' => $filePath,
                ], 'SecureFileReaderTool');
            }

            // Validate path
            $basePath = base_path();
            $fullPath = realpath($basePath.'/'.$filePath);

            if ($fullPath === false) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'File does not exist',
                    'file_path' => $filePath,
                ], 'SecureFileReaderTool');
            }

            if (! str_starts_with($fullPath, $basePath)) {
                Log::warning('SecureFileReaderTool: Path traversal attempt blocked', [
                    'file_path' => $filePath,
                    'full_path' => $fullPath,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid file path (path traversal detected)',
                ], 'SecureFileReaderTool');
            }

            // Check if it's a file (not directory)
            if (! is_file($fullPath)) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Path is not a file',
                    'file_path' => $filePath,
                ], 'SecureFileReaderTool');
            }

            // Check file size (limit to 1MB)
            $fileSize = filesize($fullPath);
            if ($fileSize > 1024 * 1024) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'File too large (maximum 1MB)',
                    'file_size_bytes' => $fileSize,
                ], 'SecureFileReaderTool');
            }

            if ($statusReporter) {
                $statusReporter->report('secure_file_reader', 'Processing file content ('.round($fileSize / 1024, 1).' KB)...', false, false);
            }

            // Read file content
            $content = File::get($fullPath);

            // Redact sensitive content
            $redactedContent = static::redactSensitiveContent($content);

            // Add line numbers if requested
            if ($includeLineNumbers) {
                $lines = explode("\n", $redactedContent);
                $numberedLines = [];
                $lineNumber = 1;
                foreach ($lines as $line) {
                    $numberedLines[] = sprintf('%4d  %s', $lineNumber++, $line);
                }
                $redactedContent = implode("\n", $numberedLines);
            }

            $lineCount = substr_count($redactedContent, "\n") + 1;

            if ($statusReporter) {
                $statusReporter->report('secure_file_reader', "File loaded: {$lineCount} lines", false, false);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'file_path' => $filePath,
                    'content' => $redactedContent,
                    'file_size_bytes' => $fileSize,
                    'line_count' => $lineCount,
                    'encoding' => mb_detect_encoding($content, ['UTF-8', 'ASCII', 'ISO-8859-1'], true),
                    'note' => 'Sensitive data has been automatically redacted',
                ],
            ], 'SecureFileReaderTool');

        } catch (\Exception $e) {
            Log::error('SecureFileReaderTool: File read failed', [
                'file_path' => $arguments['file_path'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'File read failed: '.$e->getMessage(),
            ], 'SecureFileReaderTool');
        }
    }

    protected static function isBlacklisted(string $filePath): bool
    {
        $fileName = basename($filePath);
        $filePathLower = strtolower($filePath);

        foreach (static::$blacklist_patterns as $pattern) {
            // Use fnmatch for wildcard patterns
            if (fnmatch($pattern, $fileName, FNM_CASEFOLD)) {
                return true;
            }

            // Also check against full path
            if (fnmatch($pattern, $filePathLower, FNM_CASEFOLD)) {
                return true;
            }

            // Check for common secret directories
            if (str_contains($filePathLower, 'secret') ||
                str_contains($filePathLower, 'credential') ||
                str_contains($filePathLower, 'private')) {
                return true;
            }
        }

        return false;
    }

    protected static function redactSensitiveContent(string $content): string
    {
        $redactedContent = $content;

        foreach (static::$sensitive_patterns as $pattern) {
            $redactedContent = preg_replace_callback($pattern, function ($matches) {
                // Keep the key name but redact the value
                if (isset($matches[0]) && isset($matches[1])) {
                    return str_replace($matches[1], '[REDACTED]', $matches[0]);
                }

                return '[REDACTED]';
            }, $redactedContent);
        }

        return $redactedContent;
    }
}
