<?php

namespace App\Services;

/**
 * Service to extract URLs from text content
 */
class UrlExtractorService
{
    /**
     * Maximum text length to process for URL extraction
     * Used to prevent ReDoS attacks with extremely long strings
     */
    protected const MAX_TEXT_LENGTH = 100000;

    /**
     * Extract source links from text using various patterns
     *
     * @param  string  $text  The text to search for URLs
     * @return array List of unique URLs found in the text
     */
    public function extractUrls(string $text): array
    {
        // Guard against extremely long texts (ReDoS protection)
        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            // Truncate text for processing or return empty
            $text = substr($text, 0, self::MAX_TEXT_LENGTH);
        }

        $links = [];

        // Extract markdown-style links [text](url)
        if (preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = trim($match[2]);
                if (! empty($url)) {
                    $links[] = $url;
                }
            }
        }

        // Extract plain URLs with improved pattern - handle more edge cases
        if (preg_match_all('/(?:https?:\/\/|www\.)[^\s\)\]\"\'\,\;\<\>]+/', $text, $matches)) {
            foreach ($matches[0] as $url) {
                // Clean up URL (remove trailing punctuation, etc)
                $url = preg_replace('/[,\.;\)\]\"\'\<\>]+$/', '', $url);
                if (! empty($url)) {
                    $links[] = $url;
                }
            }
        }

        // Look for numbered references with URLs [1]: http://example.com
        if (preg_match_all('/\[\d+\]:\s*((?:https?:\/\/|www\.)[^\s]+)/', $text, $matches)) {
            foreach ($matches[1] as $url) {
                if (! empty($url) && ! in_array($url, $links)) {
                    $links[] = $url;
                }
            }
        }

        // Look for academic citation patterns with URLs
        if (preg_match_all('/\((?:[^)]*?)((?:https?:\/\/|www\.)[^\s\)]+)(?:[^)]*)\)/', $text, $matches)) {
            foreach ($matches[1] as $url) {
                $url = trim($url);
                if (! empty($url) && ! in_array($url, $links)) {
                    $links[] = $url;
                }
            }
        }

        // Look for bare DOIs
        if (preg_match_all('/\bDOI:\s*(10\.\d{4,}[\d\.]?\/[^\s\)\]\"\'\,\;]+)/', $text, $matches)) {
            foreach ($matches[1] as $doi) {
                $url = 'https://doi.org/'.trim($doi);
                if (! in_array($url, $links)) {
                    $links[] = $url;
                }
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * Static helper method for URL extraction when creating a service instance is inconvenient
     *
     * @param  string  $text  The text to search for URLs
     * @return array List of unique URLs found in the text
     */
    public static function extract(string $text): array
    {
        $service = new self;

        return $service->extractUrls($text);
    }

    /**
     * Count potential sources in the text
     * Improved method that considers different source formats
     *
     * @param  string  $text  The text to analyze for sources
     * @return int Number of unique sources found
     */
    public function countSources(string $text): int
    {
        // Guard against extremely long texts
        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = substr($text, 0, self::MAX_TEXT_LENGTH);
        }

        $referenceCounts = 0;
        $urlCounts = 0;

        // Count numbered references properly - only within references sections or at line start
        if (preg_match('/(?:references|sources|citations):/i', $text)) {
            // We found a references section
            if (preg_match_all('/(?:^|\n)\s*\[\d+\]/', $text, $matches)) {
                $referenceCounts = count(array_unique($matches[0]));
            }
        }

        // Count URLs as potential sources
        $urlCounts = count($this->extractUrls($text));

        // Return the maximum of both counts, as they might be counting the same sources in different ways
        return max($referenceCounts, $urlCounts);
    }
}
