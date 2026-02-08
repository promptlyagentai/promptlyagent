<?php

namespace App\Providers;

use App\Scout\Engines\MeilisearchVectorEngine;
use App\Services\Knowledge\Embeddings\EmbeddingService;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;

/**
 * Scout Vector Search service provider for Meilisearch RAG integration
 *
 * Registers custom 'meilisearch-vector' Scout engine that integrates Laravel Scout
 * with Meilisearch's vector search capabilities for semantic similarity search.
 *
 * Responsibilities:
 * - Registers MeilisearchVectorEngine with EmbeddingService integration
 * - Configures multiple indices (knowledge_documents, research_sources, research_summaries, chat_interactions)
 * - Sets up embedder settings (userProvided source with configurable dimensions)
 * - Configures filterable, searchable, sortable attributes per index
 * - Creates missing indices automatically on boot
 * - Uses caching to prevent redundant configuration on every request
 *
 * Index configuration:
 * - Each index supports vector search with user-provided embeddings
 * - Dimensions configured via knowledge.embeddings.dimensions (default: 3072 for text-embedding-3-large)
 * - Hybrid search: combines vector similarity with keyword search
 *
 * Performance:
 * - Configuration cached for 5 minutes to reduce boot overhead
 * - Polls task completion instead of blocking sleeps
 * - Graceful degradation if Redis cache unavailable during boot
 */
class ScoutVectorServiceProvider extends ServiceProvider
{
    /**
     * Model configurations for Meilisearch indices
     */
    protected array $scoutModels;

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Initialize Scout models configuration with dynamic config values
        $this->initializeScoutModels();

        // Register custom meilisearch-vector engine
        resolve(EngineManager::class)->extend('meilisearch-vector', function () {
            $client = new MeilisearchClient(
                config('scout.meilisearch.host'),
                config('scout.meilisearch.key')
            );

            return new MeilisearchVectorEngine(
                $client,
                app(EmbeddingService::class),
                config('scout.soft_delete', false)
            );
        });

