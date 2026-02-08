<?php

namespace App\Scout\Engines;

use App\Services\Knowledge\Embeddings\EmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Client as MeilisearchClient;

/**
 * Custom Laravel Scout engine for Meilisearch with vector search and hybrid RAG capabilities.
 *
 * Extends the default MeilisearchEngine to provide:
 * - Vector similarity search using embeddings from EmbeddingService
 * - Hybrid search combining semantic (vector) and keyword (text) search with configurable ratios
 * - Intelligent relevance filtering with sharp-drop detection to eliminate low-quality results
 * - Automatic chunking for large documents exceeding embedding model token limits
 * - Transient embedding architecture (embeddings generated at index time, not persisted)
 *
 * Search Strategies:
 * - Pure vector search: semanticRatio = 1.0
 * - Balanced hybrid: semanticRatio = 0.5
 * - Pure text search: parent MeilisearchEngine behavior
 *
 * Relevance Filtering:
 * - Configurable minimum relevance threshold (default: 0.5)
 * - Automatic cutoff detection for sharp relevance drops (>20% decrease)
 * - High-relevance detection (>0.8) to preserve quality results
 *
 * @see \App\Services\Knowledge\Embeddings\EmbeddingService
 * @see \App\Scout\Traits\HasVectorSearch
 */
class MeilisearchVectorEngine extends MeilisearchEngine
{
    protected EmbeddingService $embeddingService;

    /**
     * Create a new Meilisearch Vector engine instance.
     *
     * @param  MeilisearchClient  $meilisearch  Meilisearch client instance
     * @param  EmbeddingService  $embeddingService  Service for generating embeddings
     * @param  bool  $softDelete  Whether to respect soft deletes (default: false)
     */
    public function __construct(MeilisearchClient $meilisearch, EmbeddingService $embeddingService, $softDelete = false)
    {
        parent::__construct($meilisearch, $softDelete);
        $this->embeddingService = $embeddingService;
    }

