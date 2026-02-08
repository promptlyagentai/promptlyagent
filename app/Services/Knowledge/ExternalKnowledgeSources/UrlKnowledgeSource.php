<?php

namespace App\Services\Knowledge\ExternalKnowledgeSources;

use App\Services\Knowledge\DTOs\ExternalKnowledgeDocument;
use App\Services\Knowledge\DTOs\ExternalKnowledgeMetadata;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * URL-based External Knowledge Source
 *
 * Fetches and processes web content from URLs including HTML parsing, metadata extraction,
 * change detection, and domain-specific TTL management.
 *
 * Architecture:
 * - Content Fetching: HTTP GET with custom User-Agent and 30s timeout
 * - Metadata Extraction: Parses HTML meta tags, Open Graph, Twitter Cards, favicon
 * - Change Detection: HTTP HEAD requests with ETag and Last-Modified headers
 * - Smart Categorization: Domain and path-based automatic categorization
 * - Domain-specific TTL: Different expiration times based on content type
 *
 * TTL Strategy:
 * - News/blogs (news., blog., reddit.com, twitter.com): 4 hours
 * - Documentation (docs., developer., api., reference.): 1 week
 * - Default: 24 hours (configurable)
 *
 * Auto-refresh Domains:
 * - Enabled: news., blog., reddit.com, twitter.com, x.com
 * - Disabled: All other domains (manual refresh only)
 *
 * Metadata Extraction:
 * - HTML title tag
 * - Meta tags: description, keywords, author, language
 * - Open Graph: og:title, og:description, og:image
 * - Article metadata: published_time, modified_time
 * - Favicon: icon, shortcut icon, apple-touch-icon
 *
 * Automatic Categorization:
 * - Documentation: docs.*, developer.* domains
 * - News: news.*, blog.* domains
 * - Code: github.com, gitlab.com
 * - Q&A: stackoverflow.com, stackexchange.com
 * - API: /api/, /docs/ paths
 * - Tutorial: /tutorial/, /guide/ paths
 *
 * Rate Limiting:
 * - 30 requests/minute
 * - 500 requests/hour
 *
 * @see \App\Services\Knowledge\ExternalKnowledgeSources\AbstractExternalKnowledgeSource
 */
class UrlKnowledgeSource extends AbstractExternalKnowledgeSource
{
    protected array $rateLimits = [
        'requests_per_minute' => 30,
        'requests_per_hour' => 500,
    ];

    /**
     * Get the unique identifier for this external knowledge source type.
     */
    public function getSourceType(): string
    {
        return 'url';
    }

    /**
     * Determine the Time-To-Live (TTL) for a document from this source.
     */
    public function getTTL(string $sourceIdentifier): ?Carbon
    {
        $defaultHours = $this->config['ttl_hours'] ?? 24;

        // Different TTL based on content type or domain
        $host = parse_url($sourceIdentifier, PHP_URL_HOST);

        // Shorter TTL for dynamic content
        $shortTtlDomains = ['news.', 'blog.', 'reddit.com', 'twitter.com', 'x.com'];
        foreach ($shortTtlDomains as $domain) {
            if (str_contains($host, $domain)) {
                return now()->addHours(4);
            }
        }

        // Longer TTL for documentation and reference material
        $longTtlDomains = ['docs.', 'developer.', 'api.', 'reference.'];
        foreach ($longTtlDomains as $domain) {
            if (str_contains($host, $domain)) {
                return now()->addHours(168); // 1 week
            }
        }

        return now()->addHours($defaultHours);
    }

    /**
     * Create a backlink URL to the original source document.
     */
    public function createBacklink(string $sourceIdentifier): string
    {
        return $sourceIdentifier;
    }

