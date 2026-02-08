<?php

namespace App\Services\Artifacts\Renderers;

use App\Models\Artifact;

/**
 * Markdown Artifact Renderer - Client-Side Markdown to HTML.
 *
 * Renders Markdown artifacts by delegating to client-side JavaScript
 * for markdown-to-HTML conversion. Uses Alpine.js component for
 * reactive rendering with compact styling.
 *
 * Rendering Strategy:
 * - Markdown source hidden in <span x-ref="source">
 * - Alpine.js markdownRenderer() component converts to HTML
 * - Rendered HTML injected into <div x-ref="target">
 * - Client-side processing ensures consistent rendering
 *
 * Styling:
 * - .markdown-content class for display pages
 * - .markdown-compact class for tighter vertical spacing
 * - .markdown-preview class for card previews
 * - Dark mode support built into CSS
 *
 * Preview Mode:
 * - Truncates markdown source to 500 characters
 * - Markdown still converted to HTML client-side
 * - May result in incomplete markdown structure
 * - Uses smaller text size for previews
 *
 * Download Format:
 * - Returns raw markdown content unchanged
 * - Proper text/markdown MIME type
 * - .md file extension
 *
 * JavaScript Requirements:
 * - markdownRenderer() Alpine component must be defined
 * - Markdown parsing library loaded (e.g., marked.js)
 * - Component handles rendering lifecycle
 *
 * @see \App\Services\Artifacts\Renderers\AbstractArtifactRenderer
 */
class MarkdownRenderer extends AbstractArtifactRenderer
{
    /**
     * Render markdown content as HTML
     */
    public function render(Artifact $artifact): string
    {
        $content = $this->raw($artifact);

        // Return markdown wrapped for client-side rendering
        // Client-side marked.js handles internal URL resolution (asset://, attachment://)
        // htmlspecialchars prevents XSS. JavaScript textContent auto-decodes &amp; to &
        return sprintf(
            '<div class="markdown-content markdown-compact" x-data="markdownRenderer()"><span x-ref="source" class="hidden">%s</span><div x-ref="target" class="markdown" x-html="renderedHtml"></div></div>',
            htmlspecialchars($content, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Render markdown preview
     */
    public function renderPreview(Artifact $artifact, int $maxLength = 500): string
    {
        $content = $this->raw($artifact);

        // Return markdown wrapped for client-side rendering
        // Client-side marked.js handles internal URL resolution (asset://, attachment://)
        return sprintf(
            '<div class="markdown-preview" x-data="markdownRenderer()"><span x-ref="source" class="hidden">%s</span><div x-ref="target" class="markdown text-sm" x-html="renderedHtml"></div></div>',
            htmlspecialchars($content, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Get MIME type for markdown files
     */
    public function getMimeType(Artifact $artifact): string
    {
        return 'text/markdown; charset=utf-8';
    }

    /**
     * Get file extension for markdown files
     */
    public function getFileExtension(Artifact $artifact): string
    {
        return 'md';
    }
}
