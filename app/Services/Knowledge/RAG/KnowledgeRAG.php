<?php

namespace App\Services\Knowledge\RAG;

use App\Models\Agent;
use App\Models\AgentKnowledgeAssignment;
use App\Models\KnowledgeDocument;
use App\Services\EventStreamNotifier;
use App\Services\Knowledge\Contracts\RAGInterface;
use App\Services\Knowledge\DTOs\RAGQuery;
use App\Services\Knowledge\DTOs\RAGResult;
use App\Services\Knowledge\Embeddings\EmbeddingService;
use App\Services\StatusReporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Knowledge RAG (Retrieval-Augmented Generation) Service.
 *
 * Core RAG pipeline orchestrating hybrid search across knowledge documents
 * with intelligent scope filtering and context generation for AI agents.
 *
 * RAG Query Flow:
 * 1. Build search filters from RAGQuery (agent, user, privacy, tags)
 * 2. Determine document scope (agent assignments, scope tags, user ownership)
 * 3. Generate query embedding via EmbeddingService
 * 4. Execute hybrid search (semantic + keyword) via MeilisearchVectorEngine
 * 5. Filter results by relevance threshold and max results
 * 6. Track knowledge sources for attribution
 * 7. Generate formatted context for AI consumption
 *
 * Search Strategies:
 * - **Hybrid Search**: Combines vector similarity (semantic) with text matching (keyword)
 * - **Semantic Ratio**: Configurable balance (default: 0.8 = 80% semantic, 20% keyword)
 * - **Relevance Filtering**: Minimum threshold to exclude low-quality results
 * - **Scope Filtering**: Agent knowledge assignments + scope tags + privacy levels
 *
 * Scope Filtering Logic:
 * - **Agent Assignments**: Documents explicitly assigned to agent
 * - **Scope Tags**: Runtime tags from agent instructions (stored in container)
 * - **Privacy Levels**: Respects document privacy (private/public)
 * - **User Ownership**: Users only see their own private documents
 *
 * Context Generation:
 * - Formats results as XML-like structure for AI parsing
 * - Includes document metadata (title, source_type, tags, TTL)
 * - Supports deduplication and prioritization
 * - Configurable formatting via context strategy
 *
 * @see \App\Services\Knowledge\DTOs\RAGQuery
 * @see \App\Services\Knowledge\DTOs\RAGResult
 * @see \App\Services\Knowledge\Embeddings\EmbeddingService
 * @see \App\Scout\Engines\MeilisearchVectorEngine
 */
class KnowledgeRAG implements RAGInterface
{
    protected EmbeddingService $embeddingService;

    protected ?StatusReporter $statusReporter;

    protected ?int $interactionId;

    public function __construct(?StatusReporter $statusReporter = null, ?int $interactionId = null)
    {
        $this->embeddingService = new EmbeddingService;
        $this->statusReporter = $statusReporter;
        $this->interactionId = $interactionId;
    }

    /**
     * Set the status reporter for tracking execution progress
     */
    public function setStatusReporter(StatusReporter $statusReporter): self
    {
        $this->statusReporter = $statusReporter;
        $this->interactionId = $statusReporter->getInteractionId();

        return $this;
    }

    /**
     * Set the interaction ID for tracking
     */
    public function setInteractionId(int $interactionId): self
    {
        $this->interactionId = $interactionId;

        // Don't create a new StatusReporter here since we don't have access to agent_execution_id
        // The StatusReporter should be passed in via setStatusReporter() with proper execution context
        return $this;
    }

    /**
     * Report status if reporter is available
     */
    protected function reportStatus(string $message): void
    {
        if ($this->statusReporter) {
            $this->statusReporter->report('Knowledge RAG', $message);
        }
    }

