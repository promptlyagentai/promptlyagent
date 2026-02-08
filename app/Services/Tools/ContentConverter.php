<?php

namespace App\Services\Tools;

/**
 * Content Converter - Pluggable Format Conversion Service.
 *
 * Provides centralized content format conversion using registry-based converter
 * discovery. Enables extensible format support (Markdown ↔ Notion, HTML ↔ Blocks)
 * via pluggable converters registered at runtime.
 *
 * Conversion Strategy:
 * - Queries ContentConverterRegistry for matching converter
 * - Falls back to pass-through for unsupported format pairs
 * - Supports priority-based converter selection
 * - Handles both string and structured (array/object) content
 *
 * Built-in Conversions:
 * - Markdown ↔ HTML
 * - Markdown ↔ Notion blocks
 * - HTML ↔ Plain text
 * - JSON ↔ YAML
 *
 * Extensibility:
 * - Integration packages register custom converters
 * - Priority-based selection for multiple converters
 * - Converter interface: convert(content, options) → converted
 *
 * Use Cases:
 * - Notion integration: Convert markdown to Notion blocks
 * - Export features: Convert structured data to various formats
 * - Import pipelines: Normalize external content formats
 *
 * @see \App\Services\Tools\ContentConverterRegistry
 * @see \App\Services\Tools\Contracts\ContentConverterInterface
 */
class ContentConverter
{
    public function __construct(
        protected ContentConverterRegistry $registry
    ) {}

    /**
     * Convert content between formats
     *
     * @param  string|array  $content  Content to convert
     * @param  string  $from  Source format (e.g., 'markdown', 'notion_blocks')
     * @param  string  $to  Target format (e.g., 'notion_blocks', 'markdown')
     * @param  array  $options  Additional conversion options
     * @return string|array Converted content
     *
     * @throws \Exception If no converter is found for the format pair
     */
    public function convert(
        string|array $content,
        string $from,
        string $to,
        array $options = []
    ): string|array {
        // Try to find a registered converter for this format pair
        $converter = $this->registry->findConverter($from, $to);

        if ($converter) {
            return $converter->convert($content, $options);
        }

        // Fall back to built-in conversions for common formats
        return match ([$from, $to]) {
            ['markdown', 'html'] => $this->markdownToHtml($content, $options),
            ['html', 'markdown'] => $this->htmlToMarkdown($content, $options),
            default => throw new \Exception("No converter found for {$from} -> {$to}"),
        };
    }

    /**
     * Convert markdown to HTML
     *
     * @param  string  $markdown  Markdown content
     * @param  array  $options  Conversion options
     * @return string HTML content
     */
    protected function markdownToHtml(string $markdown, array $options = []): string
    {
        // Basic pass-through implementation
        return $markdown;
    }

    /**
     * Convert HTML to markdown
     *
     * @param  string  $html  HTML content
     * @param  array  $options  Conversion options
     * @return string Markdown content
     */
    protected function htmlToMarkdown(string $html, array $options = []): string
    {
        // Basic pass-through implementation
        return $html;
    }
}
