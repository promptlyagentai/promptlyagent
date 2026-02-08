<?php

namespace App\Services\Research;

use App\Models\ChatInteractionSource;
use App\Models\KnowledgeDocument;
use App\Models\Source;
use App\Models\User;
use App\Scout\Engines\MeilisearchVectorEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;

/**
 * Semantic Search Service - Hybrid Multi-Source Search with Scoring.
 *
 * Orchestrates hybrid semantic search across multiple content sources: research
 * summaries, agent sources, and knowledge documents. Combines vector similarity
 * with keyword matching for robust search results.
 *
 * Search Strategy:
 * 1. **Summaries**: Search ChatInteraction summaries (historical research)
 * 2. **Sources**: Search AgentSource records (URLs/documents from past research)
 * 3. **Knowledge**: Search KnowledgeDocument index (user knowledge base)
 *
 * Hybrid Search:
 * - Vector similarity (embeddings) for semantic matching
 * - Keyword matching (Meilisearch) for exact term search
 * - Combined scoring for relevance ranking
 *
 * Scoring Algorithm:
 * - Base score from search engine
 * - Diversity bonus (unique domains prioritized)
 * - Recency decay (older results penalized)
 * - Final scores normalized 0-1
 *
 * Result Deduplication:
 * - Groups results by URL
 * - Prioritizes highest-scored duplicate
 * - Preserves domain diversity
 *
 * @see \App\Scout\Engines\MeilisearchVectorEngine
 * @see \App\Models\ChatInteractionSource
 * @see \App\Models\KnowledgeDocument
 */
class SemanticSearchService
{
    protected MeilisearchVectorEngine $vectorEngine;

    public function __construct(EngineManager $engineManager)
    {
        $this->vectorEngine = $engineManager->engine('meilisearch-vector');
    }

