<?php

namespace App\Services\Knowledge;

use App\Events\ExternalKnowledgeUpdated;
use App\Jobs\RefreshExternalKnowledgeJob;
use App\Models\KnowledgeDocument;
use App\Services\Integrations\Contracts\KnowledgeSourceProvider;
use App\Services\Integrations\ProviderRegistry;
use App\Services\Knowledge\Contracts\ExternalKnowledgeSourceInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * External Knowledge Source Management Service
 *
 * Manages integration with external knowledge sources (URLs, APIs, cloud services) including
 * content fetching, auto-refresh scheduling, metadata generation, and webhook handling.
 *
 * Architecture:
 * - Provider Discovery: Dynamically finds knowledge sources via ProviderRegistry
 * - Auto-refresh: Scheduled background refresh for time-sensitive content
 * - TTL Management: Configurable content expiration with domain-specific heuristics
 * - AI Enhancement: Generates missing metadata (title, description, tags) using FileAnalyzer
 * - Webhook Support: Handles real-time updates from external providers
 *
 * Supported Source Types:
 * - URL sources: Web pages, documentation sites, blogs (via UrlKnowledgeSource)
 * - Integration providers: Services implementing KnowledgeSourceProvider
 * - Custom sources: Any class implementing ExternalKnowledgeSourceInterface
 *
 * Refresh Strategy:
 * - Manual refresh: Triggered by user action or webhook
 * - Auto-refresh: Scheduled via next_refresh_at timestamp + RefreshExternalKnowledgeJob
 * - TTL-based: Content expires based on domain type (news: 4h, docs: 1 week, default: 24h)
 * - Change detection: Uses content hashing and HTTP headers (ETag, Last-Modified)
 *
 * Metadata Generation:
 * - AI-powered: FileAnalyzer generates title, description, suggested tags
 * - Tag merging: Combines user tags, AI suggestions, and source metadata
 * - Entity tags: Supports client:*, service:*, project:* specific tags
 *
 * @see \App\Services\Knowledge\Contracts\ExternalKnowledgeSourceInterface
 * @see \App\Services\Knowledge\ExternalKnowledgeSources\UrlKnowledgeSource
 * @see \App\Jobs\RefreshExternalKnowledgeJob
 */
class ExternalKnowledgeManager
{
    private KnowledgeManager $knowledgeManager;

    private ProviderRegistry $providerRegistry;

    public function __construct(KnowledgeManager $knowledgeManager, ProviderRegistry $providerRegistry)
    {
        $this->knowledgeManager = $knowledgeManager;
        $this->providerRegistry = $providerRegistry;
    }

