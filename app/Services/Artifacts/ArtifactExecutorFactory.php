<?php

namespace App\Services\Artifacts;

use App\Models\Artifact;
use App\Services\Artifacts\Executors\AbstractArtifactExecutor;
use App\Services\Artifacts\Executors\HtmlExecutor;
use App\Services\Artifacts\Executors\PhpExecutor;
use App\Services\Artifacts\Executors\PythonExecutor;

/**
 * Artifact Executor Factory - Dynamic Executor Resolution.
 *
 * Registry-based factory for resolving appropriate code executors based on
 * artifact file type. Provides extensible executor system with runtime
 * registration and dependency injection support.
 *
 * Executor Resolution Strategy:
 * 1. Extract artifact filetype (normalized to lowercase)
 * 2. Lookup executor class in static registry
 * 3. Return null if no executor registered for filetype
 * 4. Resolve executor via Laravel container for DI
 * 5. Verify executor capability via canExecute()
 *
 * Built-in Executors:
 * - **HtmlExecutor**: html, htm → Renders HTML in iframe sandbox
 * - **PhpExecutor**: php → Executes PHP code with security restrictions
 * - **PythonExecutor**: py, python → Executes Python via external interpreter
 *
 * Registry Pattern:
 * - Static registry allows runtime executor registration
 * - Custom executors via register() method
 * - Package/plugin executors can extend supported filetypes
 * - All executors must extend AbstractArtifactExecutor
 *
 * Extensibility:
 * ```php
 * ArtifactExecutorFactory::register('js', JavaScriptExecutor::class);
 * ArtifactExecutorFactory::register('rb', RubyExecutor::class);
 * ```
 *
 * Security:
 * - Executors implement their own sandboxing/restrictions
 * - Container resolution allows middleware injection
 * - canExecute() provides pre-execution validation
 *
 * @see \App\Services\Artifacts\Executors\AbstractArtifactExecutor
 * @see \App\Services\Artifacts\Executors\HtmlExecutor
 * @see \App\Services\Artifacts\Executors\PhpExecutor
 * @see \App\Services\Artifacts\Executors\PythonExecutor
 */
class ArtifactExecutorFactory
{
    /**
     * Mapping of filetypes to executor classes
     */
    protected static array $executors = [
        // HTML
        'html' => HtmlExecutor::class,
        'htm' => HtmlExecutor::class,

        // PHP
        'php' => PhpExecutor::class,

        // Python
        'py' => PythonExecutor::class,
        'python' => PythonExecutor::class,
    ];

    /**
     * Get the appropriate executor for a artifact
     * Returns null if no executor is available
     */
    public static function getExecutor(Artifact $artifact): ?AbstractArtifactExecutor
    {
        $filetype = strtolower($artifact->filetype ?? '');

        $executorClass = static::$executors[$filetype] ?? null;

        if (! $executorClass) {
            return null;
        }

        // Resolve executor from container for proper dependency injection
        return app($executorClass);
    }

    /**
     * Register a custom executor for a filetype
     */
    public static function register(string $filetype, string $executorClass): void
    {
        if (! is_subclass_of($executorClass, AbstractArtifactExecutor::class)) {
            throw new \InvalidArgumentException(
                'Executor class must extend AbstractArtifactExecutor'
            );
        }

        static::$executors[strtolower($filetype)] = $executorClass;
    }

    /**
     * Check if an executor is registered for a filetype
     */
    public static function hasExecutor(string $filetype): bool
    {
        return isset(static::$executors[strtolower($filetype)]);
    }

    /**
     * Check if a artifact can be executed
     */
    public static function canExecute(Artifact $artifact): bool
    {
        $executor = static::getExecutor($artifact);

        return $executor !== null && $executor->canExecute($artifact);
    }

    /**
     * Get all registered filetypes
     */
    public static function getRegisteredFiletypes(): array
    {
        return array_keys(static::$executors);
    }
}