    /**
     * Perform comprehensive semantic search across sources and summaries
     */
    public function search(
        string $query,
        int $interactionId,
        int $limit = 10,
        ?float $relevanceThreshold = null,
        ?float $semanticRatio = null,
        ?User $user = null
    ): Collection {
        // Use optimized defaults from config
        $relevanceThreshold = $relevanceThreshold ?? config('knowledge.search.internal_knowledge_threshold', 0.7);
        $semanticRatio = $semanticRatio ?? config('knowledge.search.semantic_ratio.rag_pipeline', 0.8);
        try {
            // Generate query embedding using Scout vector engine
            $queryEmbedding = $this->vectorEngine->generateQueryEmbedding($query);

            $allResults = collect();

            // 1. Search interaction summaries (most relevant to current context)
            $summaryResults = $this->searchInteractionSummaries($query, $queryEmbedding, $interactionId, $limit, $relevanceThreshold, $semanticRatio);
            $allResults = $allResults->merge($summaryResults);

            // 2. Search web sources from past interactions
            $sourceResults = $this->searchWebSources($query, $queryEmbedding, $limit, $relevanceThreshold, $semanticRatio);
            $allResults = $allResults->merge($sourceResults);

            // 3. Search knowledge documents
            $knowledgeResults = $this->searchKnowledgeDocuments($query, $queryEmbedding, $limit, $relevanceThreshold, $semanticRatio, $user);
            $allResults = $allResults->merge($knowledgeResults);

            // 4. Apply hybrid reranking and deduplication
            $rerankedResults = $this->rerank($allResults, $query, $queryEmbedding, $limit);

            Log::info('SemanticSearchService: Search completed', [
                'query' => $query,
                'interaction_id' => $interactionId,
                'total_found' => $allResults->count(),
                'final_count' => $rerankedResults->count(),
                'semantic_ratio' => $semanticRatio,
            ]);

            return $rerankedResults;

        } catch (\Exception $e) {
            Log::error('SemanticSearchService: Search failed', [
                'query' => $query,
                'interaction_id' => $interactionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return collect();
        }
    }

    /**
     * Search interaction summaries for contextually relevant information
     */
    protected function searchInteractionSummaries(
        string $query,
        ?array $queryEmbedding,
        int $interactionId,
        int $limit,
        float $relevanceThreshold,
        float $semanticRatio
    ): Collection {
        try {
            // Use Scout vector engine for semantic search on interaction summaries
            $filters = [
                'chat_interaction_id' => ['!=', $interactionId],
                'content_summary' => ['!=', null],
            ];

            $summaries = $this->vectorEngine->semanticSearch(
                modelClass: ChatInteractionSource::class,
                query: $query,
                limit: $limit * 2,
                relevanceThreshold: $relevanceThreshold,
                semanticRatio: $semanticRatio,
                filters: $filters
            );

            // Load related source data
            $summaries->load('source');

            return $summaries->map(function ($summary) {
                $summaryText = $summary->content_summary ?? ($summary->source->description ?? $summary->source->title ?? '');

                return [
                    'type' => 'summary',
                    'id' => $summary->id,
                    'title' => $summary->source->title ?? 'Interaction Summary',
                    'description' => $summary->source->description ?? null,
                    'content_preview' => substr($summaryText, 0, 200),
                    'url' => $summary->source->url,
                    'score' => $summary->relevance_score ?? 0.8, // Use stored relevance score
                    'final_score' => $summary->relevance_score ?? 0.8,
                    'domain' => $summary->source->domain ?? null,
                    'source_type' => 'interaction_summary',
                    'discovery_method' => 'semantic_search',
                    'was_scraped' => $summary->was_scraped ?? false,
                    'created_at' => $summary->created_at,
                ];
            });

        } catch (\Exception $e) {
            Log::error('SemanticSearchService: Summary search failed', [
                'error' => $e->getMessage(),
                'interaction_id' => $interactionId,
            ]);

            return collect();
        }
    }

    /**
     * Search web sources from past research
     */
    protected function searchWebSources(
        string $query,
        ?array $queryEmbedding,
        int $limit,
        float $relevanceThreshold,
        float $semanticRatio
    ): Collection {
        try {
            // Use Scout vector engine for semantic search on web sources
            $filters = [
                'content_markdown' => ['!=', null],
                'url' => ['like', 'http%'],
            ];

            $sources = $this->vectorEngine->semanticSearch(
                modelClass: Source::class,
                query: $query,
                limit: $limit * 3,
                relevanceThreshold: $relevanceThreshold,
                semanticRatio: $semanticRatio,
                filters: $filters
            );

            return $sources->map(function ($source) {

                return [
                    'type' => 'source',
                    'id' => $source->id,
                    'title' => $source->title,
                    'description' => $source->description,
                    'url' => $source->url,
                    'score' => 0.8, // Scout already filtered by relevance
                    'final_score' => 0.8,
                    'domain' => $source->domain,
                    'content_preview' => substr($source->content_markdown ?? '', 0, 200),
                    'content_length' => strlen($source->content_markdown ?? ''),
                    'created_at' => $source->created_at,
                    'last_accessed_at' => $source->last_accessed_at,
                    'discovery_method' => 'semantic_search',
                    'was_scraped' => ! empty($source->content_markdown),
                ];
            });

        } catch (\Exception $e) {
            Log::error('SemanticSearchService: Web source search failed', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Search knowledge documents
     */
    protected function searchKnowledgeDocuments(
        string $query,
        ?array $queryEmbedding,
        int $limit,
        float $relevanceThreshold,
        float $semanticRatio,
        ?User $user = null
    ): Collection {
        try {
            // Use current user if not provided
            $user = $user ?? Auth::user();

            // Use Scout vector engine for semantic search
            $filters = [];

            // Apply privacy filtering
            if ($user) {
                // Get documents the user can access using the model's scope
                $accessibleIds = KnowledgeDocument::where('processing_status', 'completed')
                    ->forUser($user->id)
                    ->pluck('id')
                    ->toArray();

                if (! empty($accessibleIds)) {
                    $filters['document_id'] = $accessibleIds;
                }
            } else {
                // If no user, only show public documents
                $filters['privacy_level'] = 'public';
            }

            // Use vector engine for semantic search
            $documents = $this->vectorEngine->semanticSearch(
                modelClass: KnowledgeDocument::class,
                query: $query,
                limit: $limit,
                relevanceThreshold: $relevanceThreshold,
                semanticRatio: $semanticRatio,
                filters: $filters
            );

            return $documents->map(function ($document) {
                return [
                    'type' => 'knowledge',
                    'id' => $document->id,
                    'title' => $document->title,
                    'description' => $document->description,
                    'url' => null, // Knowledge documents don't have URLs
                    'content_preview' => substr($document->content, 0, 200),
                    'score' => 0.9, // Meilisearch already filtered by relevance threshold
                    'final_score' => 0.9, // High score since these passed Meilisearch filtering
                    'domain' => 'Knowledge Base', // Set a default domain for knowledge docs
                    'content_length' => strlen($document->content ?? ''),
                    'created_at' => $document->created_at,
                    'discovery_method' => 'semantic_search',
                    'was_scraped' => false, // Knowledge documents aren't scraped
                ];
            })->filter(function ($result) use ($relevanceThreshold) {
                return $result['score'] >= $relevanceThreshold;
            });

        } catch (\Exception $e) {
            Log::error('SemanticSearchService: Knowledge document search failed', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Rerank and deduplicate results using multiple signals
     */
    protected function rerank(Collection $allResults, string $query, ?array $queryEmbedding, int $limit): Collection
    {
        // Group by URL to handle duplicates
        $grouped = $allResults->groupBy(function ($result) {
            return $result['url'] ?? $result['type'].'_'.$result['id'];
        });

        // For each group, select the best result
        $deduplicated = $grouped->map(function ($group) {
            // Priority: summary > source > knowledge (summaries are most relevant to current interaction)
            $prioritized = $group->sortBy(function ($result) {
                $typePriority = match ($result['type']) {
                    'summary' => 1,
                    'source' => 2,
                    'knowledge' => 3,
                    default => 4
                };

                // Secondary sort by score (higher is better)
                return [$typePriority, -($result['score'] ?? 0)];
            });

            return $prioritized->first();
        });

        // Calculate final relevance scores with enhanced factors
        $scored = $deduplicated->map(function ($result) use ($query) {
            $result['final_score'] = $this->calculateFinalScore($result, $query);

            return $result;
        });

        // Apply diversity filter to prevent over-representation from single domains
        $diversified = $this->applyDiversityFilter($scored, $limit);

        // Sort by final score and take top results
        return $diversified->sortByDesc('final_score')->take($limit);
    }

    /**
     * Apply diversity filter to prevent over-representation from single domains
     */
    protected function applyDiversityFilter(Collection $results, int $limit): Collection
    {
        $domainCounts = [];
        $maxPerDomain = max(2, intval($limit / 4)); // Allow max 25% from single domain, minimum 2

        return $results->filter(function ($result) use (&$domainCounts, $maxPerDomain) {
            $domain = $result['domain'] ?? $result['type'].'_domain';

            if (! isset($domainCounts[$domain])) {
                $domainCounts[$domain] = 0;
            }

            if ($domainCounts[$domain] < $maxPerDomain) {
                $domainCounts[$domain]++;

                return true;
            }

            return false;
        });
    }

    /**
     * Calculate final relevance score combining multiple factors
     */
    protected function calculateFinalScore(array $result, string $query): float
    {
        $score = 0.0;

        // Base search score from Meilisearch (50% weight - primary relevance signal)
        $searchScore = $result['score'] ?? 0;
        $score += $searchScore * 0.5;

        // Type priority bonus (20% weight)
        $typeBonus = match ($result['type']) {
            'summary' => 1.0,   // Highest - most relevant to current interaction
            'source' => 0.8,    // Medium - general web sources
            'knowledge' => 0.6, // Lower - general knowledge base
            default => 0.5
        };
        $score += $typeBonus * 0.2;

        // Domain authority bonus (15% weight)
        $domainScore = $this->calculateDomainAuthorityScore($result);
        $score += $domainScore * 0.15;

        // Content freshness bonus (10% weight)
        $freshnessScore = $this->calculateFreshnessScore($result);
        $score += $freshnessScore * 0.1;

        // Content depth bonus (5% weight)
        $depthScore = $this->calculateContentDepthScore($result);
        $score += $depthScore * 0.05;

        return min(1.0, max(0.0, $score));
    }

    /**
     * Calculate domain authority score based on known authoritative domains
     */
    protected function calculateDomainAuthorityScore(array $result): float
    {
        if (empty($result['url'])) {
            return 0.5; // default score for non-web sources
        }

        $domain = parse_url($result['url'], PHP_URL_HOST);
        if (! $domain) {
            return 0.5;
        }

        // High authority domains
        $highAuthorityDomains = [
            'wikipedia.org', 'github.com', 'stackoverflow.com', 'arxiv.org',
            'nature.com', 'science.org', 'ieee.org', 'acm.org', 'pubmed.ncbi.nlm.nih.gov',
            'google.com', 'microsoft.com', 'apple.com', 'mozilla.org', 'w3.org',
            'ietf.org', 'rfc-editor.org', 'openai.com', 'anthropic.com',
        ];

        // Medium authority domains
        $mediumAuthorityDomains = [
            'medium.com', 'dev.to', 'hashnode.com', 'substack.com', 'blog.google.com',
            'docs.microsoft.com', 'developer.mozilla.org', 'aws.amazon.com',
            'cloud.google.com', 'azure.microsoft.com', 'redis.io', 'postgresql.org',
        ];

        // Government and educational domains get high scores
        if (preg_match('/\.(gov|edu|ac\.|org)$/', $domain)) {
            return 0.9;
        }

        foreach ($highAuthorityDomains as $authDomain) {
            if (str_contains($domain, $authDomain)) {
                return 0.9;
            }
        }

        foreach ($mediumAuthorityDomains as $authDomain) {
            if (str_contains($domain, $authDomain)) {
                return 0.7;
            }
        }

        // Default score for other domains
        return 0.5;
    }

    /**
     * Calculate content freshness score based on publication date
     */
    protected function calculateFreshnessScore(array $result): float
    {
        $dateField = $result['published_date'] ?? $result['created_at'] ?? $result['publishedDate'] ?? null;

        if (! $dateField) {
            return 0.5; // neutral score if no date available
        }

        try {
            $publishedDate = \Carbon\Carbon::parse($dateField);
            $now = \Carbon\Carbon::now();
            $daysDiff = $now->diffInDays($publishedDate);

            // Fresh content (within 30 days) gets higher score
            if ($daysDiff <= 30) {
                return 1.0;
            } elseif ($daysDiff <= 90) {
                return 0.8;
            } elseif ($daysDiff <= 365) {
                return 0.6;
            } elseif ($daysDiff <= 730) { // 2 years
                return 0.4;
            } else {
                return 0.2;
            }
        } catch (\Exception $e) {
            return 0.5; // neutral score if date parsing fails
        }
    }

    /**
     * Calculate content depth score based on content length and metadata
     */
    protected function calculateContentDepthScore(array $result): float
    {
        $contentLength = $result['content_length'] ?? 0;

        // If no content length available, try to estimate from description/content
        if ($contentLength === 0) {
            $content = $result['content_preview'] ?? $result['description'] ?? $result['content'] ?? '';
            $contentLength = strlen($content);
        }

        // Score based on content depth
        if ($contentLength >= 2000) {
            return 1.0; // Comprehensive content
        } elseif ($contentLength >= 1000) {
            return 0.8; // Good depth
        } elseif ($contentLength >= 500) {
            return 0.6; // Moderate depth
        } elseif ($contentLength >= 200) {
            return 0.4; // Basic content
        } else {
            return 0.2; // Minimal content
        }
    }
}