    /**
     * Add external knowledge source.
     */
    public function addExternalSource(
        string $sourceIdentifier,
        string $sourceType,
        ?string $title = null,
        ?string $description = null,
        array $tags = [],
        string $privacyLevel = 'private',
        ?int $ttlHours = null,
        bool $autoRefresh = false,
        ?int $refreshIntervalMinutes = null,
        array $authCredentials = [],
        ?int $userId = null,
        ?string $integrationId = null
    ): KnowledgeDocument {
        $source = $this->getSourceInstance($sourceType, $authCredentials);

        // Validate source identifier
        if (! $source->validateSourceIdentifier($sourceIdentifier)) {
            throw new \Exception("Invalid source identifier for type: {$sourceType}");
        }

        try {
            // Fetch document data
            $document = $source->getDocument($sourceIdentifier);
            $metadata = $source->getMetadata($sourceIdentifier);

            // Use provided title/description or generated ones
            $finalTitle = $title ?? $document->title;
            $finalDescription = $description ?? $document->description;

            // Generate missing metadata if needed
            if (empty($finalTitle) || empty($finalDescription)) {
                $generated = $source->generateMissingMetadata($sourceIdentifier, [
                    'title' => $finalTitle,
                    'description' => $finalDescription,
                ]);

                $finalTitle = $finalTitle ?? $generated['title'];
                $finalDescription = $finalDescription ?? $generated['description'];
            }

            // Get AI suggestions for tags based on content
            $aiSuggestions = [];
            if (config('knowledge.file_analysis.enabled', true) && ! empty($document->content)) {
                try {
                    $analyzer = new \App\Services\Knowledge\FileAnalyzer;
                    $aiSuggestions = $analyzer->analyzeTextContent(
                        $document->content,
                        $finalTitle ?? $sourceIdentifier
                    );
                } catch (\Exception $e) {
                    Log::warning('ExternalKnowledgeManager: Content analysis failed', [
                        'source_identifier' => $sourceIdentifier,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Merge AI-suggested tags with metadata tags and provided tags
            $finalTags = $tags;
            if (! empty($aiSuggestions['suggested_tags'])) {
                $finalTags = array_unique(array_merge($tags, $aiSuggestions['suggested_tags']));
            }
            if (! empty($metadata->tags)) {
                $finalTags = array_unique(array_merge($finalTags, $metadata->tags));
            }

            // Use AI-suggested description if still empty
            if (empty($finalDescription) && ! empty($aiSuggestions['suggested_description'])) {
                $finalDescription = $aiSuggestions['suggested_description'];
            }

            // Create knowledge document
            $knowledgeDocument = KnowledgeDocument::create([
                'title' => $finalTitle,
                'description' => $finalDescription,
                'content_type' => 'external',
                'source_type' => $sourceType,
                'content' => $document->content,
                'external_source_identifier' => $sourceIdentifier,
                'external_source_class' => $this->discoverSourceClass($sourceType),
                'privacy_level' => $privacyLevel,
                'processing_status' => 'completed',
                'ttl_expires_at' => $ttlHours ? now()->addHours($ttlHours) : $source->getTTL($sourceIdentifier),
                'last_fetched_at' => now(),
                'content_hash' => $document->getContentHash(),
                'auto_refresh_enabled' => $autoRefresh || $source->shouldRefreshBeforeExpiration($sourceIdentifier),
                'refresh_interval_minutes' => $refreshIntervalMinutes ?? $this->parseRefreshInterval($source->getRefreshInterval($sourceIdentifier)),
                'next_refresh_at' => $autoRefresh ? now()->addMinutes($refreshIntervalMinutes ?? 60) : null,
                'external_metadata' => $metadata->toArray(),
                'favicon_url' => $metadata->favicon,
                'thumbnail_url' => $metadata->thumbnail,
                'author' => $metadata->author,
                'language' => $metadata->language,
                'published_at' => $metadata->publishedAt,
                'last_modified_at' => $metadata->lastModified,
                'word_count' => $metadata->wordCount ?? $document->getWordCount(),
                'reading_time_minutes' => $metadata->getEstimatedReadingTime(),
                'metadata' => [
                    'backlink_url' => $source->createBacklink($sourceIdentifier),
                    'source_metadata' => $metadata->customFields,
                    'fetch_timestamp' => now()->toISOString(),
                ],
                'created_by' => $userId ?? Auth::id(),
                'integration_id' => $integrationId,
            ]);

            // Attach tags (already merged with AI suggestions and metadata tags)
            $this->knowledgeManager->attachTags($knowledgeDocument, $finalTags, $userId);

            // Queue processing for embeddings
            $this->knowledgeManager->queueProcessing($knowledgeDocument);

            // Schedule refresh if needed
            if ($knowledgeDocument->auto_refresh_enabled && $knowledgeDocument->next_refresh_at) {
                $this->scheduleRefresh($knowledgeDocument);
            }

            event(new ExternalKnowledgeUpdated($knowledgeDocument, 'created'));

            return $knowledgeDocument;

        } catch (\Exception $e) {
            Log::error('Failed to add external knowledge source', [
                'source_identifier' => $sourceIdentifier,
                'source_type' => $sourceType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Refresh external knowledge document.
     */
    public function refreshDocument(KnowledgeDocument $document): bool
    {
        if ($document->content_type === 'external') {
            return $this->refreshExternalDocument($document);
        }

        if ($document->content_type === 'text') {
            return $this->refreshTextDocument($document);
        }

        throw new \InvalidArgumentException('Document type not refreshable: '.$document->content_type);
    }

    /**
     * Refresh external knowledge document (original implementation).
     */
    private function refreshExternalDocument(KnowledgeDocument $document): bool
    {
        if ($document->content_type !== 'external') {
            throw new \Exception('Document is not an external knowledge source');
        }

        $source = $this->getSourceInstanceFromDocument($document);

        try {
            // Check if document has changed
            $hasChanged = $source->hasChanged(
                $document->external_source_identifier,
                $document->last_fetched_at,
                $document->content_hash
            );

            if (! $hasChanged) {
                // Calculate original TTL duration (from created to expiration)
                $ttlDuration = $document->ttl_expires_at && $document->created_at
                    ? (int) round($document->created_at->diffInHours($document->ttl_expires_at, false))
                    : 24; // Default to 24 hours if no TTL was set

                // Update last fetched timestamp and extend TTL even if no changes
                $document->update([
                    'last_fetched_at' => now(),
                    'ttl_expires_at' => $document->ttl_expires_at ? now()->addHours($ttlDuration) : null,
                    'next_refresh_at' => $document->auto_refresh_enabled ?
                        now()->addMinutes($document->refresh_interval_minutes ?? 60) : null,
                ]);

                return false; // No changes detected
            }

            // Fetch updated document
            $updatedDocument = $source->getDocument($document->external_source_identifier);
            $updatedMetadata = $source->getMetadata($document->external_source_identifier);

            // Calculate original TTL duration (from created to expiration)
            $ttlDuration = $document->ttl_expires_at && $document->created_at
                ? (int) round($document->created_at->diffInHours($document->ttl_expires_at, false))
                : 24; // Default to 24 hours if no TTL was set

            // Update document with new content
            $document->update([
                'content' => $updatedDocument->content,
                'last_fetched_at' => now(),
                'content_hash' => $updatedDocument->getContentHash(),
                'processing_status' => 'pending', // Re-process embeddings
                'external_metadata' => $updatedMetadata->toArray(),
                'favicon_url' => $updatedMetadata->favicon,
                'thumbnail_url' => $updatedMetadata->thumbnail,
                'author' => $updatedMetadata->author,
                'language' => $updatedMetadata->language,
                'published_at' => $updatedMetadata->publishedAt,
                'last_modified_at' => $updatedMetadata->lastModified,
                'word_count' => $updatedMetadata->wordCount ?? $updatedDocument->getWordCount(),
                'reading_time_minutes' => $updatedMetadata->getEstimatedReadingTime(),
                'ttl_expires_at' => $document->ttl_expires_at ? now()->addHours($ttlDuration) : null,
                'next_refresh_at' => $document->auto_refresh_enabled ?
                    now()->addMinutes($document->refresh_interval_minutes ?? 60) : null,
            ]);

            // Re-queue processing for updated content
            $this->knowledgeManager->queueProcessing($document);

            // Schedule next refresh
            if ($document->auto_refresh_enabled) {
                $this->scheduleRefresh($document);
            }

            event(new ExternalKnowledgeUpdated($document, 'updated'));

            return true; // Document was updated

        } catch (\Exception $e) {
            Log::error('Failed to refresh external knowledge document', [
                'document_id' => $document->id,
                'source_identifier' => $document->external_source_identifier,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Refresh text knowledge document with external URL.
     */
    private function refreshTextDocument(KnowledgeDocument $document): bool
    {
        if ($document->content_type !== 'text') {
            throw new \Exception('Document is not a text document');
        }

        if (! $document->external_source_identifier) {
            throw new \Exception('Document does not have an external source URL');
        }

        try {
            Log::info('ExternalKnowledgeManager: Refreshing text document', [
                'document_id' => $document->id,
                'url' => $document->external_source_identifier,
            ]);

            // Use MarkItDown to fetch and convert the URL to markdown
            $markitdownUrl = config('services.markitdown.url', 'http://markitdown:8000');

            $response = \Illuminate\Support\Facades\Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($markitdownUrl.'/convert', [
                    'url' => $document->external_source_identifier,
                    'format' => 'markdown',
                ]);

            if (! $response->successful()) {
                $error = 'MarkItDown conversion failed with status: '.$response->status();
                Log::error('ExternalKnowledgeManager: Failed to fetch content', [
                    'document_id' => $document->id,
                    'url' => $document->external_source_identifier,
                    'status' => $response->status(),
                ]);

                // Update refresh tracking
                $document->update([
                    'last_refresh_attempted_at' => now(),
                    'last_refresh_status' => 'failed',
                    'last_refresh_error' => $error,
                    'refresh_attempt_count' => ($document->refresh_attempt_count ?? 0) + 1,
                ]);

                throw new \Exception($error);
            }

            $data = $response->json();
            $newContent = $data['markdown'] ?? $data['content'] ?? '';

            if (empty($newContent)) {
                throw new \Exception('No content returned from MarkItDown service');
            }

            // Check if content has changed
            $newContentHash = hash('sha256', $newContent);

            if ($document->content_hash === $newContentHash) {
                // Calculate original TTL duration
                $ttlDuration = $document->ttl_expires_at && $document->created_at
                    ? (int) round($document->created_at->diffInHours($document->ttl_expires_at, false))
                    : 24;

                // Update last fetched timestamp and extend TTL even if no changes
                $document->update([
                    'last_fetched_at' => now(),
                    'ttl_expires_at' => $document->ttl_expires_at ? now()->addHours($ttlDuration) : null,
                    'next_refresh_at' => $document->auto_refresh_enabled ?
                        now()->addMinutes($document->refresh_interval_minutes ?? 60) : null,
                    'last_refresh_attempted_at' => now(),
                    'last_refresh_status' => 'success',
                    'last_refresh_error' => null,
                    'refresh_attempt_count' => 0, // Reset on success
                ]);

                Log::info('ExternalKnowledgeManager: Text document content unchanged', [
                    'document_id' => $document->id,
                ]);

                return false; // No changes detected
            }

            // Calculate original TTL duration
            $ttlDuration = $document->ttl_expires_at && $document->created_at
                ? (int) round($document->created_at->diffInHours($document->ttl_expires_at, false))
                : 24;

            // Content has changed - update document
            // NOTE: We do NOT update 'metadata' field - this preserves notes!
            $document->update([
                'content' => $newContent,
                'last_fetched_at' => now(),
                'content_hash' => $newContentHash,
                'processing_status' => 'pending', // Re-process embeddings
                'ttl_expires_at' => $document->ttl_expires_at ? now()->addHours($ttlDuration) : null,
                'next_refresh_at' => $document->auto_refresh_enabled ?
                    now()->addMinutes($document->refresh_interval_minutes ?? 60) : null,
                'last_refresh_attempted_at' => now(),
                'last_refresh_status' => 'success',
                'last_refresh_error' => null,
                'refresh_attempt_count' => 0, // Reset on success
            ]);

            // Re-queue processing for updated content
            $this->knowledgeManager->queueProcessing($document);

            // Schedule next refresh
            if ($document->auto_refresh_enabled) {
                $this->scheduleRefresh($document);
            }

            event(new ExternalKnowledgeUpdated($document, 'updated'));

            Log::info('ExternalKnowledgeManager: Text document refreshed successfully', [
                'document_id' => $document->id,
            ]);

            return true; // Document was updated

        } catch (\Exception $e) {
            Log::error('Failed to refresh text knowledge document', [
                'document_id' => $document->id,
                'source_identifier' => $document->external_source_identifier,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update refresh tracking on error
            $document->update([
                'last_refresh_attempted_at' => now(),
                'last_refresh_status' => 'failed',
                'last_refresh_error' => substr($e->getMessage(), 0, 500),
                'refresh_attempt_count' => ($document->refresh_attempt_count ?? 0) + 1,
            ]);

            throw $e;
        }
    }

    /**
     * Check if external source has changed.
     */
    public function checkIfSourceChanged(KnowledgeDocument $document): bool
    {
        if ($document->content_type !== 'external') {
            throw new \Exception('Document is not an external knowledge source');
        }

        $source = $this->getSourceInstanceFromDocument($document);

        return $source->hasChanged(
            $document->external_source_identifier,
            $document->last_fetched_at,
            $document->content_hash
        );
    }

    /**
     * Handle webhook for external source updates.
     */
    public function handleWebhook(array $webhookData, ?string $sourceType = null, ?string $signature = null): bool
    {
        if (! $sourceType) {
            Log::warning('Webhook received without source type');

            return false;
        }

        // Check if source class is available
        $sourceClass = $this->discoverSourceClass($sourceType);
        if (! $sourceClass) {
            Log::warning("Unknown source type in webhook: {$sourceType}");

            return false;
        }

        $source = $this->getSourceInstance($sourceType);

        try {
            $processed = $source->handleWebhook($webhookData);

            if ($processed) {
                // Extract source identifier from webhook data
                $sourceIdentifier = $this->extractSourceIdentifierFromWebhook($webhookData, $sourceType);

                if ($sourceIdentifier) {
                    // Find and refresh the document
                    $document = KnowledgeDocument::where('content_type', 'external')
                        ->where('source_type', $sourceType)
                        ->where('external_source_identifier', $sourceIdentifier)
                        ->first();

                    if ($document) {
                        RefreshExternalKnowledgeJob::dispatch($document);
                        Log::info('Webhook triggered refresh for document', [
                            'document_id' => $document->id,
                            'source_type' => $sourceType,
                        ]);
                    }
                }
            }

            return $processed;

        } catch (\Exception $e) {
            Log::error('Failed to handle webhook', [
                'source_type' => $sourceType,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get available external knowledge source types.
     * Dynamically discovers sources from ProviderRegistry
     */
    public function getAvailableSourceTypes(): array
    {
        $types = [];

        // Get all providers that support knowledge import
        foreach ($this->providerRegistry->all() as $provider) {
            if (! ($provider instanceof KnowledgeSourceProvider) || ! $provider->supportsKnowledgeImport()) {
                continue;
            }

            $sourceClass = $provider->getKnowledgeSourceClass();
            if (! $sourceClass || ! class_exists($sourceClass)) {
                continue;
            }

            $source = App::make($sourceClass);
            $providerId = $provider->getProviderId();

            $types[] = [
                'type' => $providerId,
                'name' => $provider->getProviderName(),
                'auth_requirements' => $source->getAuthRequirements(),
                'rate_limits' => $source->getRateLimits(),
                'configuration_schema' => $source->getConfigurationSchema(),
            ];
        }

        return $types;
    }

    /**
     * Test connection to external source.
     */
    public function testConnection(string $sourceType, string $sourceIdentifier, array $authCredentials = []): bool
    {
        $source = $this->getSourceInstance($sourceType, $authCredentials);

        if (! $source->validateSourceIdentifier($sourceIdentifier)) {
            return false;
        }

        return $source->testConnection();
    }

    /**
     * Schedule refresh for documents that need it.
     */
    public function scheduleRefreshes(): void
    {
        $documentsToRefresh = KnowledgeDocument::with('integration.integrationToken')
            ->whereIn('content_type', ['external', 'text'])
            ->whereNotNull('external_source_identifier')
            ->where('auto_refresh_enabled', true)
            ->where('next_refresh_at', '<=', now())
            ->get();

        foreach ($documentsToRefresh as $document) {
            RefreshExternalKnowledgeJob::dispatch($document);

            // Update next refresh time to prevent immediate re-scheduling
            $document->update([
                'next_refresh_at' => now()->addMinutes($document->refresh_interval_minutes ?? 60),
            ]);
        }

        Log::info("Scheduled refresh for {$documentsToRefresh->count()} external knowledge documents");
    }

    /**
     * Get source instance for a document.
     */
    private function getSourceInstanceFromDocument(KnowledgeDocument $document): ExternalKnowledgeSourceInterface
    {
        $sourceClass = $document->external_source_class;

        if (! class_exists($sourceClass)) {
            throw new \Exception("Source class not found: {$sourceClass}");
        }

        $source = App::make($sourceClass);

        // Pass integration token if document has one (for refresh operations without auth context)
        if ($document->integration_id && $document->integration) {
            $source->setAuthCredentials([
                'integration_token' => $document->integration->integrationToken,
            ]);
        }

        return $source;
    }

    /**
     * Get source instance by type.
     * Dynamically discovers source class from ProviderRegistry
     */
    private function getSourceInstance(string $sourceType, array $authCredentials = []): ExternalKnowledgeSourceInterface
    {
        $sourceClass = $this->discoverSourceClass($sourceType);

        if (! $sourceClass) {
            throw new \Exception("Unknown source type: {$sourceType}");
        }

        $source = App::make($sourceClass);

        if (! empty($authCredentials)) {
            $source->setAuthCredentials($authCredentials);
        }

        return $source;
    }

    /**
     * Discover source class for a given source type from ProviderRegistry
     */
    private function discoverSourceClass(string $sourceType): ?string
    {
        // Get provider by ID
        $provider = $this->providerRegistry->get($sourceType);

        // Check if provider supports knowledge import
        if (! ($provider instanceof KnowledgeSourceProvider) || ! $provider->supportsKnowledgeImport()) {
            return null;
        }

        // Get source class from provider
        $sourceClass = $provider->getKnowledgeSourceClass();

        if (! $sourceClass || ! class_exists($sourceClass)) {
            return null;
        }

        return $sourceClass;
    }

    /**
     * Parse refresh interval string to minutes.
     */
    private function parseRefreshInterval(?string $interval): ?int
    {
        if (! $interval) {
            return null;
        }

        // Parse intervals like "1 hour", "30 minutes", "2 days"
        if (preg_match('/(\d+)\s*(minute|hour|day)s?/i', $interval, $matches)) {
            $value = (int) $matches[1];
            $unit = strtolower($matches[2]);

            return match ($unit) {
                'minute' => $value,
                'hour' => $value * 60,
                'day' => $value * 60 * 24,
                default => 60, // Default to 1 hour
            };
        }

        return 60; // Default to 1 hour
    }

    /**
     * Schedule refresh job for a document.
     */
    private function scheduleRefresh(KnowledgeDocument $document): void
    {
        if ($document->next_refresh_at && $document->next_refresh_at->isFuture()) {
            RefreshExternalKnowledgeJob::dispatch($document)
                ->delay($document->next_refresh_at);
        }
    }

    /**
     * Extract source identifier from webhook data using common field patterns.
     */
    private function extractSourceIdentifierFromWebhook(array $webhookData, string $sourceType): ?string
    {
        return $webhookData['url'] ?? $webhookData['source'] ?? $webhookData['identifier'] ?? null;
    }
}
