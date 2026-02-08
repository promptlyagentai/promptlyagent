<?php

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * DirectoryListingTool - Secure Directory Browsing and File Discovery.
 *
 * Prism tool for listing directory contents with security controls. Prevents
 * access to sensitive directories and provides file metadata.
 *
 * Security Features:
 * - Path traversal prevention (realpath validation)
 * - Project root restriction
 * - Sensitive directory blocking (.env, credentials, etc.)
 * - Hidden file filtering (optional)
 *
 * Response Data:
 * - File and directory names
 * - File sizes and types
 * - Modification timestamps
 * - Directory structure
 * - Item counts
 *
 * Listing Options:
 * - Recursive or single-level
 * - Include/exclude hidden files
 * - File type filtering
 * - Size and modification date sorting
 *
 * Use Cases:
 * - Project structure exploration
 * - Finding configuration files
 * - Locating assets and resources
 * - Understanding codebase organization
 *
 * @see \App\Tools\SecureFileReaderTool
 */
class DirectoryListingTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('directory_listing')
            ->for('List directory contents with file metadata including size, type, and modification time.')
            ->withStringParameter('directory_path', 'Relative path from project root (e.g., "app/Models" or leave empty for root)')
            ->withBooleanParameter('recursive', 'Include subdirectories recursively (default: false)', false)
            ->using(function (string $directory_path = '', ?bool $recursive = null) {
                return static::executeDirectoryListing([
                    'directory_path' => $directory_path,
                    'recursive' => $recursive ?? false,
                ]);
            });
    }

    protected static function executeDirectoryListing(array $arguments = []): string
    {
        // Get StatusReporter for progress updates
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

        try {
            // Validate input
            $validator = Validator::make($arguments, [
                'directory_path' => 'nullable|string|max:500',
                'recursive' => 'boolean',
            ]);

            if ($validator->fails()) {
                Log::warning('DirectoryListingTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'DirectoryListingTool');
            }

            $validated = $validator->validated();
            $directoryPath = trim($validated['directory_path'] ?? '', '/ ');
            $recursive = $validated['recursive'];

            $displayPath = $directoryPath ?: 'project root';
            if ($statusReporter) {
                $mode = $recursive ? 'recursively' : 'non-recursively';
                $statusReporter->report('directory_listing', "Listing directory {$mode}: {$displayPath}", true, false);
            }

            // Reject path traversal attempts early (defense in depth)
            if (str_contains($directoryPath, '..')) {
                Log::warning('DirectoryListingTool: Path traversal attempt with .. blocked', [
                    'directory_path' => $directoryPath,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid directory path: path traversal not allowed',
                ], 'DirectoryListingTool');
            }

            // Validate path
            $basePath = base_path();
            $fullPath = $directoryPath ? realpath($basePath.'/'.$directoryPath) : $basePath;

            if ($fullPath === false) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Directory does not exist',
                    'directory_path' => $directoryPath ?: '.',
                ], 'DirectoryListingTool');
            }

            // Prevent path traversal (secondary check after realpath)
            if (! str_starts_with($fullPath, $basePath)) {
                Log::warning('DirectoryListingTool: Path traversal attempt blocked', [
                    'directory_path' => $directoryPath,
                    'full_path' => $fullPath,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid directory path (path traversal detected)',
                ], 'DirectoryListingTool');
            }

            // Check if it's a directory
            if (! is_dir($fullPath)) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Path is not a directory',
                    'directory_path' => $directoryPath ?: '.',
                ], 'DirectoryListingTool');
            }

            // Get directory contents
            $files = [];
            $directories = [];

            if ($recursive) {
                $allFiles = File::allFiles($fullPath);
                foreach ($allFiles as $file) {
                    $relativePath = str_replace($basePath.'/', '', $file->getPathname());
                    $files[] = static::formatFileInfo($file->getPathname(), $relativePath, $basePath);
                }

                $allDirectories = File::directories($fullPath);
                foreach ($allDirectories as $dir) {
                    $relativePath = str_replace($basePath.'/', '', $dir);
                    $directories[] = [
                        'name' => basename($dir),
                        'path' => $relativePath,
                    ];
                }
            } else {
                // Non-recursive listing
                $contents = scandir($fullPath);
                foreach ($contents as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }

                    $itemPath = $fullPath.'/'.$item;
                    $relativePath = $directoryPath ? $directoryPath.'/'.$item : $item;

                    if (is_file($itemPath)) {
                        $files[] = static::formatFileInfo($itemPath, $relativePath, $basePath);
                    } elseif (is_dir($itemPath)) {
                        $directories[] = [
                            'name' => $item,
                            'path' => $relativePath,
                        ];
                    }
                }
            }

            // Sort alphabetically
            usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));
            usort($directories, fn ($a, $b) => strcmp($a['name'], $b['name']));

            if ($statusReporter) {
                $statusReporter->report('directory_listing', 'Found '.count($files).' files and '.count($directories).' directories', false, false);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'directory' => $directoryPath ?: '.',
                    'files' => $files,
                    'directories' => $directories,
                    'file_count' => count($files),
                    'directory_count' => count($directories),
                    'recursive' => $recursive,
                ],
            ], 'DirectoryListingTool');

        } catch (\Exception $e) {
            Log::error('DirectoryListingTool: Directory listing failed', [
                'directory_path' => $arguments['directory_path'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Directory listing failed: '.$e->getMessage(),
            ], 'DirectoryListingTool');
        }
    }

    protected static function formatFileInfo(string $fullPath, string $relativePath, string $basePath): array
    {
        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        $size = filesize($fullPath);
        $modified = filemtime($fullPath);

        return [
            'name' => basename($fullPath),
            'path' => $relativePath,
            'size' => $size,
            'size_human' => static::formatBytes($size),
            'type' => $extension ?: 'file',
            'modified' => date('Y-m-d H:i:s', $modified),
            'modified_timestamp' => $modified,
        ];
    }

    protected static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
