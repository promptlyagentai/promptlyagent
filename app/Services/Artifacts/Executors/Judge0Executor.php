<?php

namespace App\Services\Artifacts\Executors;

use App\Models\Artifact;
use App\Services\CodeExecution\Judge0Client;

/**
 * Judge0 Base Executor - Server-Side Sandboxed Code Execution.
 *
 * Base class for language executors that use Judge0 API for secure,
 * sandboxed code execution. Judge0 provides isolated Docker containers
 * with resource limits and network restrictions.
 *
 * Judge0 Sandbox Capabilities:
 * - Isolated Docker containers per execution
 * - CPU and memory limits enforced
 * - Execution time limits (configurable timeouts)
 * - No network access from executed code
 * - Read-only filesystem (tmpfs for writes)
 * - Process isolation and resource cleanup
 * - Support for 60+ programming languages
 *
 * Execution Flow:
 * 1. Submit code + language ID to Judge0 API
 * 2. Judge0 queues execution in isolated container
 * 3. Poll for completion with timeout
 * 4. Retrieve stdout, stderr, execution metadata
 * 5. Render results with execution time/memory stats
 *
 * Subclass Implementation:
 * - Implement getLanguageId() to return Judge0 language ID
 * - Override getSecurityWarnings() for language-specific risks
 * - Set $supportedFiletypes array with file extensions
 *
 * Configuration:
 * - Requires JUDGE0_ENDPOINT and JUDGE0_API_KEY in .env
 * - Language IDs defined in config/code-execution.php
 * - API client configured in Judge0Client service
 *
 * @see \App\Services\CodeExecution\Judge0Client
 * @see https://ce.judge0.com/ Judge0 documentation
 */
abstract class Judge0Executor extends AbstractArtifactExecutor
{
    public function __construct(
        protected Judge0Client $judge0
    ) {}

    /**
     * Get the Judge0 language ID for this executor
     * Must be implemented by subclasses
     */
    abstract protected function getLanguageId(): int;

    /**
     * Check if this executor can execute the artifact
     * Requires Judge0 to be configured
     */
    public function canExecute(Artifact $artifact): bool
    {
        // Check if filetype is supported
        if (! parent::canExecute($artifact)) {
            return false;
        }

        // Check if Judge0 is configured
        return Judge0Client::isConfigured();
    }

    /**
     * Execute the artifact using Judge0
     */
    public function execute(Artifact $artifact): string
    {
        $content = $this->getContent($artifact);

        if (empty($content)) {
            return $this->renderError('No content to execute');
        }

        // Security warnings
        $warnings = $this->getSecurityWarnings($artifact);
        $warningHtml = '';
        foreach ($warnings as $warning) {
            $warningHtml .= $this->renderWarning($warning);
        }

        try {
            // Execute using Judge0
            $result = $this->judge0->execute(
                code: $content,
                languageId: $this->getLanguageId()
            );

            // Render output
            $outputHtml = $this->renderExecutionResult($result);

            return $warningHtml.$outputHtml;
        } catch (\Exception $e) {
            \Log::error('Judge0 execution failed', [
                'artifact_id' => $artifact->id,
                'language_id' => $this->getLanguageId(),
                'error' => $e->getMessage(),
                'executor' => static::class,
            ]);

            return $warningHtml.$this->renderError('Execution failed: '.$e->getMessage());
        }
    }

    /**
     * Render execution result as HTML
     */
    protected function renderExecutionResult($result): string
    {
        $output = $result->getCombinedOutput();

        // Metadata section
        $metadata = '';
        if ($result->time !== null || $result->memory !== null) {
            $timeStr = $result->time !== null ? number_format($result->time, 3).' seconds' : 'N/A';
            $memoryStr = $result->memory !== null ? number_format($result->memory / 1024, 2).' MB' : 'N/A';

            $metadata = sprintf(
                '<div class="text-xs text-zinc-600 dark:text-zinc-400 mb-2 flex gap-4">
                    <span>⏱️ Time: %s</span>
                    <span>💾 Memory: %s</span>
                    <span>✓ Status: %s</span>
                </div>',
                $timeStr,
                $memoryStr,
                htmlspecialchars($result->status ?? 'Unknown', ENT_QUOTES, 'UTF-8')
            );
        }

        // Output section with appropriate styling
        $outputClass = $result->hasErrors() ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-zinc-100';

        return sprintf(
            '<div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 overflow-auto">
                %s
                <pre class="text-sm %s whitespace-pre-wrap">%s</pre>
            </div>',
            $metadata,
            $outputClass,
            htmlspecialchars($output, ENT_QUOTES, 'UTF-8')
        );
    }
}
