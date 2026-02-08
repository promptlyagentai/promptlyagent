<?php

namespace App\Scout\Traits;

use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;

/**
 * Provides vector search capabilities to models via transient embedding architecture.
 *
 * This trait enables models to participate in semantic search using embeddings
 * generated at index time. Embeddings are NOT persisted to the database but
 * stored temporarily during indexing operations.
 *
 * Embedding Lifecycle:
 * 1. Model is queued for indexing (via Scout)
 * 2. MeilisearchVectorEngine calls getEmbeddingContent() to get text
 * 3. Engine generates embedding and calls setEmbedding()
 * 4. Embedding stored in $tempEmbedding (transient, not saved)
 * 5. Scout sends searchable data + embedding to Meilisearch
 * 6. Embedding cleared after indexing
 *
 * Search Methods:
 * - vectorSearch(): Pure vector similarity (semanticRatio = 1.0)
 * - hybridSearch(): Balanced semantic + keyword search (configurable ratio)
 * - semanticSearch(): Semantic search with engine-generated embeddings
 * - enhancedTextSearch(): Pure keyword search with relevance filtering
 *
 * Usage:
 * ```php
 * class KnowledgeDocument extends Model {
 *     use HasVectorSearch;
 *
 *     public function getEmbeddingContent(): string {
 *         return $this->title . "\n\n" . $this->content;
 *     }
 * }
 *
 * $results = KnowledgeDocument::hybridSearch('machine learning', semanticRatio: 0.8)->get();
 * ```
 *
 * @see \App\Scout\Engines\MeilisearchVectorEngine
 * @see \App\Services\Knowledge\Embeddings\EmbeddingService
 */
trait HasVectorSearch
{
    use Searchable;

    /**
     * Temporary embedding storage (not persisted to database)
     */
    protected ?array $tempEmbedding = null;

    /**
     * Set embedding temporarily for indexing.
     *
     * Called by MeilisearchVectorEngine during indexing operations.
     * Embedding is NOT persisted and will be cleared after indexing.
     *
     * @param  array<float>  $embedding  Vector embedding array
     */
    public function setEmbedding(array $embedding): void
    {
        $this->tempEmbedding = $embedding;
    }

    /**
     * Get temporary embedding.
     *
     * @return array<float>|null Vector embedding or null if not set
     */
    public function getEmbedding(): ?array
    {
        return $this->tempEmbedding;
    }

    /**
     * Clear temporary embedding.
     */
    public function clearEmbedding(): void
    {
        $this->tempEmbedding = null;
    }

    /**
     * Check if embedding exists.
     *
     * @return bool True if temporary embedding is set
     */
    public function hasEmbedding(): bool
    {
        return $this->tempEmbedding !== null;
    }

    /**
     * Get content for embedding generation.
     *
     * Concatenates searchable fields (title, content, description) into
     * a single string for embedding generation. Models can override this
     * method to customize which fields are included in embeddings.
     *
     * Field Priority (all included if present):
     * 1. title (weighted higher in semantic search)
     * 2. content (main body text)
     * 3. description (summary/metadata)
     *
     * @return string Concatenated content separated by double newlines
     */
    public function getEmbeddingContent(): string
    {
        // Combine title and content for embedding
        $content = [];

        if (! empty($this->title)) {
            $content[] = $this->title;
        }

        if (! empty($this->content)) {
            $content[] = $this->content;
        }

        if (! empty($this->description)) {
            $content[] = $this->description;
        }

        return implode("\n\n", $content);
    }

    /**
     * Perform a pure vector similarity search.
     *
     * Creates a Scout builder configured for pure semantic search using
     * vector embeddings. Requires pre-generated embedding vector.
     *
     * @param  string  $query  Search query text (used for logging, not search)
     * @param  array<float>|null  $embedding  Pre-generated query embedding vector
     * @param  float  $relevanceThreshold  Minimum relevance score (0.0-1.0, default: 0.5)
     * @return \Laravel\Scout\Builder Configured Scout builder for chaining
     */
    public static function vectorSearch(string $query = '', ?array $embedding = null, float $relevanceThreshold = 0.5): Builder
    {
        $builder = new Builder(new static, $query);

        if ($embedding) {
            $builder->options['vector'] = $embedding;
            $builder->options['semanticRatio'] = 1.0; // Pure vector search
        }

        // Set relevance threshold and enable match positions for better previews
        $builder->options['relevance_threshold'] = $relevanceThreshold;
        $builder->options['showMatchesPosition'] = true;
        $builder->options['showRankingScore'] = true;

        return $builder;
    }