    /**
     * Generate embedding vector for a query string.
     *
     * Calls the EmbeddingService to convert text into a vector representation.
     * Returns null if embedding service is disabled or generation fails.
     *
     * @param  string  $query  The search query to convert to an embedding
     * @return array<float>|null Vector embedding array or null on failure
     */
    public function generateQueryEmbedding(string $query): ?array
    {
        if (! $this->embeddingService->isEnabled()) {
            return null;
        }

        try {
            return $this->embeddingService->generateEmbedding($query);
        } catch (\Exception $e) {
            Log::warning('MeilisearchVectorEngine: Failed to generate query embedding', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Perform semantic search across models using hybrid search with embeddings.
     *
     * Generates a query embedding and performs hybrid search combining semantic similarity
     * with optional text matching. Falls back to pure text search if embedding generation fails.
     *
     * @param  class-string  $modelClass  The model class to search (must use HasVectorSearch trait)
     * @param  string  $query  The search query string
     * @param  int  $limit  Maximum number of results to return (default: 10)
     * @param  float|null  $relevanceThreshold  Minimum relevance score (0.0-1.0), defaults to config value
     * @param  float|null  $semanticRatio  Weight of semantic vs text search (0.0=text only, 1.0=semantic only)
     * @param  array<string, mixed>  $filters  Additional filters to apply
     *                                         Format: ['field' => 'value'] or ['field' => ['operator', 'value']]
     *                                         Operators: '!=', '<>', '>', '<', '>=', '<=', 'like'
     * @return \Illuminate\Support\Collection<int, mixed> Collection of matching models sorted by relevance
     */
    public function semanticSearch(
        string $modelClass,
        string $query,
        int $limit = 10,
        ?float $relevanceThreshold = null,
        ?float $semanticRatio = null,
        array $filters = []
    ): Collection {
        $relevanceThreshold = $relevanceThreshold ?? config('knowledge.search.internal_knowledge_threshold', 0.7);
        $semanticRatio = $semanticRatio ?? config('knowledge.search.semantic_ratio.rag_pipeline', 0.8);

        // Generate query embedding
        $queryEmbedding = $this->generateQueryEmbedding($query);

        if (! $queryEmbedding) {
            Log::warning('MeilisearchVectorEngine: No query embedding generated, falling back to text search');

            // Fallback to regular Scout search
            return $modelClass::search($query)->take($limit)->get();
        }

        // Use Scout's hybrid search builder
        $searchBuilder = $modelClass::hybridSearch(
            query: $query,
            embedding: $queryEmbedding,
            semanticRatio: $semanticRatio,
            relevanceThreshold: $relevanceThreshold
        )->take($limit);

        // Apply any additional filters
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                // Handle special filter operators
                if (count($value) === 2 && in_array($value[0], ['!=', '<>', '>', '<', '>=', '<=', 'like'])) {
                    $operator = $value[0];
                    $filterValue = $value[1];

                    if ($operator === '!=' || $operator === '<>') {
                        // Scout doesn't support != directly, use whereNotIn or custom filter
                        if ($filterValue === null) {
                            // whereNotNull equivalent
                            $searchBuilder->where($field, '!=', null);
                        } else {
                            $searchBuilder->where($field, '!=', $filterValue);
                        }
                    } elseif ($operator === 'like') {
                        // For 'like' filters, use where with wildcard matching
                        $searchBuilder->where($field, $filterValue);
                    } else {
                        $searchBuilder->where($field, $operator, $filterValue);
                    }
                } else {
                    // Regular whereIn for array of values
                    $searchBuilder->whereIn($field, $value);
                }
            } else {
                $searchBuilder->where($field, $value);
            }
        }

        return $searchBuilder->get();
    }

    /**
     * Update the given models in the index with vector embeddings.
     *
     * Generates embeddings for models that support it (via getEmbeddingContent method),
     * with automatic chunking for large content exceeding token limits. Embeddings are
     * transient (not persisted) and regenerated on each indexing operation.
     *
     * Chunking Strategy:
     * - Estimates tokens using 1 token ≈ 3 characters (conservative)
     * - Chunks content if estimated tokens exceed 90% of model limit
     * - Uses configurable chunk size and overlap from config
     *
     * @param  \Illuminate\Support\Collection  $models  Collection of models to index
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        // Generate embeddings for models that support it
        $models->each(function ($model) {
            if ($this->embeddingService->isEnabled() && method_exists($model, 'getEmbeddingContent')) {

                // In the new architecture, always generate embedding since it's transient
                try {
                    $content = $model->getEmbeddingContent();

                    // Check if content is too large and needs chunking
                    // Use conservative estimate: 1 token ≈ 3 characters (accounting for worst case)
                    $estimatedTokens = strlen($content) / 3;
                    $maxTokens = $this->embeddingService->getMaxTokens();

                    if ($estimatedTokens > $maxTokens * 0.9) {
                        // Use chunked embeddings for large content (90% of max to be safe)
                        Log::debug('MeilisearchVectorEngine: Using chunked embeddings for large content', [
                            'model_class' => get_class($model),
                            'model_id' => $model->getScoutKey(),
                            'content_length' => strlen($content),
                            'estimated_tokens' => round($estimatedTokens),
                            'max_tokens' => $maxTokens,
                        ]);

                        $embedding = $this->embeddingService->generateChunkedEmbeddings(
                            $content,
                            config('knowledge.vector_store.chunk_size', 1000),
                            config('knowledge.vector_store.chunk_overlap', 200)
                        );
                    } else {
                        // Use regular embedding for normal-sized content
                        $embedding = $this->embeddingService->generateEmbedding($content);
                    }

                    if ($embedding && method_exists($model, 'setEmbedding')) {
                        $model->setEmbedding($embedding);

                        Log::debug('MeilisearchVectorEngine: Generated new embedding for model', [
                            'model_class' => get_class($model),
                            'model_id' => $model->getScoutKey(),
                            'embedding_dimensions' => count($embedding),
                            'content_length' => strlen($content),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('MeilisearchVectorEngine: Failed to generate embedding for model', [
                        'model_class' => get_class($model),
                        'model_id' => $model->getScoutKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        // Call parent update method
        parent::update($models);

        Log::info('MeilisearchVectorEngine: Models indexed with embeddings', [
            'model_class' => get_class($models->first()),
            'count' => $models->count(),
            'with_embeddings' => $models->filter(fn ($m) => method_exists($m, 'hasEmbedding') && $m->hasEmbedding())->count(),
        ]);

        // Update meilisearch_document_id for models that have this field
        $models->each(function ($model) {
            if (method_exists($model, 'update') && Schema::hasColumn($model->getTable(), 'meilisearch_document_id')) {
                $scoutKey = $model->getScoutKey();

                // Only update if the field is empty or different from current Scout key
                if ($model->meilisearch_document_id !== $scoutKey) {
                    try {
                        $model->update(['meilisearch_document_id' => $scoutKey]);

                        Log::debug('MeilisearchVectorEngine: Updated meilisearch_document_id', [
                            'model_class' => get_class($model),
                            'model_id' => $model->getKey(),
                            'scout_key' => $scoutKey,
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('MeilisearchVectorEngine: Failed to update meilisearch_document_id', [
                            'model_class' => get_class($model),
                            'model_id' => $model->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });
    }

    /**
     * Perform the given search on the engine.
     *
     * Routes to appropriate search method based on builder options:
     * - Vector search if 'vector' option is set
     * - Hybrid search if 'semanticRatio' option is set with a query
     * - Default text search otherwise (parent method)
     *
     * @param  \Laravel\Scout\Builder  $builder  Scout builder with search configuration
     * @return mixed Raw search results from Meilisearch
     */
    public function search(Builder $builder)
    {
        // Handle vector search if embedding provided
        if (isset($builder->options['vector'])) {
            return $this->performVectorSearch($builder);
        }

        // Handle hybrid search if we have a query and semantic ratio is set (indicating hybrid/semantic search)
        if ($builder->query && isset($builder->options['semanticRatio'])) {
            return $this->performHybridSearch($builder);
        }

        // Default to parent search
        return parent::search($builder);
    }

    /**
     * Map the search results to models.
     *
     * Converts Meilisearch search results into Eloquent model instances,
     * preserving search result ordering. Handles ID mapping to convert
     * Scout keys back to database IDs when document_id field is present.
     *
     * @param  \Laravel\Scout\Builder  $builder  Scout builder instance
     * @param  mixed  $results  Raw search results from Meilisearch
     * @param  \Illuminate\Database\Eloquent\Model  $model  Model instance for hydration
     * @return \Illuminate\Database\Eloquent\Collection<int, mixed> Ordered collection of models
     */
    public function map(Builder $builder, $results, $model)
    {
        // Fix ID mapping - convert scout keys back to database IDs
        if (is_array($results) && isset($results['hits'])) {
            foreach ($results['hits'] as &$hit) {
                // If we have document_id, use that as the ID for Scout mapping
                if (isset($hit['document_id'])) {
                    $hit['id'] = $hit['document_id'];
                }
            }
        }

        if (is_null($results) || count($results['hits']) === 0) {
            return $model->newCollection();
        }

        // Get the database IDs from results
        $databaseIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();
        $objectIdPositions = array_flip($databaseIds);

        // Get models by database IDs
        $models = $model->getScoutModelsByIds($builder, $databaseIds);

        // Filter models by matching database IDs (not Scout keys)
        $filteredModels = $models->filter(function ($model) use ($databaseIds) {
            return in_array($model->getKey(), $databaseIds);
        });

        // Sort by the original search result order using database IDs
        return $filteredModels->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getKey()];
        })->values();
    }

    protected function applyRelevanceFilter($searchResult, Builder $builder)
    {
        $hits = $searchResult->getHits();

        if (empty($hits)) {
            return $this->createFilteredResult($searchResult, []);
        }

        $filteredHits = $this->applyIntelligentFiltering($hits, $builder);

        return $this->createFilteredResult($searchResult, $filteredHits);
    }

    /**
     * Apply intelligent filtering to detect sharp relevance drops.
     *
     * Analyzes ranking scores to identify and remove low-quality results
     * using a sharp-drop detection algorithm. This prevents returning
     * marginally relevant results that would dilute search quality.
     *
     * Algorithm:
     * 1. Sort hits by relevance score (highest first)
     * 2. Detect sharp drops (>20% decrease) after high-relevance results (>0.8)
     * 3. Cut off at sharp drop or minimum relevance threshold
     * 4. Return only high-quality results
     *
     * @param  array<array>  $hits  Raw search result hits from Meilisearch
     * @param  \Laravel\Scout\Builder  $builder  Scout builder with filter options
     * @return array<array> Filtered hits with low-quality results removed
     */
    protected function applyIntelligentFiltering(array $hits, Builder $builder): array
    {
        if (count($hits) <= 1) {
            return $hits;
        }

        // Extract scores and sort hits by relevance (highest first)
        $scoredHits = [];
        foreach ($hits as $hit) {
            $score = $hit['_rankingScore'] ?? 0;
            $scoredHits[] = ['hit' => $hit, 'score' => $score];
        }

        // Sort by score descending
        usort($scoredHits, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Find cutoff point based on sharp drops after high-relevance results
        $cutoffIndex = $this->findSharpDropCutoff($scoredHits, $builder);

        // Extract hits up to cutoff point
        $filteredHits = [];
        for ($i = 0; $i < $cutoffIndex; $i++) {
            $filteredHits[] = $scoredHits[$i]['hit'];
        }

        Log::debug('MeilisearchVectorEngine: Applied intelligent relevance filtering', [
            'original_count' => count($hits),
            'filtered_count' => count($filteredHits),
            'cutoff_index' => $cutoffIndex,
            'scores' => array_slice(array_column($scoredHits, 'score'), 0, min(10, count($scoredHits))),
        ]);

        return $filteredHits;
    }

    /**
     * Find the cutoff point where relevance drops sharply after high-quality results.
     *
     * Algorithm:
     * 1. Reject all results if best result is below minimum threshold
     * 2. Identify high-relevance results (score >= 0.8)
     * 3. After high-relevance results, detect sharp drops (>20% decrease)
     * 4. Cut off at sharp drop or when score falls below minimum threshold
     * 5. If no sharp drop found, include all results above minimum threshold
     *
     * Thresholds:
     * - High relevance: 0.8 (80% match)
     * - Sharp drop: 0.2 (20% decrease from previous result)
     * - Minimum relevance: configurable via Builder options or config
     *
     * @param  array<int, array{hit: array<string, mixed>, score: float}>  $scoredHits  Results sorted by score descending
     * @param  \Laravel\Scout\Builder  $builder  Scout builder with options
     * @return int Index position where to cut off results (exclusive)
     */
    protected function findSharpDropCutoff(array $scoredHits, Builder $builder): int
    {
        $highRelevanceThreshold = 0.8;
        $sharpDropThreshold = 0.2; // 20% drop
        $minRelevanceThreshold = $builder->options['relevance_threshold'] ?? $this->getDefaultRelevanceThreshold();

        // If no results meet minimum threshold, return empty
        if (empty($scoredHits) || $scoredHits[0]['score'] < $minRelevanceThreshold) {
            return 0;
        }

        // Find first highly relevant result
        $highRelevanceStart = -1;
        for ($i = 0; $i < count($scoredHits); $i++) {
            if ($scoredHits[$i]['score'] >= $highRelevanceThreshold) {
                $highRelevanceStart = $i;
                break;
            }
        }

        // If no highly relevant results, use standard threshold filtering
        if ($highRelevanceStart === -1) {
            for ($i = 0; $i < count($scoredHits); $i++) {
                if ($scoredHits[$i]['score'] < $minRelevanceThreshold) {
                    return $i;
                }
            }

            return count($scoredHits);
        }

        // Look for sharp drop after highly relevant results
        for ($i = $highRelevanceStart; $i < count($scoredHits) - 1; $i++) {
            $currentScore = $scoredHits[$i]['score'];
            $nextScore = $scoredHits[$i + 1]['score'];

            // Calculate percentage drop
            $percentageDrop = ($currentScore - $nextScore) / $currentScore;

            // If we find a sharp drop (>20%), cut off here
            if ($percentageDrop > $sharpDropThreshold) {
                return $i + 1;
            }

            // If next result falls below minimum threshold, cut off there
            if ($nextScore < $minRelevanceThreshold) {
                return $i + 1;
            }
        }

        // If no sharp drop found, include all results above minimum threshold
        for ($i = 0; $i < count($scoredHits); $i++) {
            if ($scoredHits[$i]['score'] < $minRelevanceThreshold) {
                return $i;
            }
        }

        return count($scoredHits);
    }

    /**
     * Create filtered result array compatible with Scout
     */
    protected function createFilteredResult($searchResult, array $filteredHits): array
    {
        $resultArray = $searchResult->toArray();
        $resultArray['hits'] = $filteredHits;
        $resultArray['estimatedTotalHits'] = count($filteredHits);
        $resultArray['hitsCount'] = count($filteredHits);

        return $resultArray;
    }

    /**
     * Get the default relevance threshold based on search type.
     */
    protected function getDefaultRelevanceThreshold(): float
    {
        // Default threshold - can be made configurable
        // 0.5 means only results with 50% relevance or higher are included
        return config('knowledge.search.relevance_threshold', 0.5);
    }

    /**
     * Perform pure vector similarity search.
     *
     * Uses Meilisearch hybrid search API with semanticRatio=1.0 for pure vector search.
     * Configures search parameters including ranking scores, match positions, and highlighting.
     *
     * @param  \Laravel\Scout\Builder  $builder  Scout builder with vector in options
     * @return array Raw Meilisearch search results with filtered hits
     *
     * @throws \Exception If Meilisearch API call fails
     */
    protected function performVectorSearch(Builder $builder)
    {
        $index = $this->meilisearch->index($builder->index ?: $builder->model->searchableAs());

        $searchParams = [
            'vector' => $builder->options['vector'],
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => $builder->options['semanticRatio'] ?? 1.0, // Meilisearch v1.10+ parameter name
            ],
            'limit' => $builder->limit ?: 20,
            'filter' => $this->filters($builder),
            'showRankingScore' => true, // Enable ranking scores for relevance filtering
            'showMatchesPosition' => true, // Enable match positions for contextual excerpts
            'attributesToSearchOn' => config('knowledge.search.meilisearch.searchable_attributes', ['title', 'content', 'description']),
            'attributesToHighlight' => config('knowledge.search.meilisearch.attributes_to_highlight', ['title', 'content', 'description']),
            'highlightPreTag' => config('knowledge.search.highlight_pre_tag', '<mark>'),
            'highlightPostTag' => config('knowledge.search.highlight_post_tag', '</mark>'),
        ];

        // Add any additional options (excluding our custom ones)
        $customOptions = ['vector', 'semanticRatio', 'hybrid', 'relevance_threshold'];
        $additionalOptions = array_diff_key($builder->options, array_flip($customOptions));
        $searchParams = array_merge($searchParams, $additionalOptions);

        try {
            $searchResult = $index->search($builder->query ?: '', $searchParams);

            // Apply relevance filtering
            $filteredResult = $this->applyRelevanceFilter($searchResult, $builder);

            Log::debug('MeilisearchVectorEngine: Vector search completed', [
                'hits_count' => count($filteredResult['hits']),
                'original_count' => count($searchResult->getHits()),
                'query' => $builder->query,
            ]);

            if (count($filteredResult['hits']) === 0 && strlen($builder->query) > 3) {
                Log::warning('MeilisearchVectorEngine: Vector search returned no results', [
                    'query' => $builder->query,
                    'index' => $builder->index ?: $builder->model->searchableAs(),
                ]);
            }

            return $filteredResult;
        } catch (\Exception $e) {
            Log::error('MeilisearchVectorEngine: Vector search failed', [
                'error' => $e->getMessage(),
                'index' => $builder->index ?: $builder->model->searchableAs(),
                'query' => $builder->query,
                'search_params' => $searchParams,
            ]);
            throw $e;
        }
    }

    /**
     * Perform hybrid search combining vector and text search.
     *
     * Generates query embedding if not provided and performs Meilisearch hybrid search
     * with configurable semanticRatio. Falls back to pure text search if embedding
     * generation fails or vector is unavailable.
     *
     * @param  \Laravel\Scout\Builder  $builder  Scout builder with semanticRatio option
     * @return mixed Raw Meilisearch search results or parent search on fallback
     *
     * @throws \Exception If Meilisearch API call fails (except on fallback)
     */
    protected function performHybridSearch(Builder $builder)
    {
        $index = $this->meilisearch->index($builder->index ?: $builder->model->searchableAs());

        // Generate vector from query if needed
        $vector = $builder->options['vector'] ?? null;
        if (! $vector && $builder->query && $this->embeddingService->isEnabled()) {
            try {
                $vector = $this->embeddingService->generateEmbedding($builder->query);
            } catch (\Exception $e) {
                Log::warning('MeilisearchVectorEngine: Failed to generate embedding for hybrid search', [
                    'query' => $builder->query,
                    'error' => $e->getMessage(),
                ]);

                // Fall back to text-only search
                return parent::search($builder);
            }
        }

        if (! $vector) {
            // No vector available, fall back to text search
            return parent::search($builder);
        }

        $searchParams = [
            'vector' => $vector,
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => $builder->options['semanticRatio'] ?? 0.5, // Balanced hybrid
            ],
            'limit' => $builder->limit ?: 20,
            'filter' => $this->filters($builder),
            'showRankingScore' => true, // Enable ranking scores for relevance filtering
            'showMatchesPosition' => true, // Enable match positions for contextual excerpts
            'attributesToSearchOn' => config('knowledge.search.meilisearch.searchable_attributes', ['title', 'content', 'description']),
            'attributesToHighlight' => config('knowledge.search.meilisearch.attributes_to_highlight', ['title', 'content', 'description']),
            'highlightPreTag' => config('knowledge.search.highlight_pre_tag', '<mark>'),
            'highlightPostTag' => config('knowledge.search.highlight_post_tag', '</mark>'),
        ];

        // Add any additional options (excluding our custom ones)
        $customOptions = ['vector', 'semanticRatio', 'hybrid', 'relevance_threshold'];
        $additionalOptions = array_diff_key($builder->options, array_flip($customOptions));
        $searchParams = array_merge($searchParams, $additionalOptions);

        try {
            $searchResult = $index->search($builder->query, $searchParams);

            // Apply relevance filtering
            $filteredResult = $this->applyRelevanceFilter($searchResult, $builder);

            Log::debug('MeilisearchVectorEngine: Hybrid search completed', [
                'hits_count' => count($filteredResult['hits']),
                'original_count' => count($searchResult->getHits()),
                'query' => $builder->query,
            ]);

            if (count($filteredResult['hits']) === 0 && strlen($builder->query) > 3) {
                Log::warning('MeilisearchVectorEngine: Hybrid search returned no results', [
                    'query' => $builder->query,
                    'index' => $builder->index ?: $builder->model->searchableAs(),
                ]);
            }

            return $filteredResult;
        } catch (\Exception $e) {
            Log::error('MeilisearchVectorEngine: Hybrid search failed', [
                'error' => $e->getMessage(),
                'index' => $builder->index ?: $builder->model->searchableAs(),
                'query' => $builder->query,
                'search_params' => $searchParams,
            ]);

            // Fall back to text-only search
            return parent::search($builder);
        }
    }
}
