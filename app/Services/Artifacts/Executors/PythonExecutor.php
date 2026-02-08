<?php

namespace App\Services\Artifacts\Executors;

use App\Models\Artifact;

/**
 * Python Artifact Executor - Sandboxed Python Code Execution.
 *
 * Executes Python code artifacts in Judge0 isolated containers with
 * restricted system access, file operations, and network capabilities.
 *
 * Judge0 Python Sandbox Features:
 * - Python 3.x in isolated Docker container
 * - No network access (urllib, requests, socket blocked)
 * - Limited file system access (tmpfs only)
 * - Restricted subprocess and system commands
 * - eval() and exec() sandboxed but dangerous
 * - Memory and time limits enforced
 * - No access to host system or other containers
 *
 * Security Warning Detection:
 * - System commands (os.system, subprocess)
 * - Dynamic code execution (eval, exec, compile, __import__)
 * - File operations (open, file functions)
 * - Network operations (urllib, requests, socket)
 *
 * Language Configuration:
 * - Judge0 Language ID: 71 (Python 3.x)
 * - Configurable via config/code-execution.php
 *
 * @see \App\Services\Artifacts\Executors\Judge0Executor
 */
class PythonExecutor extends Judge0Executor
{
    protected array $supportedFiletypes = ['py', 'python'];

    /**
     * Get the Judge0 language ID for Python
     */
    protected function getLanguageId(): int
    {
        return config('code-execution.language_ids.python', 71);
    }

    /**
     * Get security warnings for Python execution
     *
     * @return array<string> Warning messages for dangerous modules and operations
     */
    public function getSecurityWarnings(Artifact $artifact): array
    {
        $warnings = [];

        $content = strtolower($this->getContent($artifact));

        // Check for potentially dangerous modules
        $dangerousImports = [
            'os.system', 'subprocess', 'eval(', 'exec(',
            '__import__', 'compile(', 'open(',
        ];

        foreach ($dangerousImports as $import) {
            if (strpos($content, $import) !== false) {
                $warnings[] = sprintf('This code uses potentially dangerous operation: %s. It will run in a secure sandbox.', $import);
            }
        }

        // Check for file operations
        if (preg_match('/\bopen\s*\(/', $content) || strpos($content, 'file(') !== false) {
            $warnings[] = 'This code performs file operations. File access is limited in the sandbox.';
        }

        // Check for network operations
        if (preg_match('/\b(urllib|requests|socket)\b/', $content)) {
            $warnings[] = 'This code may perform network operations. Network access is restricted in the sandbox.';
        }

        return $warnings;
    }
}
