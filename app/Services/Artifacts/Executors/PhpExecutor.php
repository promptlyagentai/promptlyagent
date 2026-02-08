<?php

namespace App\Services\Artifacts\Executors;

use App\Models\Artifact;

/**
 * PHP Artifact Executor - Sandboxed PHP Code Execution.
 *
 * Executes PHP code artifacts in Judge0 isolated containers with
 * strict security restrictions. Prevents dangerous function calls
 * and file system access outside the sandbox.
 *
 * Judge0 PHP Sandbox Features:
 * - PHP 8.x in isolated Docker container
 * - Disabled dangerous functions (exec, system, shell_exec, etc.)
 * - No network access (curl, file_get_contents with URLs, etc.)
 * - Limited file system access (tmpfs only)
 * - Memory and time limits enforced
 * - No access to host system or other containers
 *
 * Security Warning Detection:
 * - System command functions (exec, shell_exec, system, passthru)
 * - File system operations (file, fopen, unlink, rmdir, chmod)
 * - Network operations (curl_exec, file_get_contents URLs)
 * - Process control (popen, proc_open)
 *
 * Language Configuration:
 * - Judge0 Language ID: 68 (PHP 8.x)
 * - Configurable via config/code-execution.php
 *
 * @see \App\Services\Artifacts\Executors\Judge0Executor
 */
class PhpExecutor extends Judge0Executor
{
    protected array $supportedFiletypes = ['php'];

    /**
     * Get the Judge0 language ID for PHP
     */
    protected function getLanguageId(): int
    {
        return config('code-execution.language_ids.php', 68);
    }

    /**
     * Get security warnings for PHP execution
     *
     * @return array<string> Warning messages for dangerous functions and operations
     */
    public function getSecurityWarnings(Artifact $artifact): array
    {
        $warnings = [];

        $content = strtolower($this->getContent($artifact));

        // Check for potentially dangerous functions
        $dangerousFunctions = [
            'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open',
            'unlink', 'rmdir', 'file_put_contents', 'fopen', 'file',
            'curl_exec', 'curl_init',
        ];

        foreach ($dangerousFunctions as $func) {
            if (strpos($content, $func) !== false) {
                $warnings[] = sprintf('This code uses potentially dangerous function: %s(). It will run in a secure sandbox.', $func);
            }
        }

        // Check for file system operations
        if (preg_match('/\b(file|fopen|fwrite|fread|unlink|rmdir|mkdir|chmod|chown)\b/', $content)) {
            $warnings[] = 'This code performs file system operations. File access is limited in the sandbox.';
        }

        return $warnings;
    }
}
