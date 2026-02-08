<?php

namespace App\Services\Tools\Contracts;

/**
 * Content Converter Interface
 *
 * Defines the contract for content format converters used across integrations.
 * Implementations handle conversion between specific formats (e.g., Markdown to Notion blocks).
 */
interface ContentConverterInterface
{
    /**
     * Check if this converter supports converting from one format to another
     *
     * @param  string  $from  Source format (e.g., 'markdown', 'notion_blocks')
     * @param  string  $to  Target format (e.g., 'notion_blocks', 'markdown')
     * @return bool True if conversion is supported
     */
    public function supports(string $from, string $to): bool;

    /**
     * Convert content from one format to another
     *
     * @param  string|array  $content  Content to convert
     * @param  array  $options  Additional conversion options
     * @return string|array Converted content
     */
    public function convert(string|array $content, array $options = []): string|array;

    /**
     * Get converter priority (higher priority converters are tried first)
     * Useful when multiple converters support the same format pair
     *
     * @return int Priority level (0-100, default 50)
     */
    public function getPriority(): int;
}
