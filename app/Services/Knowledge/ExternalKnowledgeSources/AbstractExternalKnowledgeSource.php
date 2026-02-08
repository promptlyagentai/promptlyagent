<?php

namespace App\Services\Knowledge\ExternalKnowledgeSources;

use App\Services\Knowledge\Contracts\ExternalKnowledgeSourceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Abstract Base Class for External Knowledge Sources
 *
 * Provides common functionality for integrating external content providers including
 * caching, rate limiting, authentication, HTTP request handling, and metadata generation.
 *
 * Architecture:
 * - Template Pattern: Subclasses implement getDocument(), getMetadata(), validateSourceIdentifier()
 * - Caching Layer: 15-minute default TTL with configurable expiration per source
 * - Rate Limiting: Per-minute and per-hour request limits with automatic enforcement
 * - Auth Support: Flexible credential injection via setAuthCredentials()
 * - Batch Processing: Default individual processing with override for optimization
 *
 * Rate Limiting:
 * - Default: 60 requests/minute, 1000 requests/hour
 * - Per-source-type tracking using cache keys
 * - Automatic exception on limit exceeded
 *
 * Caching Strategy:
 * - Cache keys: "external_knowledge:{type}:{identifier}:{suffix}"
 * - Default TTL: 15 minutes (overridable)
 * - Document and metadata cached separately
 *
 * Subclass Responsibilities:
 * - getSourceType(): Return unique identifier (e.g., 'url', 'github', 'confluence')
 * - getDocument(): Fetch and return ExternalKnowledgeDocument
 * - getMetadata(): Fetch and return ExternalKnowledgeMetadata
 * - validateSourceIdentifier(): Validate source ID format
 * - hasChanged(): Detect content changes for refresh logic
 * - getTTL(): Return appropriate expiration time
 * - createBacklink(): Generate URL to original source
 *
 * Optional Overrides:
 * - testConnection(): Custom connection testing
 * - batchProcess(): Optimized batch fetching
 * - handleWebhook(): Real-time update notifications
 * - getVectors(): Pre-computed embeddings (advanced)
 *
 * @see \App\Services\Knowledge\Contracts\ExternalKnowledgeSourceInterface
 * @see \App\Services\Knowledge\ExternalKnowledgeSources\UrlKnowledgeSource
 */
abstract class AbstractExternalKnowledgeSource implements ExternalKnowledgeSourceInterface
{
    protected array $authCredentials = [];

    protected array $config = [];

    protected array $rateLimits = [
        'requests_per_minute' => 60,
        'requests_per_hour' => 1000,
    ];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Set authentication credentials for this source.
     */
    public function setAuthCredentials(array $credentials): void
    {
        $this->authCredentials = $credentials;
    }

    /**
     * Get authentication requirements for accessing this source.
     */
    public function getAuthRequirements(): array
    {
        return [];
    }

    /**
     * Get rate limiting information for this source.
     */
    public function getRateLimits(): array
    {
        return $this->rateLimits;
    }