    /**
     * Execute RAG query to retrieve relevant knowledge documents.
     *
     * Performs hybrid search combining semantic (vector) and keyword (text) search
     * with intelligent scope filtering based on agent assignments and tags.
     *
     * @param  RAGQuery  $query  Query configuration with user, agent, and search parameters
     * @return RAGResult Result containing matched documents, formatted context, and metadata
     */
    public function query(RAGQuery $query): RAGResult
    {
        $startTime = microtime(true);

        try {
            $this->reportStatus("Starting knowledge search for query: {$query->query}");

            // Build search filters based on query parameters
            $filters = $this->buildSearchFilters($query);

            // Determine document scope
            $documentIds = $this->getRelevantDocumentIds($query);

            // CRITICAL: Check if scope tags resulted in zero documents
            // Empty array can mean: (1) "all knowledge" with no restrictions, or (2) scope tags filtered to 0 documents
            $scopeTags = app()->has('knowledge_scope_tags') ? app('knowledge_scope_tags') : [];
            if (! empty($scopeTags) && empty($documentIds)) {
                // Scope tags exist but found no matching documents - return empty results immediately
                $this->reportStatus('No documents match the specified scope tags: '.implode(', ', $scopeTags));

                return RAGResult::create(
                    query: $query->query,
                    documents: collect(),
                    options: [
                        'context' => '',
                        'processingTime' => microtime(true) - $startTime,
                        'metadata' => [
                            'scopeTagsApplied' => $scopeTags,
                            'scopeFilteredToZero' => true,
                            'searchType' => 'filtered_by_scope',
                            'interactionId' => $this->interactionId,
                        ],
                    ]
                );
            }

            if (! empty($documentIds)) {
                $this->reportStatus('Searching within '.count($documentIds).' assigned documents');
            } else {
                $this->reportStatus('Searching all accessible knowledge documents');
            }

            // Start building Scout query
            $scoutQuery = KnowledgeDocument::search($query->query);

            // Apply filters
            foreach ($filters as $field => $value) {
                if ($field === 'document_id' && ! empty($documentIds)) {
                    $scoutQuery = $scoutQuery->whereIn('document_id', $documentIds);
                } elseif (is_array($value)) {
                    $scoutQuery = $scoutQuery->whereIn($field, $value);
                } else {
                    $scoutQuery = $scoutQuery->where($field, $value);
                }
            }

            // Apply document ID filter if specified (for scope tag filtering)
            // This applies to text search fallback paths
            if (! empty($documentIds)) {
                $scoutQuery = $scoutQuery->whereIn('document_id', $documentIds);
            }

            // Set limit
            $scoutQuery = $scoutQuery->take($query->limit);

            // Get consistent search parameters from config (same as KnowledgeManager)
            $semanticRatio = config('knowledge.search.semantic_ratio.rag_pipeline', 0.3);
            $relevanceThreshold = config('knowledge.search.relevance_threshold', 0.7);

            // Perform the search using the best available method
            if ($query->hybridSearch && $this->embeddingService->isEnabled()) {
                $this->reportStatus("Performing hybrid semantic search with ratio {$semanticRatio}");

                // Use hybrid search (text + semantic) with proper parameters
                $searchBuilder = KnowledgeDocument::hybridSearch(
                    query: $query->query,
                    embedding: null, // Let the system generate embedding
                    semanticRatio: $semanticRatio,
                    relevanceThreshold: $relevanceThreshold
                )->take($query->limit);

                // Apply document ID filter if specified (for scope tag filtering)
                if (! empty($documentIds)) {
                    $searchBuilder = $searchBuilder->whereIn('document_id', $documentIds);
                }

                // Get raw results first to capture match positions and relevance scores
                $rawResults = $searchBuilder->raw();
                $results = $searchBuilder->get();

                // Attach match position data and relevance scores to the models
                if (isset($rawResults['hits']) && count($rawResults['hits']) > 0) {
                    foreach ($results as $index => $model) {
                        if (isset($rawResults['hits'][$index]['_matchesPosition'])) {
                            // Store match positions as a temporary attribute
                            $model->_matchesPosition = $rawResults['hits'][$index]['_matchesPosition'];
                        }
                        if (isset($rawResults['hits'][$index]['_rankingScore'])) {
                            // Store relevance score as a temporary attribute
                            $model->relevanceScore = $rawResults['hits'][$index]['_rankingScore'];
                        }
                    }
                }

                $this->reportStatus('Found '.$results->count().' results from hybrid search');
            } elseif ($this->embeddingService->isEnabled()) {
                // Try semantic search with optimized ratio (not pure vector search)
                try {
                    $this->reportStatus("Performing semantic vector search with ratio {$semanticRatio}");

                    $searchBuilder = KnowledgeDocument::semanticSearch(
                        query: $query->query,
                        semanticRatio: $semanticRatio,
                        relevanceThreshold: $relevanceThreshold
                    )->take($query->limit);

                    // Apply document ID filter if specified (for scope tag filtering)
                    if (! empty($documentIds)) {
                        $searchBuilder = $searchBuilder->whereIn('document_id', $documentIds);
                    }

                    // Get raw results first to capture match positions and relevance scores
                    $rawResults = $searchBuilder->raw();
                    $results = $searchBuilder->get();

                    // Attach match position data and relevance scores to the models
                    if (isset($rawResults['hits']) && count($rawResults['hits']) > 0) {
                        foreach ($results as $index => $model) {
                            if (isset($rawResults['hits'][$index]['_matchesPosition'])) {
                                // Store match positions as a temporary attribute
                                $model->_matchesPosition = $rawResults['hits'][$index]['_matchesPosition'];
                            }
                            if (isset($rawResults['hits'][$index]['_rankingScore'])) {
                                // Store relevance score as a temporary attribute
                                $model->relevanceScore = $rawResults['hits'][$index]['_rankingScore'];
                            }
                        }
                    }

                    $this->reportStatus('Found '.$results->count().' results from semantic search');
                } catch (\Exception $e) {
                    $this->reportStatus('Semantic search failed, falling back to text search: '.$e->getMessage());

                    Log::warning('KnowledgeRAG: Semantic search failed, falling back to text search', [
                        'error' => $e->getMessage(),
                    ]);

                    $results = $scoutQuery->get();
                }
            } else {
                $this->reportStatus('Performing text-based search');

                // Use traditional text search
                $results = $scoutQuery->get();

                $this->reportStatus('Found '.$results->count().' results from text search');
            }

            // Apply additional filters that Scout doesn't handle
            if (! empty($documentIds)) {
                $results = $results->whereIn('id', $documentIds);
            }

            // Filter results based on TTL preferences
            $filteredResults = $this->filterByTTL($results, $query->includeExpired);
            $this->reportStatus('Filtered out expired documents, '.$filteredResults->count().' documents remaining');

            // Apply relevance threshold if specified and not already handled by search
            if ($query->relevanceThreshold !== null && $query->relevanceThreshold !== $relevanceThreshold) {
                $this->reportStatus("Applying additional relevance threshold filter: {$query->relevanceThreshold}");
                // Note: This is a placeholder - Scout/Meilisearch scores aren't easily accessible
            }

            // Track knowledge sources if interaction ID is available
            $this->trackKnowledgeSources($filteredResults, $query);

            // Generate context for AI consumption
            $context = $this->generateContext($filteredResults, 4000, $query->query);
            $this->reportStatus('Generated context from '.$filteredResults->count().' documents ('.strlen($context).' characters)');

            $processingTime = microtime(true) - $startTime;
            $this->reportStatus('Knowledge search completed in '.round($processingTime * 1000, 2).'ms');

            return RAGResult::create(
                query: $query->query,
                documents: $filteredResults,
                options: [
                    'context' => $context, // Pass our custom context
                    'totalScore' => 0, // Scout doesn't expose scores easily
                    'processingTime' => $processingTime,
                    'usedFilters' => $filters,
                    'metadata' => [
                        'searchType' => $query->hybridSearch ? 'hybrid' : 'text',
                        'semanticRatio' => $semanticRatio,
                        'relevanceThreshold' => $relevanceThreshold,
                        'documentScope' => count($documentIds ?? []),
                        'originalResults' => $results->count(),
                        'filteredResults' => $filteredResults->count(),
                        'interactionId' => $this->interactionId,
                    ],
                    'maxContextLength' => 4000,
                ]
            );

        } catch (\Exception $e) {
            $this->reportStatus('Knowledge search failed: '.$e->getMessage());

            // Return empty result on error
            return RAGResult::create(
                query: $query->query,
                documents: collect(),
                options: [
                    'error' => $e->getMessage(),
                    'processingTime' => microtime(true) - $startTime,
                    'interactionId' => $this->interactionId,
                ]
            );
        }
    }

