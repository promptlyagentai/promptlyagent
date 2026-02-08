<?php

namespace App\Tools;

use App\Models\ChatInteractionSource;
use App\Models\Source;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * MarkItDownTool - Web Page to Markdown Conversion with Image Extraction.
 *
 * Prism tool for converting web pages to clean Markdown format with automatic
 * image extraction and relevance scoring. Ideal for knowledge capture and content
 * archival from web sources.
 *
 * Conversion Features:
 * - HTML to Markdown transformation
 * - Image extraction and analysis
 * - Relevance scoring for images
 * - Link preservation
 * - Clean text extraction
 *
 * Image Processing:
 * - Extracts all images from page
 * - AI-powered relevance scoring
 * - Identifies primary content images
 * - Filters decorative/UI elements
 *
 * Output Format:
 * - Clean Markdown text
 * - Image list with URLs and relevance scores
 * - Metadata (title, description)
 * - Source URL tracking
 *
 * Use Cases:
 * - Archiving web content
 * - Knowledge base population
 * - Content research and analysis
 * - Web scraping for documentation
 *
 * @see \App\Models\Source
 * @see \App\Tools\SourceContentTool
 */
class MarkItDownTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('markitdown')
            ->for('Download and convert web URLs to Markdown using MarkItDown. Supports various formats including HTML, PDF, Office documents, and more.')
            ->withStringParameter('url', 'The URL to download and convert to Markdown')
            ->withNumberParameter('timeout', 'Request timeout in seconds (default: 30, max: 120)', false)
            ->using(function (string $url, int $timeout = 30) {
                // Get the StatusReporter from the execution context
                $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

                if (! $statusReporter) {
                    Log::warning('MarkItDownTool: No status reporter available for status reporting');
                    // Fall back to simple execution without status reporting
                    if (empty($url)) {
                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_argument',
                            'message' => 'URL is required',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    }
                    if (! filter_var($url, FILTER_VALIDATE_URL)) {
                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_argument',
                            'message' => 'Invalid URL format',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    }
                }

                try {
                    $start = microtime(true);

                    if ($statusReporter) {
                        $domain = parse_url($url, PHP_URL_HOST) ?: 'unknown';
                        $statusReporter->report('markitdown', "Downloading: {$domain}", false, false);
                    }

                    if (empty($url)) {
                        if ($statusReporter) {
                            $statusReporter->report('markitdown', 'Error: URL is required', false, false);
                        }

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_argument',
                            'message' => 'URL is required',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    }

                    // Validate URL format
                    if (! filter_var($url, FILTER_VALIDATE_URL)) {
                        if ($statusReporter) {
                            $statusReporter->report('markitdown', 'Error: Invalid URL format', false, false);
                        }

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_argument',
                            'message' => 'Invalid URL format',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    }

                    // Limit timeout to reasonable bounds
                    $timeout = min(max($timeout, 5), 120);

                    // Get MarkItDown service URL (no status report - internal detail)
                    $markitdownUrl = config('services.markitdown.url', 'http://markitdown:8000');

                    try {
                        // Make request to MarkItDown service
                        $response = Http::timeout($timeout)
                            ->retry(2, 1000) // Retry twice with 1 second delay
                            ->withHeaders([
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json',
                            ])
                            ->post($markitdownUrl.'/convert', [
                                'url' => $url,
                                'format' => 'markdown',
                            ]);
                    } catch (\Illuminate\Http\Client\ConnectionException $e) {
                        // Handle connection failures (DNS, network unreachable, etc.)
                        $errorMessage = 'MarkItDown service unavailable - connection failed';

                        if ($statusReporter) {
                            $statusReporter->report('markitdown', $errorMessage, false, false);
                        }

                        Log::error('MarkItDown connection failed', [
                            'url' => $url,
                            'markitdown_url' => $markitdownUrl,
                            'error' => $e->getMessage(),
                            'error_class' => get_class($e),
                        ]);

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'connection_error',
                            'message' => $errorMessage,
                            'data' => [
                                'url' => $url,
                                'service_url' => $markitdownUrl,
                            ],
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    } catch (\Illuminate\Http\Client\RequestException $e) {
                        // Handle HTTP request exceptions (timeouts, etc.)
                        $errorMessage = 'MarkItDown request failed - timeout or HTTP error';

                        if ($statusReporter) {
                            $statusReporter->report('markitdown', $errorMessage, false, false);
                        }

                        Log::error('MarkItDown request exception', [
                            'url' => $url,
                            'error' => $e->getMessage(),
                            'error_class' => get_class($e),
                        ]);

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'request_error',
                            'message' => $errorMessage,
                            'data' => [
                                'url' => $url,
                            ],
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    } catch (\Exception $e) {
                        // Handle any other unexpected exceptions
                        $errorMessage = 'MarkItDown request failed - unexpected error';

                        if ($statusReporter) {
                            $statusReporter->report('markitdown', $errorMessage, false, false);
                        }

                        Log::error('MarkItDown unexpected exception', [
                            'url' => $url,
                            'error' => $e->getMessage(),
                            'error_class' => get_class($e),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'unexpected_error',
                            'message' => $errorMessage,
                            'data' => [
                                'url' => $url,
                            ],
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    }

                    if (! $response->successful()) {
                        $responseBody = $response->body();
                        $errorMsg = 'MarkItDown conversion failed with status: '.$response->status();

                        // Classify error types from MarkItDown service responses
                        $isServiceNetworkError = str_contains($responseBody, 'Failed to download URL')
                            || str_contains($responseBody, 'Max retries exceeded')
                            || str_contains($responseBody, 'Connection timed out')
                            || str_contains($responseBody, 'HTTPSConnectionPool')
                            || str_contains($responseBody, 'Connection refused');

                        $isServiceDOMError = str_contains($responseBody, 'DOMEntityReference')
                            || str_contains($responseBody, 'HTML parsing error')
                            || str_contains($responseBody, 'malformed HTML');

                        $errorType = $isServiceNetworkError ? 'service_network_error'
                            : ($isServiceDOMError ? 'service_parsing_error' : 'api_error');

                        $friendlyMessage = $isServiceNetworkError
                            ? 'Website unreachable - connection failed'
                            : ($isServiceDOMError
                                ? 'Content parsing error - malformed website content'
                                : "Error: {$errorMsg}");

                        if ($statusReporter) {
                            $statusReporter->report('markitdown', $friendlyMessage, false, false);
                        }

                        Log::warning('MarkItDown conversion failed', [
                            'url' => $url,
                            'status' => $response->status(),
                            'body' => substr($responseBody, 0, 500).(strlen($responseBody) > 500 ? '...' : ''),
                            'error_type' => $errorType,
                            'is_service_network_error' => $isServiceNetworkError,
                            'is_service_dom_error' => $isServiceDOMError,
                        ]);

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => $errorType,
                            'message' => $friendlyMessage,
                            'data' => [
                                'url' => $url,
                                'service_status' => $response->status(),
                                'error_classification' => $errorType,
                            ],
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    }

                    // Processing response (no status report - internal detail)

                    $data = $response->json();
                    $markdown = $data['markdown'] ?? $data['content'] ?? '';

                    if (empty($markdown)) {
                        if ($statusReporter) {
                            $statusReporter->report('markitdown', 'Error: No markdown content returned from MarkItDown service', false, false);
                        }

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'api_response_error',
                            'message' => 'No markdown content returned from MarkItDown service',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'markitdown');
                    }

                    // Extract images from the markdown content
                    $images = self::extractImages($markdown, $url);

                    // Get content statistics
                    $wordCount = str_word_count(strip_tags($markdown));
                    $charCount = strlen($markdown);
                    $duration = microtime(true) - $start;

                    // Report meaningful completion with content info
                    if ($statusReporter) {
                        $statusReporter->report('markitdown', "Downloaded: {$url} - {$wordCount} words, {$charCount} characters", true, false);
                    }

                    Log::info('MarkItDown conversion completed successfully', [
                        'url' => $url,
                        'word_count' => $wordCount,
                        'char_count' => $charCount,
                    ]);

                    // Update Source model with scraped content if it exists
                    try {
                        // Update source record (no status report - internal detail)

                        $urlHash = md5($url);
                        $source = Source::where('url_hash', $urlHash)->first();

                        if ($source) {
                            // Update the source with the scraped markdown content
                            $source->update([
                                'content_markdown' => $markdown,
                                'content_preview' => substr($markdown, 0, 500).($charCount > 500 ? '...' : ''),
                                'is_scrapeable' => true,
                                'scraping_metadata' => [
                                    'scraped_at' => now()->toISOString(),
                                    'word_count' => $wordCount,
                                    'char_count' => $charCount,
                                    'images_found' => count($images),
                                    'markitdown_execution_time_ms' => (int) ($duration * 1000),
                                ],
                            ]);

                            // Update relevance scores for any associated ChatInteractionSources
                            if ($statusReporter && $statusReporter->getInteractionId()) {
                                $chatInteractionSources = ChatInteractionSource::where('source_id', $source->id)
                                    ->where('chat_interaction_id', $statusReporter->getInteractionId())
                                    ->get();

                                foreach ($chatInteractionSources as $chatInteractionSource) {
                                    // Calculate content-based relevance score
                                    $contentRelevanceScore = self::calculateContentRelevance(
                                        $chatInteractionSource->relevance_metadata['query'] ?? '',
                                        $markdown
                                    );

                                    $chatInteractionSource->updateAfterScraping($contentRelevanceScore);
                                }

                                Log::info('MarkItDown updated source and relevance scores', [
                                    'url' => $url,
                                    'source_id' => $source->id,
                                    'chat_interaction_sources_updated' => $chatInteractionSources->count(),
                                ]);
                            }
                        } else {
                            Log::warning('MarkItDown could not find source to update', ['url' => $url]);
                        }
                    } catch (\Exception $updateException) {
                        // Don't fail the main operation if source update fails
                        Log::error('MarkItDown failed to update source record', [
                            'url' => $url,
                            'error' => $updateException->getMessage(),
                        ]);

                        // Source update failed (no status report - internal detail)
                        Log::warning('MarkItDown source update failed', [
                            'url' => $url,
                            'error' => $updateException->getMessage(),
                        ]);
                    }

                    return static::safeJsonEncode([
                        'success' => true,
                        'data' => [
                            'url' => $url,
                            'markdown' => self::safeTruncate($markdown),
                            'word_count' => $wordCount,
                            'char_count' => $charCount,
                            'images' => $images,
                            'preview' => substr($markdown, 0, 500).($charCount > 500 ? '...' : ''),
                        ],
                        'metadata' => [
                            'executed_at' => now()->toISOString(),
                            'tool_version' => '1.0.0',
                            'execution_time_ms' => (int) ((microtime(true) - $start) * 1000),
                        ],
                    ], 'markitdown');

                } catch (\Exception $e) {
                    // Use unified exception handler for all MarkItDown errors
                    return static::handleMarkItDownException($e, $url, $statusReporter);
                }
            });
    }

    /**
     * Unified exception handler for all MarkItDown errors
     */
    protected static function handleMarkItDownException(
        \Exception $e,
        string $url,
        $statusReporter = null
    ): string {
        $errorMessage = $e->getMessage();
        $errorClass = get_class($e);

        // Classify error type based on exception class and message patterns
        $isConnectionTimeout = $e instanceof \Illuminate\Http\Client\ConnectionException
            || str_contains($errorMessage, 'cURL error 28')
            || str_contains($errorMessage, 'Timeout was reached')
            || str_contains($errorMessage, 'Connection timed out')
            || str_contains($errorMessage, 'Failed to connect');

        $isConnectionRefused = str_contains($errorMessage, 'Connection refused')
            || str_contains($errorMessage, 'cURL error 7');

        $isDNSError = str_contains($errorMessage, 'Could not resolve host')
            || str_contains($errorMessage, 'cURL error 6');

        // Service-side errors (from MarkItDown service's response)
        $isServiceNetworkError = str_contains($errorMessage, 'Failed to download URL')
            || str_contains($errorMessage, 'Max retries exceeded')
            || str_contains($errorMessage, 'HTTPSConnectionPool');

        $isServiceDOMError = str_contains($errorMessage, 'DOMEntityReference')
            || str_contains($errorMessage, 'HTML parsing error')
            || str_contains($errorMessage, 'malformed HTML');

        // Determine error type and friendly message
        if ($isConnectionTimeout) {
            $errorType = 'connection_timeout';
            $friendlyMessage = 'MarkItDown service unavailable - connection timeout. The service may be down or unreachable.';
        } elseif ($isConnectionRefused) {
            $errorType = 'connection_refused';
            $friendlyMessage = 'MarkItDown service unavailable - connection refused. The service is not running.';
        } elseif ($isDNSError) {
            $errorType = 'dns_error';
            $friendlyMessage = 'MarkItDown service unavailable - DNS resolution failed.';
        } elseif ($isServiceNetworkError) {
            $errorType = 'service_network_error';
            $friendlyMessage = 'Website unreachable - MarkItDown service could not connect to the target URL.';
        } elseif ($isServiceDOMError) {
            $errorType = 'service_parsing_error';
            $friendlyMessage = 'Content parsing error - malformed website content.';
        } elseif ($e instanceof \Illuminate\Http\Client\RequestException) {
            $errorType = 'http_request_error';
            $friendlyMessage = 'HTTP request failed: '.$errorMessage;
        } else {
            $errorType = 'markitdown_error';
            $friendlyMessage = 'MarkItDown conversion failed: '.$errorMessage;
        }

        // Report to status reporter if available
        if ($statusReporter) {
            $statusReporter->report('markitdown', $friendlyMessage, false, false);
        }

        // Log with appropriate context
        Log::error('MarkItDown conversion error', [
            'url' => $url,
            'error' => $errorMessage,
            'error_class' => $errorClass,
            'error_code' => $e->getCode(),
            'error_type' => $errorType,
            'is_connection_timeout' => $isConnectionTimeout,
            'is_connection_refused' => $isConnectionRefused,
            'is_dns_error' => $isDNSError,
            'is_service_network_error' => $isServiceNetworkError,
            'is_service_dom_error' => $isServiceDOMError,
            'trace' => $e->getTraceAsString(),
        ]);

        // Return standardized error response
        return static::safeJsonEncode([
            'success' => false,
            'error' => $errorType,
            'message' => $friendlyMessage,
            'data' => [
                'url' => $url,
                'error_type' => $errorClass,
                'error_classification' => $errorType,
            ],
            'metadata' => [
                'executed_at' => now()->toISOString(),
                'tool_version' => '1.0.0',
                'error_occurred' => true,
            ],
        ], 'markitdown');
    }

    /**
     * Extract image URLs from markdown content
     */
    protected static function extractImages(string $markdown, string $sourceUrl): array
    {
        $images = [];

        // Extract markdown image syntax ![alt](url)
        preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $markdown, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $altText = trim($match[1]);
            $imageUrl = trim($match[2]);

            // Convert relative URLs to absolute
            $absoluteUrl = self::makeAbsoluteUrl($imageUrl, $sourceUrl);

            // Basic validation - check if it looks like an image URL
            if (self::isValidImageUrl($absoluteUrl)) {
                $images[] = [
                    'url' => $absoluteUrl,
                    'alt' => $altText ?: 'Image',
                    'type' => 'markdown',
                    'source_url' => $sourceUrl,
                ];
            }
        }

        // Also extract HTML img tags that might be in the markdown
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*(?:alt=["\']([^"\']*)["\'])?[^>]*>/i', $markdown, $htmlMatches, PREG_SET_ORDER);

        foreach ($htmlMatches as $match) {
            $imageUrl = trim($match[1]);
            $altText = isset($match[2]) ? trim($match[2]) : '';

            // Convert relative URLs to absolute
            $absoluteUrl = self::makeAbsoluteUrl($imageUrl, $sourceUrl);

            // Basic validation - check if it looks like an image URL
            if (self::isValidImageUrl($absoluteUrl)) {
                $images[] = [
                    'url' => $absoluteUrl,
                    'alt' => $altText ?: 'Image',
                    'type' => 'html',
                    'source_url' => $sourceUrl,
                ];
            }
        }

        // Remove duplicates based on URL
        $uniqueImages = [];
        $seenUrls = [];

        foreach ($images as $image) {
            if (! in_array($image['url'], $seenUrls)) {
                $uniqueImages[] = $image;
                $seenUrls[] = $image['url'];
            }
        }

        return $uniqueImages;
    }

    /**
     * Convert relative URL to absolute URL
     */
    protected static function makeAbsoluteUrl(string $url, string $baseUrl): string
    {
        // Already absolute URL
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }

        // Protocol-relative URL
        if (str_starts_with($url, '//')) {
            $parsedBase = parse_url($baseUrl);

            return ($parsedBase['scheme'] ?? 'https').':'.$url;
        }

        $parsedBase = parse_url($baseUrl);
        if (! $parsedBase) {
            return $url;
        }

        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';
        $port = isset($parsedBase['port']) ? ':'.$parsedBase['port'] : '';
        $basePath = isset($parsedBase['path']) ? dirname($parsedBase['path']) : '';

        // Root-relative URL
        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$port.$url;
        }

        // Relative URL
        if ($basePath === '.') {
            $basePath = '';
        }

        return $scheme.'://'.$host.$port.$basePath.'/'.$url;
    }

    /**
     * Basic validation to check if URL looks like an image
     */
    protected static function isValidImageUrl(string $url): bool
    {
        // Skip data URLs, they're too long for our purposes
        if (str_starts_with($url, 'data:')) {
            return false;
        }

        // Skip if URL is too long (probably not a direct image)
        if (strlen($url) > 500) {
            return false;
        }

        // Check for common image extensions
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
        $urlPath = parse_url($url, PHP_URL_PATH);

        if ($urlPath) {
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            if (in_array($extension, $imageExtensions)) {
                return true;
            }
        }

        // Check for common image hosting patterns
        $imageHosts = ['imgur.com', 'i.imgur.com', 'images.', 'img.', 'static.', 'cdn.', 'media.'];
        foreach ($imageHosts as $host) {
            if (str_contains($url, $host)) {
                return true;
            }
        }

        // If URL contains image-related keywords, likely an image
        if (preg_match('/\b(image|img|photo|picture|pic|thumbnail|thumb)\b/i', $url)) {
            return true;
        }

        return false;
    }

    /**
     * Calculate content relevance score based on scraped markdown content
     */
    protected static function calculateContentRelevance(string $userQuery, string $markdownContent): float
    {
        if (empty($userQuery) || empty($markdownContent)) {
            return 0.0;
        }

        $query = strtolower($userQuery);
        $content = strtolower($markdownContent);

        // Extract keywords from query (remove common words)
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'how', 'what', 'when', 'where', 'why', 'who', 'can', 'will', 'would', 'should', 'could'];
        $queryWords = array_filter(
            str_word_count($query, 1),
            fn ($word) => strlen($word) > 2 && ! in_array($word, $stopWords)
        );

        if (empty($queryWords)) {
            return 0.0;
        }

        $score = 0.0;
        $totalWords = count($queryWords);

        // Keyword frequency scoring
        foreach ($queryWords as $word) {
            $frequency = substr_count($content, $word);
            if ($frequency > 0) {
                // Score based on frequency, but with diminishing returns
                $wordScore = min(1.0, ($frequency / 10)) * (1 / $totalWords);
                $score += $wordScore;
            }
        }

        // Exact phrase matching bonus
        if (str_contains($content, $query)) {
            $score += 0.5;
        }

        // Partial phrase matching
        $queryWords = explode(' ', $query);
        if (count($queryWords) > 1) {
            for ($i = 0; $i < count($queryWords) - 1; $i++) {
                $phrase = $queryWords[$i].' '.$queryWords[$i + 1];
                if (str_contains($content, $phrase)) {
                    $score += 0.2;
                }
            }
        }

        // Content length bonus - longer content is more likely to be comprehensive
        $contentWordCount = str_word_count($content);
        if ($contentWordCount > 1000) {
            $score += 0.1; // Bonus for comprehensive content
        } elseif ($contentWordCount < 100) {
            $score -= 0.1; // Penalty for very short content
        }

        // Normalize to 0-10 scale
        $finalScore = min(10.0, max(0.0, $score * 10));

        Log::debug('Content relevance calculated', [
            'query' => $userQuery,
            'content_length' => strlen($markdownContent),
            'content_word_count' => $contentWordCount,
            'query_words' => count($queryWords),
            'final_score' => $finalScore,
        ]);

        return round($finalScore, 3);
    }
}
