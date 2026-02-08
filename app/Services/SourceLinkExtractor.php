<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Source Link Extractor Service
 *
 * Extracts source links from tool execution results across different tool types.
 * Supports multiple formats: SearXNG, Perplexity, generic sources, citations, etc.
 *
 * This service consolidates duplicate source link extraction logic previously
 * scattered across AgentExecutor and StreamingController.
 */
class SourceLinkExtractor
{
    /**
     * Extract source links from a single tool result.
     *
     * @param  object|array  $toolResult  Tool result from Prism (object) or array
     * @return array<int, array{url: string, title: string, tool: string, content?: string, type?: string}>
     */
    public function extractFromToolResult($toolResult): array
    {
        $sourceLinks = [];

        try {
            // Handle both object and array formats
            $result = null;
            $toolName = 'unknown';

            if (is_object($toolResult)) {
                $result = $toolResult->result ?? null;
                $toolName = $toolResult->toolName ?? 'unknown';
            } elseif (is_array($toolResult)) {
                $result = $toolResult['result'] ?? null;
                $toolName = $toolResult['toolName'] ?? 'unknown';
            }

            if (! $result) {
                return $sourceLinks;
            }

            // First try to decode as JSON
            $resultData = json_decode($result, true);
            if (! is_array($resultData)) {
                // If JSON decode fails, check if it's already an array or object
                if (is_array($result)) {
                    $resultData = $result;
                } elseif (is_object($result)) {
                    $resultData = (array) $result;
                } else {
                    // Neither JSON nor an array/object, return empty
                    return $sourceLinks;
                }
            }

            // Handle SearXNG search results
            if ($toolName === 'searxng_search' && isset($resultData['data']['results'])) {
                foreach ($resultData['data']['results'] as $result) {
                    if (isset($result['url']) && ! empty($result['url'])) {
                        $sourceLinks[] = [
                            'url' => $result['url'],
                            'title' => $result['title'] ?? $this->extractTitleFromUrl($result['url']),
                            'tool' => $toolName,
                            'content' => $result['content'] ?? '',
                        ];
                    }
                }
            }

            // Handle Perplexity research citations
            if ($toolName === 'perplexity_research' && isset($resultData['data']['citations'])) {
                foreach ($resultData['data']['citations'] as $citation) {
                    if (isset($citation['url']) && ! empty($citation['url'])) {
                        $sourceLinks[] = [
                            'url' => $citation['url'],
                            'title' => $citation['text'] ?? $this->extractTitleFromUrl($citation['url']),
                            'tool' => $toolName,
                            'type' => $citation['type'] ?? 'markdown_link',
                        ];
                    }
                }
            }

            // Handle generic citations format
            if (isset($resultData['data']['citations']) && is_array($resultData['data']['citations']) && $toolName !== 'perplexity_research') {
                foreach ($resultData['data']['citations'] as $citation) {
                    if (isset($citation['url']) && ! empty($citation['url'])) {
                        $sourceLinks[] = [
                            'url' => $citation['url'],
                            'title' => $citation['text'] ?? $citation['title'] ?? $this->extractTitleFromUrl($citation['url']),
                            'tool' => $toolName,
                            'type' => $citation['type'] ?? 'markdown_link',
                        ];
                    }
                }
            }

            // Handle link_validator and bulk_link_validator specially
            // Check for error in tool execution
            if (is_string($result) && stripos($result, 'Tool execution error') !== false) {
                Log::warning('SourceLinkExtractor: Tool execution error detected in tool result', [
                    'tool_name' => $toolName,
                    'error' => $result,
                ]);

                // Skip source extraction for this tool result
                return $sourceLinks;
            }

            if (($toolName === 'link_validator' || $toolName === 'bulk_link_validator') &&
                isset($resultData['status']) && isset($resultData['url'])) {
                // Single link validation result
                $sourceLinks[] = [
                    'url' => $resultData['url'],
                    'title' => $resultData['title'] ?? $this->extractTitleFromUrl($resultData['url']),
                    'tool' => $toolName,
                    'content' => $resultData['description'] ?? ($resultData['content_markdown'] ?? ''),
                ];

                Log::debug('SourceLinkExtractor: Extracted source link from link validator', [
                    'url' => $resultData['url'],
                    'title' => $resultData['title'] ?? null,
                    'has_markdown' => isset($resultData['content_markdown']),
                    'tool' => $toolName,
                ]);
            }

            // Handle bulk_link_validator array results
            if ($toolName === 'bulk_link_validator' && isset($resultData['validatedUrls']) && is_array($resultData['validatedUrls'])) {
                foreach ($resultData['validatedUrls'] as $url => $linkInfo) {
                    if (is_array($linkInfo) && isset($linkInfo['status']) && $linkInfo['status'] < 400) {
                        $sourceLinks[] = [
                            'url' => $url,
                            'title' => $linkInfo['title'] ?? $this->extractTitleFromUrl($url),
                            'tool' => $toolName,
                            'content' => $linkInfo['description'] ?? ($linkInfo['content_markdown'] ?? ''),
                        ];

                        Log::debug('SourceLinkExtractor: Extracted source link from bulk validator', [
                            'url' => $url,
                            'title' => $linkInfo['title'] ?? null,
                            'status' => $linkInfo['status'],
                            'has_markdown' => isset($linkInfo['content_markdown']),
                        ]);
                    }
                }
            }

            // Handle generic sources array
            if (isset($resultData['sources']) && is_array($resultData['sources'])) {
                foreach ($resultData['sources'] as $source) {
                    if (is_string($source)) {
                        $sourceLinks[] = [
                            'url' => $source,
                            'title' => $this->extractTitleFromUrl($source),
                            'tool' => $toolName,
                        ];
                    } elseif (is_array($source) && isset($source['url'])) {
                        $sourceLinks[] = [
                            'url' => $source['url'],
                            'title' => $source['title'] ?? $this->extractTitleFromUrl($source['url']),
                            'tool' => $toolName,
                        ];
                    }
                }
            }

            // Handle direct metadata.source format
            if (isset($resultData['metadata']['source'])) {
                $sourceLinks[] = [
                    'url' => $resultData['metadata']['source'],
                    'title' => $this->extractTitleFromUrl($resultData['metadata']['source']),
                    'tool' => $toolName,
                ];
            }

            Log::info('SourceLinkExtractor: Extracted source links from tool result', [
                'tool_name' => $toolName,
                'source_links_count' => count($sourceLinks),
                'result_data_keys' => array_keys($resultData),
            ]);

        } catch (\Exception $e) {
            $toolName = 'unknown';
            if (is_object($toolResult)) {
                $toolName = $toolResult->toolName ?? 'unknown';
            } elseif (is_array($toolResult)) {
                $toolName = $toolResult['toolName'] ?? 'unknown';
            }

            Log::warning('SourceLinkExtractor: Failed to extract source links from tool result', [
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
            ]);
        }

        return $sourceLinks;
    }

    /**
     * Extract source links from multiple tool results.
     *
     * @param  array  $toolResults  Array of tool results
     * @return array<int, array{url: string, title: string, tool: string, content?: string, type?: string}>
     */
    public function extractFromMultipleResults(array $toolResults): array
    {
        $allSourceLinks = [];

        foreach ($toolResults as $toolResult) {
            $sourceLinks = $this->extractFromToolResult($toolResult);
            $allSourceLinks = array_merge($allSourceLinks, $sourceLinks);
        }

        return $allSourceLinks;
    }

    /**
     * Extract title from URL (simplified version).
     *
     * @param  string  $url  The URL to extract title from
     * @return string The extracted title (hostname or URL if parsing fails)
     */
    public function extractTitleFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host) {
            return $host;
        }

        return $url;
    }
}