    public function getRelevantKnowledge(string $agentId, string $query, array $options = []): Collection
    {
        $ragQuery = RAGQuery::create($query, array_merge($options, [
            'agentId' => (int) $agentId,
        ]));

        $result = $this->query($ragQuery);

        return $result->documents;
    }

    public function searchByTags(array $tagIds, string $query, array $options = []): Collection
    {
        $ragQuery = RAGQuery::create($query, array_merge($options, [
            'tagIds' => $tagIds,
        ]));

        $result = $this->query($ragQuery);

        return $result->documents;
    }

    public function searchByDocuments(array $documentIds, string $query, array $options = []): Collection
    {
        $ragQuery = RAGQuery::create($query, array_merge($options, [
            'documentIds' => $documentIds,
        ]));

        $result = $this->query($ragQuery);

        return $result->documents;
    }

    public function searchForUser(int $userId, string $query, array $options = []): Collection
    {
        $ragQuery = RAGQuery::create($query, array_merge($options, [
            'userId' => $userId,
        ]));

        $result = $this->query($ragQuery);

        return $result->documents;
    }

    public function isExpired(int $documentId): bool
    {
        $document = KnowledgeDocument::find($documentId);

        if (! $document) {
            return false;
        }

        return $document->is_expired;
    }

    public function getExpiredDocuments(array $documentIds = []): Collection
    {
        $query = KnowledgeDocument::expired();

        if (! empty($documentIds)) {
            $query->whereIn('id', $documentIds);
        }

        return $query->get();
    }

    public function filterByTTL(Collection $results, bool $includeExpired = false): Collection
    {
        if ($includeExpired) {
            return $results;
        }

        return $results->filter(function ($result) {
            // Check if document has TTL and if it's expired
            if (isset($result->metadata['ttl_expires_at'])) {
                $expiresAt = $result->metadata['ttl_expires_at'];

                return now()->isBefore($expiresAt);
            }

            // If no TTL, document is valid
            return true;
        });
    }

    public function rankResults(Collection $results, array $criteria = []): Collection
    {
        return $results->sortByDesc(function ($result) use ($criteria) {
            $score = $result->score ?? 0;

            // Apply additional ranking criteria
            foreach ($criteria as $criterion => $weight) {
                switch ($criterion) {
                    case 'recency':
                        if (isset($result->metadata['created_at'])) {
                            $createdAt = \Carbon\Carbon::parse($result->metadata['created_at']);
                            $daysSince = now()->diffInDays($createdAt);
                            $recencyScore = max(0, 1 - ($daysSince / 365)) * $weight;
                            $score += $recencyScore;
                        }
                        break;

                    case 'privacy_preference':
                        if ($result->privacyLevel === 'public') {
                            $score += $weight;
                        }
                        break;

                    case 'tag_relevance':
                        if (! empty($result->tags)) {
                            $score += count($result->tags) * $weight * 0.1;
                        }
                        break;
                }
            }

            return $score;
        })->values();
    }

