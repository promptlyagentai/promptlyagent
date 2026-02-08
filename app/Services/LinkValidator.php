<?php

namespace App\Services;

use andreskrey\Readability\Configuration;
use andreskrey\Readability\Readability;
use App\Models\Source;
use DOMDocument;
use DOMXPath;
use Error;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\HTMLToMarkdown\HtmlConverter;

class LinkValidator
{
    /**
     * List of realistic browser user agents to rotate through
     */
    private static array $userAgents = [
        // Chrome on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        // Chrome on macOS
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        // Firefox on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        // Safari on macOS
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
        // Edge on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
    ];

    /**
     * Get a random realistic user agent
     */
    private function getRandomUserAgent(): string
    {
        return self::$userAgents[array_rand(self::$userAgents)];
    }

    /**
     * Determine content category based on URL and content
     */
    private function determineContentCategory(string $url, ?string $title = null, ?string $description = null): string
    {
        $domain = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        // Academic/Research indicators
        if ($domain && (
            str_contains($domain, 'arxiv.org') ||
            str_contains($domain, 'scholar.google.com') ||
            str_contains($domain, 'researchgate.net') ||
            str_contains($domain, 'ncbi.nlm.nih.gov') ||
            str_contains($domain, 'ieee.org') ||
            str_contains($domain, 'acm.org') ||
            str_contains($domain, 'nature.com') ||
            str_contains($domain, 'science.org') ||
            str_ends_with($domain, '.edu')
        )) {
            return 'academic';
        }

        // News sites
        $newsSites = ['bbc.com', 'cnn.com', 'reuters.com', 'ap.org', 'nytimes.com', 'guardian.com', 'washingtonpost.com', 'bloomberg.com', 'wsj.com'];
        if ($domain && collect($newsSites)->contains(fn ($site) => str_contains($domain, $site))) {
            return 'news';
        }

        // Documentation
        if ($domain && (
            str_contains($domain, 'docs.') ||
            str_contains($domain, 'developer.') ||
            str_contains($domain, 'api.') ||
            str_contains($path, '/docs/') ||
            str_contains($path, '/documentation/') ||
            str_contains($domain, 'stackoverflow.com') ||
            str_contains($domain, 'github.com')
        )) {
            return 'documentation';
        }

        // Blog platforms
        $blogSites = ['medium.com', 'substack.com', 'wordpress.com', 'blogspot.com', 'dev.to', 'hashnode.com'];
        if ($domain && collect($blogSites)->contains(fn ($site) => str_contains($domain, $site))) {
            return 'blog';
        }

        // Social media
        $socialSites = ['twitter.com', 'x.com', 'linkedin.com', 'facebook.com', 'reddit.com', 'youtube.com'];
        if ($domain && collect($socialSites)->contains(fn ($site) => str_contains($domain, $site))) {
            return 'social';
        }

        // Content-based categorization
        $content = strtolower(($title ?? '').' '.($description ?? ''));
        if (str_contains($content, 'research') || str_contains($content, 'study') || str_contains($content, 'paper')) {
            return 'research';
        }

        if (str_contains($content, 'news') || str_contains($content, 'breaking') || str_contains($content, 'report')) {
            return 'news';
        }

        return 'general';
    }

