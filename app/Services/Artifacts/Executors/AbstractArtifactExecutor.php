<?php

namespace App\Services\Artifacts\Executors;

use App\Models\Artifact;

/**
 * Abstract Artifact Executor - Base Executor Contract.
 *
 * Defines contract and provides shared functionality for all artifact code
 * executors. Subclasses implement execute() for language-specific code
 * execution with appropriate sandboxing and security measures.
 *
 * Executor Contract:
 * - **canExecute()**: Verify executor supports artifact filetype
 * - **execute()**: Run artifact code and return HTML output
 * - **getSecurityWarnings()**: Provide security warnings to display to users
 *
 * Security Model:
 * ⚠️ **CRITICAL**: Code execution is inherently dangerous
 * - Each executor MUST implement appropriate sandboxing
 * - Network access, file system access, dangerous functions restricted
 * - Execution timeouts enforced to prevent infinite loops
 * - Output sanitization required before rendering
 *
 * Security Warning System:
 * - getSecurityWarnings() returns array of warning messages
 * - Warnings displayed to user before execution
 * - Users must explicitly acknowledge risks
 * - Override in subclasses for executor-specific warnings
 *
 * Helper Methods:
 * - **renderError()**: Format execution errors with styling
 * - **renderWarning()**: Format security warnings with styling
 * - **getContent()**: Extract artifact content safely
 *
 * Implementation Requirements:
 * - Set $supportedFiletypes array (lowercase extensions)
 * - Implement execute() with sandboxing measures
 * - Override getSecurityWarnings() with specific risks
 * - Sanitize all output before returning HTML
 *
 * @see \App\Services\Artifacts\Executors\PhpExecutor
 * @see \App\Services\Artifacts\Executors\PythonExecutor
 * @see \App\Services\Artifacts\Executors\HtmlExecutor
 */
abstract class AbstractArtifactExecutor implements ArtifactExecutorInterface
{
    /**
     * Supported filetypes for this executor
     */
    protected array $supportedFiletypes = [];

    /**
     * Check if this executor can handle the given artifact
     */
    public function canExecute(Artifact $artifact): bool
    {
        $filetype = strtolower($artifact->filetype ?? '');

        return in_array($filetype, $this->supportedFiletypes);
    }

    /**
     * Get security warnings for executing this artifact type
     * Override in subclasses to provide specific warnings
     *
     * @return array<string> Array of warning messages to display to user
     */
    public function getSecurityWarnings(Artifact $artifact): array
    {
        return [];
    }

    /**
     * Get the artifact content
     */
    protected function getContent(Artifact $artifact): string
    {
        return $artifact->content ?? '';
    }

    /**
     * Wrap content in an error display
     */
    protected function renderError(string $message): string
    {
        return sprintf(
            '<div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                <h3 class="text-lg font-semibold text-red-800 dark:text-red-200 mb-2">Execution Error</h3>
                <p class="text-red-700 dark:text-red-300">%s</p>
            </div>',
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Wrap content in a warning display
     */
    protected function renderWarning(string $message): string
    {
        return sprintf(
            '<div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg mb-4">
                <h3 class="text-lg font-semibold text-yellow-800 dark:text-yellow-200 mb-2">⚠️ Security Warning</h3>
                <p class="text-yellow-700 dark:text-yellow-300">%s</p>
            </div>',
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Abstract method that must be implemented by subclasses
     */
    abstract public function execute(Artifact $artifact): string;
}