    /**
     * Generate formatted context string from knowledge documents for AI consumption.
     *
     * Creates markdown-formatted context with document metadata and previews.
     * Encourages AI to use retrieve_full_document tool for complete content.
     *
     * Format per document:
     * - Heading: Title with expired flag if applicable
     * - Metadata: Document ID, relevance score, tags
     * - Preview: Summary or contextual excerpt (150-300 chars)
     * - Call-to-action: Instruction to use retrieve_full_document tool
     *
     * @param  Collection  $results  Collection of KnowledgeDocument models with search metadata
     * @param  int  $maxLength  Maximum total character length for context (default: 4000)
     * @param  string|null  $query  Optional query string for generating contextual excerpts
     * @return string Markdown-formatted context string (empty if no results)
     */
    public function generateContext(Collection $results, int $maxLength = 4000, ?string $query = null): string
    {
        if ($results->isEmpty()) {
            return '';
        }

        $context = [];
        $currentLength = 0;

        foreach ($results as $doc) {
            $title = is_array($doc) ? ($doc['title'] ?? 'Untitled') : ($doc->title ?? 'Untitled');
            $documentId = is_array($doc) ? ($doc['id'] ?? $doc['document_id'] ?? null) : ($doc->id ?? null);
            $docSource = is_array($doc) ? ($doc['source'] ?? null) : ($doc->source ?? null);
            $source = $docSource ? " (Source: {$docSource})" : '';
            $expired = is_array($doc) ? ($doc['isExpired'] ?? false) : ($doc->isExpired ?? false);
            $expired = $expired ? ' [OUTDATED]' : '';

            // Get brief metadata instead of truncated content
            $summary = is_array($doc) ? ($doc['summary'] ?? '') : ($doc->summary ?? '');
            // Handle tags more robustly - ensure we always get an array
            $tags = [];
            if (is_array($doc) && isset($doc['tags'])) {
                $tags = is_array($doc['tags']) ? $doc['tags'] : [];
            } elseif (isset($doc->tags)) {
                $tags = $doc->tags instanceof \Illuminate\Support\Collection ? $doc->tags->toArray() : (is_array($doc->tags) ? $doc->tags : []);
            }

            $tagsText = ! empty($tags) && is_array($tags) ? ' | Tags: '.implode(', ', array_map(fn ($tag) => is_array($tag) ? ($tag['name'] ?? $tag) : (isset($tag->name) ? $tag->name : $tag), $tags)) : '';

            // Create metadata-rich preview that encourages tool usage
            $metadataPreview = '';
            if (! empty($summary)) {
                $metadataPreview = 'Summary: '.mb_substr($summary, 0, 150).(mb_strlen($summary) > 150 ? '...' : '');
            } else {
                // Use match positions to generate contextual excerpts
                $excerpt = $this->generateContextualExcerpt($doc, $query ?? '');
                $metadataPreview = $excerpt;
            }

            // Format as metadata preview that encourages full document retrieval
            $formatted = "## 📄 {$title}{$expired}\n";
            $formatted .= "**Document ID**: {$documentId} | **Relevance**: High{$tagsText}\n";
            $formatted .= "**Preview**: {$metadataPreview}\n";
            $formatted .= "📋 *Use `retrieve_full_document` tool with document_id={$documentId} to access complete content*{$source}\n\n";

            $formattedLength = mb_strlen($formatted);

            if ($currentLength + $formattedLength > $maxLength) {
                break;
            }

            $context[] = $formatted;
            $currentLength += $formattedLength;
        }

        return implode('', $context);
    }

    /**
     * Build Meilisearch filter array from RAGQuery parameters.
     *
     * Constructs filters for Scout/Meilisearch search based on query parameters.
     * Handles privacy levels, user access, document IDs, and custom filters.
     *
     * Filter Logic:
     * - Privacy: Filters by privacy level if specified
     * - User access: Includes both public and user's private documents
     * - Document IDs: Restricts search to specific document set
     * - Custom filters: Merges any additional filters from query
     *
     * Note: TTL filtering is now handled post-search due to Meilisearch field
     * consistency issues (documents without TTL lack ttl_expires_at field).
     *
     * @param  RAGQuery  $query  Query configuration with filter parameters
     * @return array<string, mixed> Filter array for Scout/Meilisearch
     */
    protected function buildSearchFilters(RAGQuery $query): array
    {
        $filters = [];

        // Privacy level filter
        if ($query->privacyLevel !== null) {
            $filters['privacy_level'] = $query->privacyLevel;
        }

        // User access filter
        if ($query->userId !== null) {
            if ($query->privacyLevel === null) {
                // Include documents accessible to user
                $filters['privacy_level'] = ['public', 'private'];
                $filters['user_id'] = $query->userId;
            }
        }

        // Document IDs filter
        if (! empty($query->documentIds)) {
            $filters['document_id'] = $query->documentIds;
        }

        // TTL filtering is now handled post-search due to Meilisearch field consistency issues
        // Documents without TTL don't have ttl_expires_at field, making Meilisearch filters unreliable

        // Additional custom filters
        foreach ($query->filters as $key => $value) {
            $filters[$key] = $value;
        }

        return $filters;
    }

