<?php

namespace App\Services\Artifacts;

use App\Models\Artifact;
use App\Services\Artifacts\Renderers\AbstractArtifactRenderer;
use App\Services\Artifacts\Renderers\CodeRenderer;
use App\Services\Artifacts\Renderers\CsvRenderer;
use App\Services\Artifacts\Renderers\JsonRenderer;
use App\Services\Artifacts\Renderers\MarkdownRenderer;

/**
 * Artifact Renderer Factory - Dynamic Renderer Resolution with Fallback.
 *
 * Registry-based factory for resolving appropriate content renderers based on
 * artifact file type. Provides graceful fallback to CodeRenderer for unknown
 * file types, ensuring all artifacts can be displayed.
 *
 * Renderer Resolution Strategy:
 * 1. Extract artifact filetype (normalized to lowercase)
 * 2. Lookup renderer class in static registry
 * 3. Fall back to CodeRenderer (default) if no match found
 * 4. Instantiate renderer (no DI required for renderers)
 * 5. Return renderer instance ready for render() call
 *
 * Built-in Renderers:
 * - **MarkdownRenderer**: md, markdown → Renders Markdown with syntax highlighting
 * - **JsonRenderer**: json → Pretty-prints and validates JSON
 * - **CsvRenderer**: csv → Renders as HTML table with styling
 * - **CodeRenderer**: ALL code files → Syntax highlighting via highlight.js
 *
 * Difference from Executor:
 * - **Renderers**: Display artifact content (safe, read-only presentation)
 * - **Executors**: Run artifact code (requires sandboxing and security)
 *
 * Registry Pattern:
 * - Static registry allows runtime renderer registration
 * - Custom renderers via register() method
 * - Package/plugin renderers can extend supported filetypes
 * - All renderers must extend AbstractArtifactRenderer
 * - CodeRenderer serves as universal fallback
 *
 * Extensibility:
 * ```php
 * ArtifactRendererFactory::register('rst', ReStructuredTextRenderer::class);
 * ArtifactRendererFactory::register('asciidoc', AsciiDocRenderer::class);
 * ```
 *
 * @see \App\Services\Artifacts\Renderers\AbstractArtifactRenderer
 * @see \App\Services\Artifacts\Renderers\CodeRenderer
 * @see \App\Services\Artifacts\Renderers\MarkdownRenderer
 * @see \App\Services\Artifacts\Renderers\JsonRenderer
 * @see \App\Services\Artifacts\Renderers\CsvRenderer
 */
class ArtifactRendererFactory
{
    /**
     * Mapping of filetypes to renderer classes
     */
    protected static array $renderers = [
        // Markdown
        'md' => MarkdownRenderer::class,
        'markdown' => MarkdownRenderer::class,

        // JSON
        'json' => JsonRenderer::class,

        // CSV
        'csv' => CsvRenderer::class,

        // Code files (all use CodeRenderer which provides proper MIME types)
        'php' => CodeRenderer::class,
        'js' => CodeRenderer::class,
        'javascript' => CodeRenderer::class,
        'ts' => CodeRenderer::class,
        'typescript' => CodeRenderer::class,
        'py' => CodeRenderer::class,
        'python' => CodeRenderer::class,
        'java' => CodeRenderer::class,
        'c' => CodeRenderer::class,
        'cpp' => CodeRenderer::class,
        'cs' => CodeRenderer::class,
        'csharp' => CodeRenderer::class,
        'go' => CodeRenderer::class,
        'rust' => CodeRenderer::class,
        'rb' => CodeRenderer::class,
        'ruby' => CodeRenderer::class,
        'swift' => CodeRenderer::class,
        'kt' => CodeRenderer::class,
        'kotlin' => CodeRenderer::class,
        'scala' => CodeRenderer::class,
        'html' => CodeRenderer::class,
        'css' => CodeRenderer::class,
        'scss' => CodeRenderer::class,
        'xml' => CodeRenderer::class,
        'sql' => CodeRenderer::class,
        'sh' => CodeRenderer::class,
        'bash' => CodeRenderer::class,
        'yaml' => CodeRenderer::class,
        'yml' => CodeRenderer::class,
    ];

    /**
     * Default renderer for unknown filetypes
     */
    protected static string $defaultRenderer = CodeRenderer::class;

    /**
     * Get the appropriate renderer for a artifact
     */
    public static function getRenderer(Artifact $artifact): AbstractArtifactRenderer
    {
        $filetype = strtolower($artifact->filetype ?? '');

        $rendererClass = static::$renderers[$filetype] ?? static::$defaultRenderer;

        return new $rendererClass;
    }

    /**
     * Register a custom renderer for a filetype
     */
    public static function register(string $filetype, string $rendererClass): void
    {
        if (! is_subclass_of($rendererClass, AbstractArtifactRenderer::class)) {
            throw new \InvalidArgumentException(
                'Renderer class must extend AbstractArtifactRenderer'
            );
        }

        static::$renderers[strtolower($filetype)] = $rendererClass;
    }

    /**
     * Check if a renderer is registered for a filetype
     */
    public static function hasRenderer(string $filetype): bool
    {
        return isset(static::$renderers[strtolower($filetype)]);
    }

    /**
     * Get all registered filetypes
     */
    public static function getRegisteredFiletypes(): array
    {
        return array_keys(static::$renderers);
    }
}