    /**
     * Validates a URL and retrieves its favicon, title, description, status, and main content.
     *
     * @param  string  $url  The URL to validate and extract information from.
     * @return array An associative array containing the favicon, title, description, status, main content, Open Graph, and Twitter Card data.
     */
    public function validateAndExtractLinkInfo(string $url): array
    {
        Log::info('LinkValidator: Starting validation', ['url' => $url]);

        // Supported URL schemes
        $supportedSchemes = ['http', 'https'];

        // Validate URL
        $parsedUrl = filter_var($url, FILTER_VALIDATE_URL);
        if (! $parsedUrl) {
            Log::info('LinkValidator: Invalid URL format', ['url' => $url]);
            $sourceData = $this->createSourceDataFromError($url, 'Invalid URL');
            $source = Source::createOrUpdate($sourceData);

            return $this->formatSourceResult($source);
        }

        $scheme = parse_url($parsedUrl, PHP_URL_SCHEME);
        if (! in_array(strtolower($scheme), $supportedSchemes, true)) {
            Log::info('LinkValidator: Unsupported URL scheme', ['url' => $url, 'scheme' => $scheme]);
            $sourceData = $this->createSourceDataFromError($url, 'Unsupported URL scheme');
            $source = Source::createOrUpdate($sourceData);

            return $this->formatSourceResult($source);
        }

        // Check if we have a valid cached source
        $urlHash = md5($url);
        $existingSource = Source::where('url_hash', $urlHash)->first();

        if ($existingSource && $existingSource->isValid()) {
            Log::info('LinkValidator: Using cached source', [
                'url' => $url,
                'source_id' => $existingSource->id,
                'expires_at' => $existingSource->expires_at,
            ]);

            // Send status update for cached source usage
            // $this->reportStatus("Using cached source: {$existingSource->domain}", false, false);

            // Update access tracking
            $existingSource->increment('access_count');
            $existingSource->update(['last_accessed_at' => now()]);

            return $this->formatSourceResult($existingSource);
        }

        // Perform HTTP request using helper method
        $httpResult = $this->performHttpRequest($url);

        if ($httpResult['error']) {
            Log::warning('LinkValidator: HTTP request failed', [
                'url' => $url,
                'status' => $httpResult['status'],
                'user_agent' => $httpResult['user_agent'],
            ]);
            $sourceData = $this->createSourceDataFromError($url, $httpResult['status']);
            $sourceData['last_user_agent'] = $httpResult['user_agent'];
            $source = Source::createOrUpdate($sourceData);

            return $this->formatSourceResult($source);
        }

        $body = $httpResult['body'];
        $statusCode = $httpResult['status'];
        $userAgent = $httpResult['user_agent'];
        $contentType = $httpResult['content_type'];

        // Parsing the HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        if (! $dom->loadHTML($body)) {
            libxml_clear_errors();
            Log::warning('LinkValidator: Failed to parse HTML', ['url' => $url]);
            $sourceData = $this->createSourceDataFromError($url, 'Failed to parse HTML');
            $sourceData['last_user_agent'] = $userAgent;
            $source = Source::createOrUpdate($sourceData);

            return $this->formatSourceResult($source);
        }
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        // Initialize result array to collect metadata
        $result = [
            'title' => null,
            'description' => null,
            'favicon' => null,
            'open_graph' => [],
            'twitter_card' => [],
            'content_markdown' => null,
        ];

