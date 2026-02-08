<?php

namespace App\Services\Artifacts\Renderers;

use App\Models\Artifact;
use Illuminate\Support\Str;

/**
 * Abstract Artifact Renderer - Base Renderer Contract and Default Implementation.
 *
 * Provides default rendering implementations for all artifact types. Subclasses
 * override specific methods to provide specialized rendering (e.g., CSV as tables,
 * JSON with formatting, Markdown as HTML).
 *
 * Renderer Contract (from ArtifactRendererInterface):
 * - **render()**: Full artifact rendering for display pages (returns HTML)
 * - **renderPreview()**: Truncated preview for cards/lists (returns HTML)
 * - **forDownload()**: Formatted content suitable for file downloads
 * - **raw()**: Unmodified artifact content as stored
 * - **getMimeType()**: Content-Type header for downloads
 * - **getFileExtension()**: File extension for download filenames
 *
 * Default Behavior:
 * - Wraps content in <pre><code> tags with language class
 * - Sanitizes HTML entities to prevent XSS
 * - Truncates preview content using Str::limit()
 * - Returns text/plain MIME type
 * - Uses artifact filetype as extension
 *
 * Customization Patterns:
 * - Override render() for specialized HTML output (tables, formatted text)
 * - Override getMimeType() for proper Content-Type headers
 * - Override getFileExtension() for correct download filenames
 * - Override forDownload() to reformat content (e.g., pretty-print JSON)
 *
 * @see \App\Services\Artifacts\Renderers\ArtifactRendererInterface
 * @see \App\Services\Artifacts\Renderers\CsvRenderer
 * @see \App\Services\Artifacts\Renderers\JsonRenderer
 * @see \App\Services\Artifacts\Renderers\MarkdownRenderer
 */
abstract class AbstractArtifactRenderer implements ArtifactRendererInterface
{
    /**
     * Default render implementation - returns content wrapped in pre/code tags
     */
    public function render(Artifact $artifact): string
    {
        $content = $this->raw($artifact);
        $language = $artifact->filetype ?: 'text';

        return sprintf(
            '<pre class="text-sm font-mono overflow-x-auto"><code class="language-%s">%s</code></pre>',
            htmlspecialchars($language, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($content, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Default preview implementation - truncates and wraps
     */
    public function renderPreview(Artifact $artifact, int $maxLength = 500): string
    {
        $content = $this->raw($artifact);
        $truncated = Str::limit($content, $maxLength);
        $language = $artifact->filetype ?: 'text';

        return sprintf(
            '<pre class="text-sm font-mono overflow-x-auto"><code class="language-%s">%s</code></pre>',
            htmlspecialchars($language, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($truncated, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Default download implementation - returns raw content
     */
    public function forDownload(Artifact $artifact): string
    {
        return $this->raw($artifact);
    }

    /**
     * Get raw content from artifact
     */
    public function raw(Artifact $artifact): string
    {
        return $artifact->content ?? '';
    }

    /**
     * Default MIME type
     */
    public function getMimeType(Artifact $artifact): string
    {
        return 'text/plain; charset=utf-8';
    }

    /**
     * Default file extension
     */
    public function getFileExtension(Artifact $artifact): string
    {
        return $artifact->filetype ?: 'txt';
    }
}