        // Configure Meilisearch indices and embedder settings on boot
        if (config('knowledge.embeddings.enabled', false)) {
            $this->configureMeilisearchIndices();
        }
    }

    /**
     * Initialize Scout model configurations with dynamic config values.
     *
     * Defines index settings for all searchable models including:
     * - Filterable attributes (for WHERE-like queries)
     * - Searchable attributes (full-text search fields)
     * - Sortable attributes (for ORDER BY operations)
     * - Ranking rules (search result ordering algorithm)
     * - Embedder dimensions (vector size from config)
     */
    protected function initializeScoutModels(): void
    {
        $this->scoutModels = [
            'knowledge_documents' => [
                'model' => \App\Models\KnowledgeDocument::class,
                'filterable' => ['privacy_level', 'source_type', 'user_id', 'document_id', 'tags', 'ttl_expires_at', 'created_at'],
                'searchable' => ['title', 'content', 'description', 'tags'], // Add 'tags' to searchable attributes
                'sortable' => ['created_at', 'ttl_expires_at'],
                'ranking' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
                'embedder_dimensions' => config('knowledge.embeddings.dimensions', 3072),
            ],
            'research_sources' => [
                'model' => \App\Models\Source::class,
                'filterable' => ['domain', 'content_category', 'http_status', 'created_at', 'content_retrieved_at'],
                'searchable' => ['title', 'description', 'content_preview', 'url', 'domain'],
                'sortable' => ['created_at', 'content_retrieved_at', 'http_status'],
                'ranking' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
                'embedder_dimensions' => config('knowledge.embeddings.dimensions', 3072),
            ],
            'research_summaries' => [
                'model' => \App\Models\ChatInteractionSource::class,
                'filterable' => ['chat_interaction_id', 'source_id', 'discovery_method', 'discovery_tool', 'was_scraped', 'created_at', 'summary_generated_at'],
                'searchable' => ['content_summary', 'source_title', 'source_url', 'source_domain'],
                'sortable' => ['relevance_score', 'created_at', 'summary_generated_at'],
                'ranking' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
                'embedder_dimensions' => config('knowledge.embeddings.dimensions', 3072),
            ],
            'chat_interactions' => [
                'model' => \App\Models\ChatInteraction::class,
                'filterable' => ['chat_session_id', 'user_id', 'agent_id', 'created_at'],
                'searchable' => ['question', 'answer', 'summary'],
                'sortable' => ['created_at'],
                'ranking' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
                'embedder_dimensions' => config('knowledge.embeddings.dimensions', 3072),
            ],
        ];
    }

    /**
     * Configure all Meilisearch indices and their embedder settings for vector search.
     */
    protected function configureMeilisearchIndices(): void
    {
        // Use cache to prevent running configuration on every request
        $cacheKey = 'meilisearch_indices_configured';
        $cacheDuration = 300; // 5 minutes

        // Check cache but don't fail if Redis is unavailable during boot
        try {
            if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                return; // Configuration already done recently
            }
        } catch (\Exception $e) {
            // Cache unavailable (e.g., Redis not ready), continue with configuration
        }

        try {
            $client = new MeilisearchClient(
                config('scout.meilisearch.host'),
                config('scout.meilisearch.key')
            );

            foreach ($this->scoutModels as $indexName => $config) {
                $this->ensureIndexExists($client, $indexName, $config);
            }

            // Cache that configuration was completed successfully (but don't fail if cache unavailable)
            try {
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, $cacheDuration);
            } catch (\Exception $e) {
                // Cache unavailable, not critical - configuration still succeeded
            }

        } catch (ConnectException $e) {
            // Meilisearch not available (expected during boot when container isn't ready)
            Log::debug('ScoutVectorServiceProvider: Meilisearch not available during boot', [
                'error' => $e->getMessage(),
                'host' => config('scout.meilisearch.host'),
            ]);
        } catch (\Exception $e) {
            Log::error('ScoutVectorServiceProvider: Failed to configure Meilisearch indices', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'embeddings_enabled' => config('knowledge.embeddings.enabled'),
                'host' => config('scout.meilisearch.host'),
            ]);
        }
    }

    /**
     * Ensure a Meilisearch index exists and is properly configured.
     */
    protected function ensureIndexExists(MeilisearchClient $client, string $indexName, array $config): void
    {
        try {
            // Try to get index to see if it exists
            $index = $client->index($indexName);

            // Check if index exists by trying to get its stats
            try {
                $index->stats();
            } catch (ConnectException $e) {
                // Meilisearch not available (e.g., container not ready during boot)
                Log::debug("ScoutVectorServiceProvider: Meilisearch not available for index '{$indexName}'", [
                    'error' => $e->getMessage(),
                ]);

                return;
            } catch (ApiException $e) {
                // Index doesn't exist, create it
                if ($e->httpStatus === 404) {
                    Log::info("ScoutVectorServiceProvider: Creating index '{$indexName}'");
                    $task = $client->createIndex($indexName, ['primaryKey' => 'id']);

                    // Poll for task completion instead of sleeping
                    $this->waitForTaskCompletion($client, $task['taskUid']);
                    $index = $client->index($indexName);
                } else {
                    throw $e;
                }
            }

            // Configure index settings
            $this->configureIndexSettings($index, $indexName, $config);

        } catch (ConnectException $e) {
            // Meilisearch connection failed - this is expected during boot
            Log::debug("ScoutVectorServiceProvider: Meilisearch connection failed for index '{$indexName}'", [
                'error' => $e->getMessage(),
            ]);
        } catch (ApiException $e) {
            Log::error("ScoutVectorServiceProvider: Failed to configure index '{$indexName}'", [
                'error' => $e->getMessage(),
                'http_code' => $e->httpStatus,
            ]);
        }
    }

    /**
     * Configure settings for a specific index.
     */
    protected function configureIndexSettings(Indexes $index, string $indexName, array $config): void
    {
        try {
            // Get current settings to compare and avoid unnecessary updates
            $currentSettings = $index->getSettings();
        } catch (ApiException $e) {
            // If we can't get settings, proceed with updates (might be a new index)
            $currentSettings = [];
        }

        // Configure embedder for vector search
        if (isset($config['embedder_dimensions'])) {
            $embedderSettings = [
                'embedders' => [
                    'default' => [
                        'source' => 'userProvided',
                        'dimensions' => $config['embedder_dimensions'],
                    ],
                ],
            ];

            // Only update if embedder settings are different or don't exist
            $currentEmbedders = $currentSettings['embedders'] ?? [];
            $needsEmbedderUpdate = ! isset($currentEmbedders['default']) ||
                                 $currentEmbedders['default']['source'] !== 'userProvided' ||
                                 $currentEmbedders['default']['dimensions'] !== $config['embedder_dimensions'];

            if ($needsEmbedderUpdate) {
                try {
                    $index->updateSettings($embedderSettings);
                } catch (ApiException $e) {
                    Log::warning("ScoutVectorServiceProvider: Failed to configure embedder for '{$indexName}'", [
                        'error' => $e->getMessage(),
                        'http_code' => $e->httpStatus,
                    ]);
                }
            }
        }

        // Configure filterable attributes
        if (isset($config['filterable']) && ! empty($config['filterable'])) {
            $currentFilterable = $currentSettings['filterableAttributes'] ?? [];

            // Compare arrays - only update if different
            if (array_diff($config['filterable'], $currentFilterable) || array_diff($currentFilterable, $config['filterable'])) {
                try {
                    $index->updateFilterableAttributes($config['filterable']);
                } catch (ApiException $e) {
                    Log::warning("ScoutVectorServiceProvider: Failed to configure filterable attributes for '{$indexName}'", [
                        'error' => $e->getMessage(),
                        'http_code' => $e->httpStatus,
                    ]);
                }
            }
        }

        // Configure searchable attributes
        if (isset($config['searchable']) && ! empty($config['searchable'])) {
            $currentSearchable = $currentSettings['searchableAttributes'] ?? [];

            // Compare arrays - only update if different
            if (array_diff($config['searchable'], $currentSearchable) || array_diff($currentSearchable, $config['searchable'])) {
                try {
                    $index->updateSearchableAttributes($config['searchable']);
                } catch (ApiException $e) {
                    Log::warning("ScoutVectorServiceProvider: Failed to configure searchable attributes for '{$indexName}'", [
                        'error' => $e->getMessage(),
                        'http_code' => $e->httpStatus,
                    ]);
                }
            }
        }

        // Configure sortable attributes
        if (isset($config['sortable']) && ! empty($config['sortable'])) {
            $currentSortable = $currentSettings['sortableAttributes'] ?? [];

            // Compare arrays - only update if different
            if (array_diff($config['sortable'], $currentSortable) || array_diff($currentSortable, $config['sortable'])) {
                try {
                    $index->updateSortableAttributes($config['sortable']);
                } catch (ApiException $e) {
                    Log::warning("ScoutVectorServiceProvider: Failed to configure sortable attributes for '{$indexName}'", [
                        'error' => $e->getMessage(),
                        'http_code' => $e->httpStatus,
                    ]);
                }
            }
        }

        // Configure ranking rules
        if (isset($config['ranking']) && ! empty($config['ranking'])) {
            $currentRanking = $currentSettings['rankingRules'] ?? [];

            // Compare arrays - only update if different
            if (array_diff($config['ranking'], $currentRanking) || array_diff($currentRanking, $config['ranking'])) {
                try {
                    $index->updateRankingRules($config['ranking']);
                } catch (ApiException $e) {
                    Log::warning("ScoutVectorServiceProvider: Failed to configure ranking rules for '{$indexName}'", [
                        'error' => $e->getMessage(),
                        'http_code' => $e->httpStatus,
                    ]);
                }
            }
        }
    }

    /**
     * Get configured models for Scout integration.
     */
    public function getScoutModels(): array
    {
        return $this->scoutModels;
    }

    /**
     * Add a new Scout model configuration.
     */
    public function addScoutModel(string $indexName, array $config): void
    {
        $this->scoutModels[$indexName] = $config;
    }

    /**
     * Wait for a Meilisearch task to complete by polling status.
     *
     * Polls task status every 100ms until task succeeds, fails, or timeout reached.
     * Used after index creation to ensure index is ready before configuration.
     *
     * @param  MeilisearchClient  $client  Meilisearch client instance
     * @param  int  $taskUid  Task UID returned from Meilisearch operation
     * @param  int  $maxWaitTime  Maximum seconds to wait (default: 30)
     * @return void Logs warning if task doesn't complete or fails
     */
    protected function waitForTaskCompletion(MeilisearchClient $client, int $taskUid, int $maxWaitTime = 30): void
    {
        $startTime = time();

        while (time() - $startTime < $maxWaitTime) {
            try {
                $task = $client->getTask($taskUid);

                if ($task['status'] === 'succeeded') {
                    return;
                }

                if ($task['status'] === 'failed') {
                    Log::error("ScoutVectorServiceProvider: Task {$taskUid} failed", [
                        'error' => $task['error'] ?? 'Unknown error',
                    ]);

                    return;
                }

                // Wait 100ms before polling again
                usleep(100000);

            } catch (ApiException $e) {
                Log::warning("ScoutVectorServiceProvider: Error checking task {$taskUid} status", [
                    'error' => $e->getMessage(),
                ]);
                usleep(100000);
            }
        }

        Log::warning("ScoutVectorServiceProvider: Task {$taskUid} did not complete within {$maxWaitTime} seconds");
    }
}