        // Extract Title
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $result['title'] = trim($titles->item(0)->textContent);
        }

        // Extract Meta Description
        $metaDescription = $this->extractMetaContent($xpath, 'description', 'content');
        if ($metaDescription) {
            $result['description'] = trim($metaDescription);
        }

        // Extract Open Graph Metadata
        $ogTitle = $this->extractMetaContent($xpath, 'og:title', 'content');
        if ($ogTitle) {
            $result['open_graph']['title'] = trim($ogTitle);
        }

        $ogDescription = $this->extractMetaContent($xpath, 'og:description', 'content');
        if ($ogDescription) {
            $result['open_graph']['description'] = trim($ogDescription);
        }

        $ogImage = $this->extractMetaContent($xpath, 'og:image', 'content');
        if ($ogImage) {
            $result['open_graph']['image'] = $this->resolveUrl($ogImage, $url);
        }

        // Extract Twitter Card Metadata
        $twitterTitle = $this->extractMetaContent($xpath, 'twitter:title', 'content');
        if ($twitterTitle) {
            $result['twitter_card']['title'] = trim($twitterTitle);
        }

        $twitterDescription = $this->extractMetaContent($xpath, 'twitter:description', 'content');
        if ($twitterDescription) {
            $result['twitter_card']['description'] = trim($twitterDescription);
        }

        $twitterImage = $this->extractMetaContent($xpath, 'twitter:image', 'content');
        if ($twitterImage) {
            $result['twitter_card']['image'] = $this->resolveUrl($twitterImage, $url);
        }

        // Extract Favicon
        $favicon = null;
        $iconLinks = $xpath->query("//link[contains(translate(@rel, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'icon')]");
        $favicons = [];

        foreach ($iconLinks as $link) {
            /** @var \DOMElement $link */
            $rel = strtolower($link->getAttribute('rel'));
            if (strpos($rel, 'icon') !== false) {
                $sizes = $link->getAttribute('sizes');
                $href = $link->getAttribute('href');
                if ($href) {
                    $faviconUrl = $this->resolveUrl($href, $url);
                    $favicons[] = [
                        'url' => $faviconUrl,
                        'sizes' => $sizes,
                    ];
                }
            }
        }

        if (! empty($favicons)) {
            // Prefer the largest icon if sizes are specified
            usort($favicons, function ($a, $b) {
                $sizeA = isset($a['sizes']) && preg_match('/(\d+)x(\d+)/', $a['sizes'], $matchesA) ? (int) $matchesA[1] * (int) $matchesA[2] : 0;
                $sizeB = isset($b['sizes']) && preg_match('/(\d+)x(\d+)/', $b['sizes'], $matchesB) ? (int) $matchesB[1] * (int) $matchesB[2] : 0;

                return $sizeB <=> $sizeA;
            });
            $favicon = $favicons[0]['url'];
        } else {
            // Default to /favicon.ico
            $favicon = $this->resolveUrl('/favicon.ico', $url);
        }
        $result['favicon'] = $favicon;

        // Extract Main Content using Readability
        try {
            $config = new Configuration;
            $config->setFixRelativeURLs(true);
            $config->setOriginalURL($url);
            $readability = new Readability($config);

            // Clean HTML to prevent DOMEntityReference issues
            $cleanBody = $this->cleanHtmlForReadability($body);
            $parsed = $readability->parse($cleanBody);

            if ($parsed && isset($parsed['content'])) {
                $contentHtml = $parsed['content'];

                // Convert HTML to Markdown
                $converter = new HtmlConverter([
                    'strip_tags' => true,
                    'strip_comments' => true,
                ]);
                $markdown = $converter->convert($contentHtml);
                $result['content_markdown'] = $markdown;
            } else {
                $result['content_markdown'] = null;
            }
        } catch (Error $e) {
            // Handle DOMEntityReference::getAttribute() error and similar DOM errors
            Log::error('LinkValidator: Readability DOM error', [
                'url' => $url,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);
            $result['content_markdown'] = null;
        } catch (Exception $e) {
            Log::error('LinkValidator: Readability failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);
            $result['content_markdown'] = null;
        }

        // Create or update source with all collected data
        $domain = parse_url($url, PHP_URL_HOST);
        $contentCategory = $this->determineContentCategory($url, $result['title'], $result['description']);

        $sourceData = [
            'url' => $url,
            'domain' => $domain,
            'title' => $result['title'],
            'description' => $result['description'],
            'favicon' => $result['favicon'],
            'open_graph' => $result['open_graph'],
            'twitter_card' => $result['twitter_card'],
            'content_markdown' => $result['content_markdown'],
            'content_preview' => $result['content_markdown'] ? substr($result['content_markdown'], 0, 500) : null,
            'http_status' => $statusCode,
            'content_type' => $contentType,
            'content_category' => $contentCategory,
            'is_scrapeable' => ! empty($result['content_markdown']),
            'validation_metadata' => [
                'validated_at' => now()->toISOString(),
                'user_agent' => $userAgent,
                'content_length' => strlen($body),
                'parsing_successful' => true,
            ],
            'last_user_agent' => $userAgent,
        ];

        $source = Source::createOrUpdate($sourceData);

        Log::info('LinkValidator: Source created/updated', [
            'url' => $url,
            'source_id' => $source->id,
            'content_category' => $contentCategory,
            'ttl_hours' => $source->ttl_hours,
            'expires_at' => $source->expires_at,
            'has_content' => ! empty($result['content_markdown']),
        ]);

        return $this->formatSourceResult($source);
    }

    /**
     * Validates multiple URLs in parallel for improved performance
     *
     * @param  array  $urls  Array of URLs to validate
     * @return array Associative array with URL as key and validation result as value
     */
    public function validateMultipleUrls(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }

        Log::info('LinkValidator: Starting parallel validation', [
            'url_count' => count($urls),
            'urls' => $urls,
        ]);

        $start = microtime(true);
        $results = [];

        // Filter out invalid URLs and check cache first
        $urlsToValidate = [];
        foreach ($urls as $url) {
            // Basic URL validation
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $results[$url] = $this->formatSourceResult($this->createSourceFromError($url, 'Invalid URL'));

                continue;
            }

            // Check if we have a valid cached source
            $urlHash = md5($url);
            $existingSource = Source::where('url_hash', $urlHash)->first();

            if ($existingSource && $existingSource->isValid()) {
                Log::debug('LinkValidator: Using cached source for parallel validation', [
                    'url' => $url,
                    'source_id' => $existingSource->id,
                ]);

                // Send status update for cached source usage
                // $this->reportStatus("Using cached source: {$existingSource->domain}", false, false);

                // Update access tracking
                $existingSource->increment('access_count');
                $existingSource->update(['last_accessed_at' => now()]);

                $results[$url] = $this->formatSourceResult($existingSource);
            } else {
                $urlsToValidate[] = $url;
            }
        }

        // If all URLs were cached, return early
        if (empty($urlsToValidate)) {
            Log::info('LinkValidator: All URLs served from cache', [
                'cached_count' => count($results),
                'duration_ms' => (microtime(true) - $start) * 1000,
            ]);

            return $results;
        }

        Log::info('LinkValidator: Performing parallel HTTP requests', [
            'urls_to_validate' => count($urlsToValidate),
            'cached_results' => count($results),
        ]);

        // Create HTTP pool for parallel requests
        $responses = Http::pool(function ($pool) use ($urlsToValidate) {
            foreach ($urlsToValidate as $url) {
                $userAgent = $this->getRandomUserAgent();

                $pool->as($url)->withOptions([
                    'timeout' => 8.0,  // Reduced timeout for parallel execution
                    'connect_timeout' => 3.0,
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'track_redirects' => true,
                    ],
                    'verify' => true,
                ])->withHeaders([
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'DNT' => '1',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                ])->get($url);
            }
        });

        // Process all responses in parallel
        foreach ($responses as $url => $response) {
            try {
                // Check if response is an exception (failed request)
                if ($response instanceof \Throwable) {
                    Log::warning('LinkValidator: Request failed with exception', [
                        'url' => $url,
                        'exception' => get_class($response),
                        'error' => $response->getMessage(),
                    ]);

                    $source = $this->createSourceFromError($url, 'Connection Error: '.$response->getMessage());
                    $results[$url] = $this->formatSourceResult($source);

                    continue;
                }

                // Handle successful responses
                if ($response->successful()) {
                    $statusCode = $response->status();
                    $contentType = $response->header('Content-Type', '');

                    // Check if it's HTML content
                    if (stripos($contentType, 'text/html') !== false) {
                        $body = $response->body();

                        // Convert body to UTF-8 if necessary
                        $charset = 'UTF-8';
                        if (preg_match('/charset=([a-zA-Z0-9\\-]+)/i', $contentType, $matches)) {
                            $charset = strtoupper($matches[1]);
                        }

                        if ($charset !== 'UTF-8') {
                            $body = mb_convert_encoding($body, 'UTF-8', $charset);
                        }

                        // Process HTML content (similar to single URL validation)
                        $result = $this->processHtmlContent($url, $body, $statusCode, $contentType);
                        $results[$url] = $result;

                    } else {
                        // Non-HTML content
                        $source = $this->createSourceFromError($url, 'Unsupported Content-Type');
                        $results[$url] = $this->formatSourceResult($source);
                    }
                } else {
                    // HTTP error (4xx, 5xx status codes)
                    $source = $this->createSourceFromError($url, $response->status());
                    $results[$url] = $this->formatSourceResult($source);
                }

            } catch (\Exception $e) {
                Log::warning('LinkValidator: Exception during parallel validation', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                $source = $this->createSourceFromError($url, 'Validation Exception: '.$e->getMessage());
                $results[$url] = $this->formatSourceResult($source);
            }
        }

        $duration = microtime(true) - $start;
        Log::info('LinkValidator: Parallel validation completed', [
            'total_urls' => count($urls),
            'validated_urls' => count($urlsToValidate),
            'cached_urls' => count($urls) - count($urlsToValidate),
            'duration_ms' => $duration * 1000,
            'avg_per_url_ms' => count($urlsToValidate) > 0 ? ($duration * 1000) / count($urlsToValidate) : 0,
        ]);

        return $results;
    }

    /**
     * Process HTML content for a single URL (extracted from validateAndExtractLinkInfo)
     */
    private function processHtmlContent(string $url, string $body, int $statusCode, string $contentType): array
    {
        // Parse HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        if (! $dom->loadHTML($body)) {
            libxml_clear_errors();
            $source = $this->createSourceFromError($url, 'Failed to parse HTML');

            return $this->formatSourceResult($source);
        }
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        // Extract metadata (similar to single URL validation but optimized)
        $result = [
            'title' => null,
            'description' => null,
            'favicon' => null,
            'open_graph' => [],
            'twitter_card' => [],
            'content_markdown' => null,
        ];

        // Extract Title
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $result['title'] = trim($titles->item(0)->textContent);
        }

        // Extract Meta Description
        $metaDescription = $this->extractMetaContent($xpath, 'description', 'content');
        if ($metaDescription) {
            $result['description'] = trim($metaDescription);
        }

        // Extract Open Graph data (basic)
        $ogTitle = $this->extractMetaContent($xpath, 'og:title', 'content');
        if ($ogTitle) {
            $result['open_graph']['title'] = trim($ogTitle);
        }

        $ogDescription = $this->extractMetaContent($xpath, 'og:description', 'content');
        if ($ogDescription) {
            $result['open_graph']['description'] = trim($ogDescription);
        }

        // Extract favicon (basic)
        $iconLinks = $xpath->query("//link[contains(translate(@rel, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'icon')]");
        if ($iconLinks->length > 0) {
            $href = $iconLinks->item(0)->getAttribute('href');
            if ($href) {
                $result['favicon'] = $this->resolveUrl($href, $url);
            }
        } else {
            $result['favicon'] = $this->resolveUrl('/favicon.ico', $url);
        }

        // Skip content extraction for parallel processing (can be done later if needed)
        // This keeps parallel processing fast
        $result['content_markdown'] = null;

        // Create source data
        $domain = parse_url($url, PHP_URL_HOST);
        $contentCategory = $this->determineContentCategory($url, $result['title'], $result['description']);

        $sourceData = [
            'url' => $url,
            'domain' => $domain,
            'title' => $result['title'],
            'description' => $result['description'],
            'favicon' => $result['favicon'],
            'open_graph' => $result['open_graph'],
            'twitter_card' => $result['twitter_card'],
            'content_markdown' => $result['content_markdown'],
            'content_preview' => null,
            'http_status' => $statusCode,
            'content_type' => $contentType,
            'content_category' => $contentCategory,
            'is_scrapeable' => $statusCode < 400,
            'validation_metadata' => [
                'validated_at' => now()->toISOString(),
                'parallel_validation' => true,
                'content_extracted' => false, // Not extracted in parallel mode
            ],
        ];

        $source = Source::createOrUpdate($sourceData);

        return $this->formatSourceResult($source);
    }

    /**
     * Create source from error (helper method)
     */
    private function createSourceFromError(string $url, string $status): Source
    {
        $sourceData = $this->createSourceDataFromError($url, $status);

        return Source::createOrUpdate($sourceData);
    }

    /**
     * Resolves a relative URL against a base URL to form an absolute URL.
     *
     * @param  string  $relative  The relative URL.
     * @param  string  $base  The base URL.
     * @return string The absolute URL.
     */
    private function resolveUrl(string $relative, string $base): string
    {
        // If relative URL has a scheme, return it as is
        if (parse_url($relative, PHP_URL_SCHEME) !== null) {
            return $relative;
        }

        // Handle protocol-relative URLs
        if (strpos($relative, '//') === 0) {
            $scheme = parse_url($base, PHP_URL_SCHEME);

            return $scheme.':'.$relative;
        }

        // Handle root-relative URLs
        if (strpos($relative, '/') === 0) {
            $parts = parse_url($base);
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';

            return "{$parts['scheme']}://{$parts['host']}{$port}{$relative}";
        }

        // Handle relative URLs
        $path = parse_url($base, PHP_URL_PATH);
        $path = preg_replace('#/[^/]*$#', '/', $path);
        $abs = $path.$relative;

        // Normalize the path (resolve ../ and ./)
        $re = ['#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#'];
        for ($n = 1; $n > 0;) {
            $abs = preg_replace($re, '/', $abs, -1, $n);
        }

        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);
        $port = $port ? ':'.$port : '';

        return parse_url($base, PHP_URL_SCHEME)."://{$host}{$port}{$abs}";
    }

    /**
     * Extracts metadata from the DOM using XPath.
     *
     * @param  DOMXPath  $xpath  The DOMXPath instance.
     * @param  string  $tag  The tag name to search for.
     * @param  string  $attribute  The attribute to extract.
     * @param  string|null  $default  Optional default value if attribute is not found.
     * @return string|null The extracted attribute value or default.
     */
    private function extractMetaContent(DOMXPath $xpath, string $tag, string $attribute, ?string $default = null): ?string
    {
        $tagLower = strtolower($tag);
        $nodes = $xpath->query("//meta[translate(@property, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='$tagLower' or translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='$tagLower']");

        if ($nodes->length > 0) {
            /** @var \DOMElement $node */
            $node = $nodes->item(0);

            return $node->getAttribute($attribute) ?: $default;
        }

        return $default;
    }

    /**
     * Perform HTTP request and return structured data
     */
    private function performHttpRequest(string $url): array
    {
        try {
            // Get a random user agent for better diversity
            $userAgent = $this->getRandomUserAgent();

            Log::debug('LinkValidator: Using user agent', [
                'url' => $url,
                'user_agent' => $userAgent,
            ]);

            $response = Http::withOptions([
                'timeout' => 10.0,
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true,
                ],
                'verify' => true, // Enable SSL certificate verification
            ])->withHeaders([
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Sec-CH-UA' => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
                'Sec-CH-UA-Mobile' => '?0',
                'Sec-CH-UA-Platform' => '"Windows"',
            ])->get($url);

            $statusCode = $response->status();
            $contentType = $response->header('Content-Type');

            if ($statusCode >= 400) {
                return [
                    'error' => true,
                    'status' => $statusCode,
                    'user_agent' => $userAgent,
                ];
            }

            // Get the content type to ensure it's HTML
            if (stripos($contentType, 'text/html') === false) {
                return [
                    'error' => true,
                    'status' => 'Unsupported Content-Type',
                    'user_agent' => $userAgent,
                ];
            }

            // Handle character encoding
            $charset = 'UTF-8'; // Default charset
            if (preg_match('/charset=([a-zA-Z0-9\\-]+)/i', $contentType, $matches)) {
                $charset = strtoupper($matches[1]);
            }

            $body = $response->body();

            // Convert body to UTF-8 if necessary
            if ($charset !== 'UTF-8') {
                $body = mb_convert_encoding($body, 'UTF-8', $charset);
            }

            return [
                'error' => false,
                'status' => $statusCode,
                'body' => $body,
                'content_type' => $contentType,
                'user_agent' => $userAgent,
            ];

        } catch (Exception $e) {
            return [
                'error' => true,
                'status' => 'Request Exception: '.$e->getMessage(),
                'user_agent' => $userAgent ?? 'unknown',
            ];
        }
    }

    /**
     * Create source data from error
     */
    private function createSourceDataFromError(string $url, string $status): array
    {
        return [
            'url' => $url,
            'http_status' => is_numeric($status) ? (int) $status : 500,
            'title' => null,
            'description' => null,
            'favicon' => null,
            'open_graph' => [],
            'twitter_card' => [],
            'content_markdown' => null,
            'content_preview' => null,
            'content_type' => null,
            'content_category' => 'general',
            'is_scrapeable' => false,
            'validation_metadata' => [
                'error' => true,
                'error_message' => $status,
                'validated_at' => now()->toISOString(),
            ],
            'last_user_agent' => null,
        ];
    }

    /**
     * Create error result in old format for backward compatibility
     */
    private function createErrorResult(string $url, string $status): array
    {
        return [
            'favicon' => null,
            'title' => null,
            'description' => null,
            'status' => $status,
            'content_markdown' => null,
            'open_graph' => [],
            'twitter_card' => [],
        ];
    }

    /**
     * Format Source model data for old API compatibility
     */
    private function formatSourceResult(Source $source): array
    {
        return [
            'favicon' => $source->favicon,
            'title' => $source->title,
            'description' => $source->description,
            'status' => $source->http_status,
            'content_markdown' => $source->content_markdown,
            'open_graph' => $source->open_graph ?? [],
            'twitter_card' => $source->twitter_card ?? [],
        ];
    }

    /**
     * Report status to StatusReporter if available
     */
    private function reportStatus(string $message): void
    {
        try {
            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                if ($statusReporter instanceof StatusReporter) {
                    $statusReporter->report('link_validator', $message);
                }
            }
        } catch (\Exception $e) {
            Log::debug('LinkValidator: Failed to report status', [
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clean HTML content to prevent DOMEntityReference issues with Readability
     */
    private function cleanHtmlForReadability(string $html): string
    {
        // First pass: Convert all HTML entities to their actual characters
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Second pass: Fix any remaining problematic ampersands
        // Only encode standalone & that aren't part of valid entities
        $html = preg_replace('/&(?![a-zA-Z][a-zA-Z0-9]*;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', $html);

        // Remove any malformed entity references (e.g., "&something" without semicolon)
        $html = preg_replace('/&[a-zA-Z0-9]+(?!\w|;)/', '', $html);

        // Third pass: Normalize whitespace and common characters
        $replacements = [
            '&nbsp;' => ' ',
            '&mdash;' => '—',
            '&ndash;' => '–',
            '&ldquo;' => '"',
            '&rdquo;' => '"',
            '&lsquo;' => "'",
            '&rsquo;' => "'",
            '&hellip;' => '…',
            '&trade;' => '™',
            '&copy;' => '©',
            '&reg;' => '®',
        ];

        foreach ($replacements as $entity => $replacement) {
            $html = str_replace($entity, $replacement, $html);
        }

        // Fourth pass: Ensure valid UTF-8 encoding
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }

        // Fifth pass: Load into DOMDocument with LIBXML_NOENT to substitute entities
        try {
            libxml_use_internal_errors(true);
            $doc = new \DOMDocument('1.0', 'UTF-8');
            // LIBXML_NOENT substitutes entities, preventing DOMEntityReference nodes
            $doc->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NOENT
            );

            // Recursively remove any remaining entity reference nodes
            $this->removeEntityReferenceNodes($doc);

            $html = $doc->saveHTML();
            libxml_clear_errors();
        } catch (\Throwable $e) {
            // If DOM cleaning fails, continue with regex-cleaned version
            Log::debug('LinkValidator: DOM cleaning skipped', [
                'error' => $e->getMessage(),
            ]);
        }

        return $html;
    }

    /**
     * Recursively remove DOMEntityReference nodes from a DOM tree
     */
    private function removeEntityReferenceNodes(\DOMNode $node): void
    {
        $nodesToRemove = [];

        // First, collect entity reference nodes
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ENTITY_REF_NODE) {
                    $nodesToRemove[] = $child;
                } else {
                    // Recursively process child nodes
                    $this->removeEntityReferenceNodes($child);
                }
            }
        }

        // Then remove them (can't remove during iteration)
        foreach ($nodesToRemove as $entityNode) {
            try {
                $node->removeChild($entityNode);
            } catch (\Throwable $e) {
                Log::debug('LinkValidator: Failed to remove entity reference node', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