    /**
     * Perform a hybrid search combining text and vector similarity.
     *
     * Balances semantic (vector) and keyword (text) search using semanticRatio.
     * If embedding not provided, engine will generate it from query text.
     *
     * Semantic Ratio Examples:
     * - 1.0 = Pure vector search (100% semantic)
     * - 0.8 = Mostly semantic (80% semantic, 20% keyword)
     * - 0.5 = Balanced (50% semantic, 50% keyword)
     * - 0.2 = Mostly keyword (20% semantic, 80% keyword)
     * - 0.0 = Pure keyword search (0% semantic)
     *
     * @param  string  $query  Search query text
     * @param  array<float>|null  $embedding  Pre-generated query embedding (optional, engine generates if null)
     * @param  float  $semanticRatio  Weight of semantic vs text (0.0-1.0, default: 0.5)
     * @param  float  $relevanceThreshold  Minimum relevance score (0.0-1.0, default: 0.5)
     * @return \Laravel\Scout\Builder Configured Scout builder for chaining
     */
    public static function hybridSearch(string $query, ?array $embedding = null, float $semanticRatio = 0.5, float $relevanceThreshold = 0.5): Builder
    {
        $builder = new Builder(new static, $query);

        if ($embedding) {
            $builder->options['vector'] = $embedding;
        }
        // Engine will handle hybrid logic based on query presence

        $builder->options['semanticRatio'] = $semanticRatio;
        $builder->options['relevance_threshold'] = $relevanceThreshold;
        $builder->options['showMatchesPosition'] = true;
        $builder->options['showRankingScore'] = true;

        return $builder;
    }

    /**
     * Perform a semantic search by generating embedding from query.
     *
     * Similar to hybridSearch but engine always generates embedding from query text.
     * Use this when you don't have a pre-generated embedding vector.
     *
     * @param  string  $query  Search query text
     * @param  float  $semanticRatio  Weight of semantic vs text (0.0-1.0, default: 1.0 for pure semantic)
     * @param  float  $relevanceThreshold  Minimum relevance score (0.0-1.0, default: 0.5)
     * @return \Laravel\Scout\Builder Configured Scout builder for chaining
     */
    public static function semanticSearch(string $query, float $semanticRatio = 1.0, float $relevanceThreshold = 0.5): Builder
    {
        $builder = new Builder(new static, $query);
        // Engine will handle hybrid logic based on query presence
        $builder->options['semanticRatio'] = $semanticRatio;
        $builder->options['relevance_threshold'] = $relevanceThreshold;
        $builder->options['showMatchesPosition'] = true;
        $builder->options['showRankingScore'] = true;

        return $builder;
    }

    /**
     * Perform enhanced text search with match positions (no vectors)
     */
    public static function enhancedTextSearch(string $query, float $relevanceThreshold = 0.5): Builder
    {
        $builder = new Builder(new static, $query);
        $builder->options['relevance_threshold'] = $relevanceThreshold;
        $builder->options['showMatchesPosition'] = true;
        $builder->options['showRankingScore'] = true;
        // Don't set semanticRatio to force pure text search

        return $builder;
    }

    /**
     * Get similar documents based on this model's embedding.
     *
     * Finds documents with similar embeddings using vector similarity search.
     * Requires model to have an active embedding (set during indexing).
     * Automatically excludes current model from results.
     *
     * Returns empty collection if no embedding exists.
     *
     * @param  int  $limit  Maximum number of similar documents to return (default: 10)
     * @return \Illuminate\Database\Eloquent\Collection<int, static> Similar models or empty collection
     */
    public function getSimilarDocuments(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        $embedding = $this->getEmbedding();

        if (! $embedding) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return static::vectorSearch('', $embedding)
            ->where('id', '!=', $this->getScoutKey())
            ->take($limit)
            ->get();
    }
}