    /**
     * Retrieve the document content and metadata for processing.
     */
    public function getDocument(string $sourceIdentifier): ExternalKnowledgeDocument
    {
        // Check cache first
        $cached = $this->getCachedData($sourceIdentifier, 'document');
        if ($cached) {
            return ExternalKnowledgeDocument::fromArray($cached);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; PromptlyAgentKnowledgeBot/1.0)',
                ])
                ->get($sourceIdentifier);

            if (! $response->successful()) {
                throw new \Exception("HTTP {$response->status()}: Failed to fetch URL");
            }

            $content = $response->body();
            $contentType = $response->header('Content-Type', 'text/html');

            // Extract metadata from HTML
            $metadata = $this->extractMetadataFromHtml($content);

            // Extract text content
            $textContent = $this->extractTextFromHtml($content);

            $document = new ExternalKnowledgeDocument(
                sourceIdentifier: $sourceIdentifier,
                sourceType: $this->getSourceType(),
                content: $textContent,
                title: $metadata['title'] ?? $this->generateTitleFromUrl($sourceIdentifier),
                description: $metadata['description'] ?? null,
                contentType: $contentType,
                lastModified: $this->parseDate($response->header('Last-Modified')),
                contentHash: $this->calculateContentHash($textContent),
                metadata: array_merge($metadata, [
                    'original_url' => $sourceIdentifier,
                    'fetch_date' => now()->toISOString(),
                    'response_headers' => $response->headers(),
                ]),
                tags: $metadata['keywords'] ?? [],
                categories: $this->categorizeUrl($sourceIdentifier),
                language: $metadata['language'] ?? null,
                author: $metadata['author'] ?? null,
            );

            // Cache the result
            $this->cacheData($sourceIdentifier, $document->toArray(), 'document', now()->addMinutes(30));

            return $document;

        } catch (\Exception $e) {
            Log::error("Failed to fetch URL {$sourceIdentifier}: {$e->getMessage()}");
            throw new \Exception("Failed to retrieve document from URL: {$e->getMessage()}");
        }
    }

    /**
     * Check if a document has been changed since last processed.
     */
    public function hasChanged(string $sourceIdentifier, ?Carbon $lastProcessed = null, ?string $lastHash = null): bool
    {
        try {
            // Make HEAD request to check Last-Modified and ETag
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; PromptlyAgentKnowledgeBot/1.0)',
                ])
                ->head($sourceIdentifier);

            if (! $response->successful()) {
                // If HEAD fails, assume changed to force refresh
                return true;
            }

            // Check Last-Modified header
            $lastModifiedHeader = $response->header('Last-Modified');
            if ($lastModifiedHeader && $lastProcessed) {
                $lastModified = Carbon::parse($lastModifiedHeader);
                if ($lastModified->isAfter($lastProcessed)) {
                    return true;
                }
            }

            // Check ETag if available and we have a hash to compare
            $etag = $response->header('ETag');
            if ($etag && $lastHash && $etag !== $lastHash) {
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::warning("Failed to check if URL changed {$sourceIdentifier}: {$e->getMessage()}");

            // On error, assume changed to be safe
            return true;
        }
    }

    /**
     * Get metadata about the document.
     */
    public function getMetadata(string $sourceIdentifier): ExternalKnowledgeMetadata
    {
        // Check cache first
        $cached = $this->getCachedData($sourceIdentifier, 'metadata');
        if ($cached) {
            return ExternalKnowledgeMetadata::fromArray($cached);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; PromptlyAgentKnowledgeBot/1.0)',
                ])
                ->get($sourceIdentifier);

            if (! $response->successful()) {
                throw new \Exception("HTTP {$response->status()}: Failed to fetch URL");
            }

            $content = $response->body();
            $htmlMetadata = $this->extractMetadataFromHtml($content);

            $metadata = new ExternalKnowledgeMetadata(
                sourceIdentifier: $sourceIdentifier,
                sourceType: $this->getSourceType(),
                title: $htmlMetadata['title'] ?? $this->generateTitleFromUrl($sourceIdentifier),
                description: $htmlMetadata['description'] ?? null,
                favicon: $htmlMetadata['favicon'] ?? null,
                categories: $this->categorizeUrl($sourceIdentifier),
                tags: $htmlMetadata['keywords'] ?? [],
                author: $htmlMetadata['author'] ?? null,
                publishedAt: $this->parseDate($htmlMetadata['published_time'] ?? null),
                lastModified: $this->parseDate($response->header('Last-Modified')),
                language: $htmlMetadata['language'] ?? null,
                contentType: $response->header('Content-Type', 'text/html'),
                wordCount: str_word_count($this->extractTextFromHtml($content)),
                customFields: [
                    'domain' => parse_url($sourceIdentifier, PHP_URL_HOST),
                    'scheme' => parse_url($sourceIdentifier, PHP_URL_SCHEME),
                    'path' => parse_url($sourceIdentifier, PHP_URL_PATH),
                ],
                thumbnail: $htmlMetadata['image'] ?? null,
            );

            // Cache the metadata
            $this->cacheData($sourceIdentifier, $metadata->toArray(), 'metadata', now()->addMinutes(60));

            return $metadata;

        } catch (\Exception $e) {
            Log::error("Failed to get metadata for URL {$sourceIdentifier}: {$e->getMessage()}");
            throw new \Exception("Failed to retrieve metadata from URL: {$e->getMessage()}");
        }
    }

    /**
     * Validate that a source identifier is valid for this source type.
     */
    public function validateSourceIdentifier(string $sourceIdentifier): bool
    {
        return filter_var($sourceIdentifier, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Test connection to the external source.
     */
    public function testConnection(): bool
    {
        // Test with a simple HTTP GET request
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; PromptlyAgentKnowledgeBot/1.0)',
                ])
                ->get('https://httpbin.org/status/200');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine if this document should be marked for "refresh before expiration".
     */
    public function shouldRefreshBeforeExpiration(string $sourceIdentifier): bool
    {
        $host = parse_url($sourceIdentifier, PHP_URL_HOST);

        // Auto-refresh news and dynamic content
        $autoRefreshDomains = ['news.', 'blog.', 'reddit.com', 'twitter.com', 'x.com'];
        foreach ($autoRefreshDomains as $domain) {
            if (str_contains($host, $domain)) {
                return true;
            }
        }

        return parent::shouldRefreshBeforeExpiration($sourceIdentifier);
    }

    /**
     * Extract metadata from HTML content.
     */
    private function extractMetadataFromHtml(string $html): array
    {
        $metadata = [];

        try {
            // Use DOMDocument to parse HTML
            $dom = new \DOMDocument;
            @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

            // Extract title
            $titleNodes = $dom->getElementsByTagName('title');
            if ($titleNodes->length > 0) {
                $metadata['title'] = trim($titleNodes->item(0)->textContent);
            }

            // Extract meta tags
            $metaTags = $dom->getElementsByTagName('meta');
            foreach ($metaTags as $meta) {
                $name = $meta->getAttribute('name') ?: $meta->getAttribute('property');
                $content = $meta->getAttribute('content');

                if (! $name || ! $content) {
                    continue;
                }

                switch (strtolower($name)) {
                    case 'description':
                        $metadata['description'] = $content;
                        break;
                    case 'keywords':
                        $metadata['keywords'] = explode(',', $content);
                        break;
                    case 'author':
                        $metadata['author'] = $content;
                        break;
                    case 'language':
                    case 'lang':
                        $metadata['language'] = $content;
                        break;
                    case 'og:title':
                        $metadata['title'] = $metadata['title'] ?? $content;
                        break;
                    case 'og:description':
                        $metadata['description'] = $metadata['description'] ?? $content;
                        break;
                    case 'og:image':
                        $metadata['image'] = $content;
                        break;
                    case 'article:published_time':
                        $metadata['published_time'] = $content;
                        break;
                    case 'article:modified_time':
                        $metadata['modified_time'] = $content;
                        break;
                }
            }

            // Extract favicon
            $linkTags = $dom->getElementsByTagName('link');
            foreach ($linkTags as $link) {
                $rel = strtolower($link->getAttribute('rel'));
                if (in_array($rel, ['icon', 'shortcut icon', 'apple-touch-icon'])) {
                    $metadata['favicon'] = $link->getAttribute('href');
                    break;
                }
            }

        } catch (Error $e) {
            // Handle DOMEntityReference::getAttribute() error and similar DOM errors
            Log::error('UrlKnowledgeSource: DOM error during metadata extraction', [
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);

            // Return basic metadata on DOM errors
        } catch (Exception $e) {
            Log::error('UrlKnowledgeSource: Exception during metadata extraction', [
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);
        }

        return $metadata;
    }

    /**
     * Generate title from URL when not available in content.
     */
    private function generateTitleFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if ($path && $path !== '/') {
            $pathParts = explode('/', trim($path, '/'));
            $lastPart = end($pathParts);

            return ucwords(str_replace(['-', '_'], ' ', $lastPart)).' - '.$host;
        }

        return $host;
    }

    /**
     * Categorize URL based on domain and path patterns.
     */
    private function categorizeUrl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        $categories = ['external', 'web'];

        // Domain-based categories
        if (str_contains($host, 'docs.') || str_contains($host, 'developer.')) {
            $categories[] = 'documentation';
        }

        if (str_contains($host, 'news.') || str_contains($host, 'blog.')) {
            $categories[] = 'news';
        }

        if (str_contains($host, 'github.com') || str_contains($host, 'gitlab.com')) {
            $categories[] = 'code';
        }

        if (str_contains($host, 'stackoverflow.com') || str_contains($host, 'stackexchange.com')) {
            $categories[] = 'qa';
        }

        // Path-based categories
        if (str_contains($path, '/api/') || str_contains($path, '/docs/')) {
            $categories[] = 'api';
        }

        if (str_contains($path, '/tutorial/') || str_contains($path, '/guide/')) {
            $categories[] = 'tutorial';
        }

        return array_unique($categories);
    }
}
