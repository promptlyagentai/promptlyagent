<?php

namespace App\Services\Artifacts\Renderers;

use App\Models\Artifact;
use Illuminate\Support\Str;

/**
 * JSON Artifact Renderer - Formatted JSON Display and Export.
 *
 * Renders JSON artifacts with pretty-printing for display and download.
 * Validates JSON structure and gracefully handles malformed data.
 *
 * Formatting Features:
 * - Pretty-print with consistent indentation
 * - Unescaped slashes for readability (/ not \/)
 * - Unescaped Unicode characters (preserves non-ASCII)
 * - Syntax highlighting via language-json class
 *
 * Validation:
 * - Attempts to parse JSON before rendering
 * - Falls back to raw content if invalid
 * - No error thrown for malformed JSON
 * - Allows viewing of broken JSON files
 *
 * Preview Mode:
 * - Formats JSON before truncation
 * - Truncates to 500 characters by default
 * - May result in incomplete JSON structure in preview
 *
 * Download Format:
 * - Pretty-printed JSON for better readability
 * - Proper application/json MIME type
 * - .json file extension
 *
 * @see \App\Services\Artifacts\Renderers\AbstractArtifactRenderer
 */
class JsonRenderer extends AbstractArtifactRenderer
{
    /**
     * Render JSON with pretty formatting
     */
    public function render(Artifact $artifact): string
    {
        $content = $this->raw($artifact);
        $formatted = $this->formatJson($content);

        return sprintf(
            '<pre class="text-sm font-mono overflow-x-auto"><code class="language-json">%s</code></pre>',
            htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Render JSON preview (truncated but formatted)
     */
    public function renderPreview(Artifact $artifact, int $maxLength = 500): string
    {
        $content = $this->raw($artifact);
        $formatted = $this->formatJson($content);
        $truncated = Str::limit($formatted, $maxLength);

        return sprintf(
            '<pre class="text-sm font-mono overflow-x-auto"><code class="language-json">%s</code></pre>',
            htmlspecialchars($truncated, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Format JSON for download with pretty print
     */
    public function forDownload(Artifact $artifact): string
    {
        return $this->formatJson($this->raw($artifact));
    }

    /**
     * Format JSON content
     */
    protected function formatJson(string $content): string
    {
        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // If JSON is invalid, return as-is
        return $content;
    }

    /**
     * Get MIME type for JSON files
     */
    public function getMimeType(Artifact $artifact): string
    {
        return 'application/json; charset=utf-8';
    }

    /**
     * Get file extension for JSON files
     */
    public function getFileExtension(Artifact $artifact): string
    {
        return 'json';
    }
}