    /**
     * Test connection to the external source.
     */
    public function testConnection(): bool
    {
        try {
            // Default implementation - subclasses should override
            return true;
        } catch (\Exception $e) {
            Log::warning("Connection test failed for {$this->getSourceType()}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Batch process multiple documents from this source.
     * Default implementation processes individually - override for efficiency.
     */
    public function batchProcess(array $sourceIdentifiers): array
    {
        $results = [];

        foreach ($sourceIdentifiers as $identifier) {
            try {
                $results[$identifier] = $this->getDocument($identifier);
            } catch (\Exception $e) {
                Log::error("Failed to process document {$identifier}: {$e->getMessage()}");
                $results[$identifier] = null;
            }
        }

        return $results;
    }

    /**
     * Get additional configuration options for this source type.
     */
    public function getConfigurationSchema(): array
    {
        return [
            'ttl_hours' => [
                'type' => 'integer',
                'default' => 24,
                'description' => 'Default TTL in hours for documents from this source',
            ],
            'auto_refresh' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Automatically refresh documents before expiration',
            ],
            'refresh_interval' => [
                'type' => 'string',
                'default' => '1 hour',
                'description' => 'Interval for automatic refresh checks',
            ],
        ];
    }

    /**
     * Handle webhook notifications for document updates.
     * Default implementation - override in specific source implementations.
     */
    public function handleWebhook(array $webhookData): bool
    {
        Log::info("Received webhook for {$this->getSourceType()}", $webhookData);

        return false;
    }

    /**
     * Get pre-computed vectors for a document (default: null).
     */
    public function getVectors(string $sourceIdentifier): ?array
    {
        return null;
    }

    /**
     * Determine if this document should be marked for "refresh before expiration".
     */
    public function shouldRefreshBeforeExpiration(string $sourceIdentifier): bool
    {
        return $this->config['auto_refresh'] ?? false;
    }

    /**
     * Get the refresh interval for documents that should be refreshed automatically.
     */
    public function getRefreshInterval(string $sourceIdentifier): ?string
    {
        return $this->config['refresh_interval'] ?? '1 hour';
    }

    /**
     * Generate or retrieve missing crucial metadata using AI assistance.
     */
    public function generateMissingMetadata(string $sourceIdentifier, array $existingMetadata = []): array
    {
        $generated = [];

        // If title is missing, generate from content
        if (empty($existingMetadata['title'])) {
            $generated['title'] = $this->generateTitle($sourceIdentifier, $existingMetadata);
        }

        // If description is missing, generate from content
        if (empty($existingMetadata['description'])) {
            $generated['description'] = $this->generateDescription($sourceIdentifier, $existingMetadata);
        }

        // If categories are missing, generate from content
        if (empty($existingMetadata['categories'])) {
            $generated['categories'] = $this->generateCategories($sourceIdentifier, $existingMetadata);
        }

        return $generated;
    }

    /**
     * Cache key for storing document data.
     */
    protected function getCacheKey(string $sourceIdentifier, string $suffix = ''): string
    {
        $key = "external_knowledge:{$this->getSourceType()}:{$sourceIdentifier}";

        return $suffix ? "{$key}:{$suffix}" : $key;
    }

    /**
     * Get cached document data.
     */
    protected function getCachedData(string $sourceIdentifier, string $suffix = '', ?Carbon $ttl = null): mixed
    {
        $key = $this->getCacheKey($sourceIdentifier, $suffix);

        return Cache::get($key);
    }

    /**
     * Cache document data.
     */
    protected function cacheData(string $sourceIdentifier, mixed $data, string $suffix = '', ?Carbon $ttl = null): void
    {
        $key = $this->getCacheKey($sourceIdentifier, $suffix);
        $ttl = $ttl ?? now()->addMinutes(15); // Default 15 minutes
        Cache::put($key, $data, $ttl);
    }

    /**
     * Make HTTP request with rate limiting and error handling.
     */
    protected function makeHttpRequest(string $url, string $method = 'GET', array $options = []): array
    {
        // Check rate limits
        $this->checkRateLimit();

        try {
            $response = Http::timeout(30)
                ->when(! empty($this->authCredentials), function ($http) {
                    return $this->addAuthentication($http);
                })
                ->$method($url, $options);

            if ($response->successful()) {
                return $response->json() ?? ['content' => $response->body()];
            }

            throw new \Exception("HTTP {$response->status()}: {$response->body()}");
        } catch (\Exception $e) {
            Log::error("HTTP request failed for {$this->getSourceType()}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Add authentication to HTTP client.
     */
    protected function addAuthentication($http)
    {
        // Override in subclasses for specific auth methods
        return $http;
    }

    /**
     * Check rate limits before making requests.
     */
    protected function checkRateLimit(): void
    {
        try {
            $sourceType = $this->getSourceType();
            $minuteKey = "rate_limit:{$sourceType}:minute:".now()->format('Y-m-d-H-i');
            $hourKey = "rate_limit:{$sourceType}:hour:".now()->format('Y-m-d-H');

            $minuteCount = Cache::get($minuteKey, 0);
            $hourCount = Cache::get($hourKey, 0);

            if ($minuteCount >= $this->rateLimits['requests_per_minute']) {
                throw new \Exception("Rate limit exceeded: {$this->rateLimits['requests_per_minute']} requests per minute");
            }

            if ($hourCount >= $this->rateLimits['requests_per_hour']) {
                throw new \Exception("Rate limit exceeded: {$this->rateLimits['requests_per_hour']} requests per hour");
            }

            // Increment counters
            Cache::put($minuteKey, $minuteCount + 1, now()->addMinute());
            Cache::put($hourKey, $hourCount + 1, now()->addHour());
        } catch (\RedisException $e) {
            // Redis unavailable - skip rate limiting, allow request to proceed
            Log::info('External knowledge source rate limit check skipped - cache unavailable', [
                'source_type' => $this->getSourceType(),
            ]);
        }
    }

    /**
     * Generate title from content.
     */
    protected function generateTitle(string $sourceIdentifier, array $existingMetadata): string
    {
        return 'Generated Title for '.basename($sourceIdentifier);
    }

    /**
     * Generate description from content.
     */
    protected function generateDescription(string $sourceIdentifier, array $existingMetadata): string
    {
        return 'Generated description for external knowledge source';
    }

    /**
     * Generate categories from content.
     */
    protected function generateCategories(string $sourceIdentifier, array $existingMetadata): array
    {
        return ['external', 'imported'];
    }

    /**
     * Extract text content from HTML.
     */
    protected function extractTextFromHtml(string $html): string
    {
        return strip_tags($html);
    }

    /**
     * Calculate content hash.
     */
    protected function calculateContentHash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Parse date string to Carbon instance.
     */
    protected function parseDate(?string $dateString): ?Carbon
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            return Carbon::parse($dateString);
        } catch (\Exception $e) {
            Log::warning("Failed to parse date: {$dateString}");

            return null;
        }
    }
}