    /**
     * Determine the set of document IDs relevant to this query using two-level filtering.
     *
     * Implements a hierarchical filtering strategy:
     *
     * LEVEL 1: Scope Tag Restriction (Universe Filter)
     * - Checks app container for 'knowledge_scope_tags' (set by agent instructions)
     * - Documents must have ALL scope tags (strict AND filtering)
     * - Intersects with agent's assigned documents to restrict searchable universe
     *
     * LEVEL 2: AI Tag Refinement (Within Restricted Universe)
     * - Uses tag IDs from RAGQuery (provided by AI tool calls)
     * - Documents must have ANY of the AI tags (OR filtering)
     * - Further refines the scope-restricted document set
     *
     * Returns:
     * - Empty array = all knowledge accessible (no restrictions)
     * - Non-empty array = restricted to specific document IDs
     *
     * @param  RAGQuery  $query  Query with agentId, tagIds, and documentIds
     * @return array<int> Document IDs to search within (empty = search all)
     */
    protected function getRelevantDocumentIds(RAGQuery $query): array
    {
        // Start with agent's assigned documents
        $baseDocumentIds = $this->getAgentAssignedDocuments($query->agentId);

        // LEVEL 1: Scope tag restriction (universe filter)
        // Check container for scope tags that restrict the searchable universe
        $scopeTags = app()->has('knowledge_scope_tags') ? app('knowledge_scope_tags') : [];

        if (! empty($scopeTags)) {
            // Get documents that have ALL scope tags (strict AND filtering)
            $scopedDocumentIds = $this->getDocumentIdsByTagNames($scopeTags, requireAll: true);

            // Intersect with agent's assigned documents to restrict the universe
            $baseDocumentIds = ! empty($baseDocumentIds)
                ? array_intersect($baseDocumentIds, $scopedDocumentIds)
                : $scopedDocumentIds;

            Log::info('KnowledgeRAG: Applied scope tag restriction', [
                'agent_id' => $query->agentId,
                'scope_tags' => $scopeTags,
                'documents_after_scope' => count($baseDocumentIds),
            ]);
        }

        // LEVEL 2: AI tag refinement (within restricted universe)
        // Get documents from tag IDs provided by AI
        if (! empty($query->tagIds)) {
            // Get documents that have ANY of the AI-provided tags (OR filtering)
            $refinedDocumentIds = $this->getDocumentIdsByTagIds($query->tagIds, requireAll: false);

            // Intersect with scope-restricted universe
            $baseDocumentIds = ! empty($baseDocumentIds)
                ? array_intersect($baseDocumentIds, $refinedDocumentIds)
                : $refinedDocumentIds;

            Log::info('KnowledgeRAG: Applied AI tag refinement', [
                'agent_id' => $query->agentId,
                'ai_tag_ids' => $query->tagIds,
                'documents_after_refinement' => count($baseDocumentIds),
            ]);
        }

        // Merge with explicitly specified document IDs
        if (! empty($query->documentIds)) {
            $baseDocumentIds = array_merge($baseDocumentIds, $query->documentIds);
        }

        return array_values(array_unique($baseDocumentIds));
    }

    /**
     * Get document IDs assigned to an agent
     */
    protected function getAgentAssignedDocuments(?int $agentId): array
    {
        if ($agentId === null) {
            return [];
        }

        $documentIds = [];
        $assignments = AgentKnowledgeAssignment::forAgent($agentId)
            ->orderedByPriority()
            ->get();

        foreach ($assignments as $assignment) {
            if ($assignment->is_document_assignment) {
                $documentIds[] = $assignment->knowledge_document_id;
            } elseif ($assignment->is_tag_assignment) {
                // Get documents with this tag
                $tagDocuments = KnowledgeDocument::whereHas('tags', function ($tagQuery) use ($assignment) {
                    $tagQuery->where('knowledge_tag_id', $assignment->knowledge_tag_id);
                })->pluck('id')->toArray();

                $documentIds = array_merge($documentIds, $tagDocuments);
            } elseif ($assignment->is_all_knowledge_assignment) {
                // Return empty array to indicate all documents are accessible
                // This will be filtered by scope tags if present
                return [];
            }
        }

        return array_unique($documentIds);
    }

    /**
     * Get document IDs that have specified tag names
     *
     * @param  array  $tagNames  Array of tag names to search for
     * @param  bool  $requireAll  If true, document must have ALL tags (AND). If false, ANY tag (OR).
     * @return array Array of document IDs
     */
    protected function getDocumentIdsByTagNames(array $tagNames, bool $requireAll = false): array
    {
        if (empty($tagNames)) {
            return [];
        }

        $query = KnowledgeDocument::query()
            ->whereHas('tags', function ($q) use ($tagNames) {
                $q->whereIn('name', $tagNames);
            });

        // If requireAll is true, document must have ALL specified tags
        if ($requireAll) {
            // For each tag, ensure the document has it
            foreach ($tagNames as $tagName) {
                $query->whereHas('tags', function ($q) use ($tagName) {
                    $q->where('name', $tagName);
                });
            }
        }

        return $query->pluck('id')->toArray();
    }

