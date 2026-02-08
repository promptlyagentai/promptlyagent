<?php

namespace App\Tools\Concerns;

trait SanitizesArtifactContent
{
    /**
     * Sanitize content to remove problematic control characters
     *
     * Removes control characters that break JSON encoding while preserving
     * standard whitespace characters (newlines, tabs, carriage returns).
     */
    protected static function sanitizeContent(string $content): string
    {
        // Remove NULL bytes and other problematic control characters
        // Keep: \n (newline), \r (carriage return), \t (tab)
        // Remove: \x00-\x08, \x0B, \x0C, \x0E-\x1F (other control chars)
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);

        // Ensure proper UTF-8 encoding
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }

        return $content;
    }

    /**
     * Get standard content parameter description with JSON encoding guidance
     */
    protected static function getContentParameterDescription(string $prefix = 'The content'): string
    {
        return "{$prefix} (REQUIRED). CRITICAL: Ensure all content is valid JSON - use \\n for newlines, \\t for tabs, \\\" for quotes, \\\\ for backslashes. Never include raw control characters.";
    }
}