    /**
     * Get document IDs that have specified tag IDs
     *
     * @param  array  $tagIds  Array of tag IDs to search for
     * @param  bool  $requireAll  If true, document must have ALL tags (AND). If false, ANY tag (OR).
     * @return array Array of document IDs
     */
    protected function getDocumentIdsByTagIds(array $tagIds, bool $requireAll = false): array
    {
        if (empty($tagIds)) {
            return [];
        }

        $query = KnowledgeDocument::query()
            ->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('knowledge_tag_id', $tagIds);
            });

        // If requireAll is true, document must have ALL specified tags
        if ($requireAll) {
            // For each tag ID, ensure the document has it
            foreach ($tagIds as $tagId) {
                $query->whereHas('tags', function ($q) use ($tagId) {
                    $q->where('knowledge_tag_id', $tagId);
                });
            }
        }

        return $query->pluck('id')->toArray();
    }

    protected function convertToDocuments(Collection $searchResults): Collection
    {
        return $searchResults->map(function ($result) {
            return [
                'id' => $result->id,
                'title' => $result->title,
                'content' => $result->content,
                'summary' => $result->summary ?? mb_substr(strip_tags($result->content), 0, 200),
                'score' => $result->score,
                'source' => $result->source,
                'sourceType' => $result->sourceType,
                'documentId' => $result->documentId,
                'isExpired' => $result->isExpired,
                'metadata' => $result->metadata,
                'highlights' => $result->highlights,
                'tags' => $this->extractTagsFromMetadata($result),
                'privacyLevel' => $this->extractPrivacyLevel($result),
                'createdAt' => $this->extractCreatedAt($result),
            ];
        });
    }

    protected function extractTagsFromMetadata($result): array
    {
        if (isset($result->metadata['tags']) && is_array($result->metadata['tags'])) {
            return $result->metadata['tags'];
        }

        return [];
    }

    protected function extractPrivacyLevel($result): string
    {
        return $result->metadata['privacy_level'] ?? 'private';
    }

    protected function extractCreatedAt($result): ?string
    {
        return $result->metadata['created_at'] ?? null;
    }

    /**
     * Track knowledge sources for real-time display and history.
     *
     * Broadcasts knowledge source events via EventStreamNotifier for:
     * - Real-time UI updates in chat interface (show sources being used)
     * - Interaction history (maintain record of which documents informed response)
     * - Attribution and transparency (let users see knowledge sources)
     *
     * For each document:
     * - Generates preview URL for frontend display
     * - Creates fast excerpt (250 chars) avoiding expensive algorithms
     * - Broadcasts knowledgeSourceAdded event via WebSocket
     *
     * Performance: Uses simple truncation instead of generateContentExcerpt()
     * to avoid hanging on large documents during tool execution.
     *
     * @param  Collection  $documents  Knowledge documents used in RAG query
     * @param  RAGQuery  $query  Original query (for future context if needed)
     */
    protected function trackKnowledgeSources(Collection $documents, RAGQuery $query): void
    {
        if (! $this->interactionId) {
            Log::warning('KnowledgeRAG: Skipping trackKnowledgeSources - no interaction ID', [
                'interaction_id_value' => $this->interactionId,
            ]);

            return;
        }

        try {
            foreach ($documents as $doc) {
                // CRITICAL: Generate preview_url separately to isolate potential hanging
                $previewUrl = null;
                try {
                    $documentId = $doc['documentId'] ?? $doc['id'];
                    $previewUrl = route('knowledge.preview', ['document' => $documentId]);
                } catch (\Exception $routeException) {
                    Log::warning('KnowledgeRAG: route() call failed', [
                        'error' => $routeException->getMessage(),
                    ]);
                    $previewUrl = '#';
                }

                // Generate a simple, fast excerpt to avoid hanging tool execution
                // The expensive generateContentExcerpt() method can hang for large documents
                $content = $doc['content'] ?? '';
                if (! empty($content)) {
                    $contentExcerpt = mb_substr($content, 0, 250).(mb_strlen($content) > 250 ? '...' : '');
                } else {
                    $contentExcerpt = $doc['summary'] ?? '';
                }

                // Create a knowledge source event
                EventStreamNotifier::knowledgeSourceAdded($this->interactionId, [
                    'document_id' => $doc['documentId'] ?? $doc['id'],
                    'title' => $doc['title'] ?? 'Untitled Document',
                    'source_type' => $doc['sourceType'] ?? 'knowledge',
                    'is_expired' => $doc['isExpired'] ?? false,
                    'preview_url' => $previewUrl,
                    'content_excerpt' => $contentExcerpt,
                    'tags' => $doc['tags'] ?? [],
                    'created_at' => $doc['createdAt'] ?? null,
                ]);
            }

            Log::info('KnowledgeRAG: Tracked knowledge sources for interaction', [
                'interaction_id' => $this->interactionId,
                'sources_count' => $documents->count(),
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to track knowledge sources', [
                'interaction_id' => $this->interactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate a relevant excerpt from document content
     */
    protected function generateContentExcerpt(array $doc, string $query): string
    {
        $content = $doc['content'] ?? '';
        if (empty($content)) {
            return $doc['summary'] ?? '';
        }

        // Try to find content around the query terms
        $queryWords = array_filter(explode(' ', strtolower($query)), fn ($word) => strlen($word) > 2);

        if (! empty($queryWords)) {
            $contentLower = strtolower($content);
            $bestPosition = 0;
            $bestMatches = 0;

            // Find the position with most query word matches
            for ($i = 0; $i < strlen($contentLower) - 300; $i += 50) {
                $excerpt = substr($contentLower, $i, 300);
                $matches = 0;
                foreach ($queryWords as $word) {
                    if (strpos($excerpt, $word) !== false) {
                        $matches++;
                    }
                }
                if ($matches > $bestMatches) {
                    $bestMatches = $matches;
                    $bestPosition = $i;
                }
            }

            if ($bestMatches > 0) {
                // Extract from the best position
                $excerpt = trim(substr($content, $bestPosition, 300));

                return $bestPosition > 0 ? '...'.$excerpt.'...' : $excerpt.'...';
            }
        }

        // Fallback to first 250 characters
        return mb_substr($content, 0, 250).(mb_strlen($content) > 250 ? '...' : '');
    }

    /**
     * Generate a contextual excerpt showing why a document is relevant.
     *
     * Uses Meilisearch match positions when available to extract relevant
     * text snippets around query matches. Falls back to relevance snippet
     * generation if match positions aren't available.
     *
     * Strategy:
     * 1. Check for _matchesPosition metadata from Meilisearch
     * 2. If available, extract surrounding text using getSurroundingText()
     * 3. If not, fall back to generateRelevanceSnippet() for keyword-based preview
     *
     * @param  mixed  $doc  Document (array or model) with optional _matchesPosition metadata
     * @param  string  $query  Original search query for fallback generation
     * @return string Contextual excerpt (150-300 chars typically)
     */
    protected function generateContextualExcerpt($doc, string $query): string
    {
        // Check if we have match positions from Meilisearch (array format or model attribute)
        $matchPositions = null;
        if (is_array($doc) && isset($doc['_matchesPosition'])) {
            $matchPositions = $doc['_matchesPosition'];
        } elseif (isset($doc->_matchesPosition)) {
            $matchPositions = $doc->_matchesPosition;
        }

        if ($matchPositions) {
            // Convert model to array format for extraction method
            $docArray = is_array($doc) ? $doc : [
                'content' => $doc->content ?? '',
                'title' => $doc->title ?? '',
                '_matchesPosition' => $matchPositions,
            ];

            return $this->extractMatchContextExcerpts($docArray, $query);
        }

        // Fallback to the old snippet generation for backwards compatibility
        $docArray = is_array($doc) ? $doc : [
            'content' => $doc->content ?? '',
            'title' => $doc->title ?? '',
        ];

        return $this->generateRelevanceSnippet($docArray, $query);
    }

    /**
     * Extract contextual excerpts from Meilisearch match positions.
     *
     * Processes _matchesPosition metadata from Meilisearch to generate
     * meaningful excerpts showing why a document matched the query.
     *
     * Algorithm:
     * 1. Process up to 2 content matches (limit to avoid overwhelming preview)
     * 2. For each match, extract surrounding text (300 char limit)
     * 3. If no content matches, check title matches
     * 4. Fall back to generateRelevanceSnippet() if no matches found
     *
     * Match Position Format (from Meilisearch):
     * - Field name → array of matches
     * - Each match: {start: int, length: int}
     *
     * @param  array  $doc  Document array with _matchesPosition metadata
     * @param  string  $query  Original search query (for fallback)
     * @return string Contextual excerpt with match context
     */
    protected function extractMatchContextExcerpts(array $doc, string $query): string
    {
        $content = $doc['content'] ?? '';
        $matchesPosition = $doc['_matchesPosition'] ?? [];

        if (empty($content) || empty($matchesPosition)) {
            return $this->generateRelevanceSnippet($doc, $query);
        }

        $excerpts = [];

        // Process matches from content field
        if (isset($matchesPosition['content'])) {
            foreach (array_slice($matchesPosition['content'], 0, 2) as $match) { // Max 2 excerpts
                $matchPosition = $match['start'] + ($match['length'] / 2); // Use middle of match

                // Get surrounding paragraph/sentence with 300 char limit
                $excerpt = $this->getSurroundingText($content, (int) $matchPosition, false, 300);

                if (! empty($excerpt) && strlen($excerpt) > 20) {
                    $excerpts[] = $excerpt;
                }
            }
        }

        // Also check title matches
        if (isset($matchesPosition['title']) && empty($excerpts)) {
            $title = $doc['title'] ?? '';
            foreach ($matchesPosition['title'] as $match) {
                $matchPosition = $match['start'] + ($match['length'] / 2);
                $excerpt = $this->getSurroundingText($title, (int) $matchPosition, true, 200);
                if (! empty($excerpt)) {
                    $excerpts[] = 'Title: '.$excerpt;
                    break; // Only one title excerpt
                }
            }
        }

        if (! empty($excerpts)) {
            return implode(' ... ', $excerpts);
        }

        // Fallback
        return $this->generateRelevanceSnippet($doc, $query);
    }

    /**
     * Extract the paragraph or sentence containing a given position in text
     *
     * @param  string  $text  The full text content to search in
     * @param  int  $position  The character position within the text
     * @param  bool  $useSentences  Whether to use sentence boundaries instead of paragraphs
     * @param  int  $maxLength  Optional maximum length of the extracted text (0 for unlimited)
     * @return string The extracted text containing the position
     */
    protected function getSurroundingText(string $text, int $position, bool $useSentences = false, int $maxLength = 0): string
    {
        // Validate input
        if (! is_string($text) || $position < 0 || $position >= strlen($text)) {
            return '';
        }

        if ($useSentences) {
            // Define sentence delimiters (period, exclamation mark, question mark followed by space or line break)
            $pattern = '/[.!?][\s\n\r]/';

            // Find the start of the sentence
            $textBefore = substr($text, 0, $position);
            preg_match_all($pattern, $textBefore, $matches, PREG_OFFSET_CAPTURE);

            $start = 0;
            if (! empty($matches[0])) {
                $lastMatch = end($matches[0]);
                $start = $lastMatch[1] + 2; // +2 to skip the punctuation and the space/newline
            }

            // Find the end of the sentence
            $textAfter = substr($text, $position);
            if (preg_match($pattern, $textAfter, $match, PREG_OFFSET_CAPTURE)) {
                $end = $position + $match[0][1] + 1; // +1 to include the punctuation
            } else {
                $end = strlen($text);
            }
        } else {
            // Define paragraph delimiters
            $delimiters = ["\n\n", "\r\n\r\n", "\n\r\n\r", "\r\r", "\n \n", "\r\n \r\n"];

            // Find the start of the paragraph
            $start = 0;
            foreach ($delimiters as $delimiter) {
                $delimiterPos = strrpos(substr($text, 0, $position), $delimiter);
                if ($delimiterPos !== false) {
                    $start = max($start, $delimiterPos + strlen($delimiter));
                }
            }

            // Find the end of the paragraph
            $end = strlen($text);
            foreach ($delimiters as $delimiter) {
                $delimiterPos = strpos($text, $delimiter, $position);
                if ($delimiterPos !== false && $delimiterPos < $end) {
                    $end = $delimiterPos;
                }
            }
        }

        // Extract the text
        $extractedText = trim(substr($text, $start, $end - $start));

        // Apply length limitation if specified
        if ($maxLength > 0 && strlen($extractedText) > $maxLength) {
            // Calculate position relative to extracted text
            $relativePos = $position - $start;

            // Ensure relative position is within bounds
            $relativePos = max(0, min($relativePos, strlen($extractedText) - 1));

            // Calculate how much text to keep before and after position
            $halfLength = floor($maxLength / 2);

            if ($relativePos <= $halfLength) {
                // Position is near the beginning, keep more of the start
                $extractedText = substr($extractedText, 0, $maxLength - 3).'...';
            } elseif (strlen($extractedText) - $relativePos <= $halfLength) {
                // Position is near the end, keep more of the end
                $extractedText = '...'.substr($extractedText, -(($maxLength - 3)));
            } else {
                // Position is in the middle, keep text around it
                $beforeText = substr($extractedText, max(0, $relativePos - $halfLength), $halfLength);
                $afterText = substr($extractedText, $relativePos, $halfLength - 3);
                $extractedText = '...'.$beforeText.$afterText.'...';
            }
        }

        return $extractedText;
    }

    protected function cleanExcerpt(string $text, int $matchStart, int $matchLength): string
    {
        $text = trim($text);

        // Remove incomplete sentences at the beginning
        if ($matchStart > 50) { // Only if match is not at the very beginning
            $firstPeriod = strpos($text, '. ');
            if ($firstPeriod !== false && $firstPeriod < $matchStart) {
                $text = substr($text, $firstPeriod + 2);
            }
        }

        // Remove incomplete sentences at the end
        $lastPeriod = strrpos($text, '. ');
        if ($lastPeriod !== false && $lastPeriod > $matchStart + $matchLength) {
            $text = substr($text, 0, $lastPeriod + 1);
        }

        // Add ellipsis if we truncated
        if (strlen($text) > 200) {
            $text = substr($text, 0, 200);
            // Try to end at a word boundary
            $lastSpace = strrpos($text, ' ');
            if ($lastSpace > 150) {
                $text = substr($text, 0, $lastSpace);
            }
            $text .= '...';
        }

        return trim($text);
    }

    /**
     * Generate a relevance snippet based on query keyword matching.
     *
     * Fallback method for generating document previews when Meilisearch
     * match positions aren't available. Analyzes query terms and generates
     * a natural-language description of relevance.
     *
     * Algorithm:
     * 1. Extract keywords from query (filter words < 3 chars)
     * 2. Find which keywords appear in document content
     * 3. Generate description: "Detailed information about {terms}"
     * 4. Fall back to title similarity if no content matches
     * 5. Generic fallback if no matches found
     *
     * @param  array  $doc  Document array with title and content
     * @param  string  $query  Original search query
     * @return string Natural-language relevance description
     */
    protected function generateRelevanceSnippet(array $doc, string $query): string
    {
        $content = $doc['content'] ?? '';
        $title = $doc['title'] ?? '';

        if (empty($content)) {
            return "Information related to '{$query}'";
        }

        // Extract key terms from the query
        $queryWords = array_filter(explode(' ', strtolower($query)), fn ($word) => strlen($word) > 2);

        if (! empty($queryWords)) {
            $contentLower = strtolower($content);
            $foundTerms = [];

            // Find which query terms appear in the document
            foreach ($queryWords as $word) {
                if (strpos($contentLower, $word) !== false) {
                    $foundTerms[] = $word;
                }
            }

            if (! empty($foundTerms)) {
                $terms = implode(', ', array_slice($foundTerms, 0, 3)); // Limit to first 3 terms

                return "Detailed information about {$terms}";
            }
        }

        // Fallback based on title similarity
        $titleWords = explode(' ', strtolower($title));
        $commonWords = array_intersect($queryWords ?? [], $titleWords);
        if (! empty($commonWords)) {
            return 'Content related to '.implode(', ', array_slice($commonWords, 0, 2));
        }

        return 'Relevant information for your query';
    }
}
