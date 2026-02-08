<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\DTOs\RAGQuery;
use App\Services\Knowledge\RAG\KnowledgeRAG;
use App\Services\StatusReporter;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;

/**
 * Knowledge Tool Service
 *
 * Unified service layer for knowledge search operations used by both MCP tools and Prism tools.
 * Eliminates code duplication and provides consistent knowledge retrieval interface.
 *
 * Architecture:
 * - RAG Integration: Wraps KnowledgeRAG for semantic + hybrid search
 * - Status Reporting: Tracks execution progress for agent interactions
 * - Document Conversion: Transforms results to Prism Document objects for AI injection
 * - Retrieval Guidance: Generates intelligent search strategy recommendations for agents
 *
 * Search Types:
 * - semantic: Pure vector similarity search using embeddings
 * - full_text: Keyword-based Meilisearch (fallback if embeddings disabled)
 * - hybrid: Combined semantic + keyword search with score fusion (default)
 *
 * Key Features:
 * - Provider Validation: Ensures documents compatible with AI provider (OpenAI, Anthropic)
 * - Context Generation: Creates both text context and Prism Document objects
 * - Relevance Filtering: Configurable threshold (default 0.1)
 * - Expiration Handling: Optionally includes/excludes expired documents
 * - Tag Filtering: Supports filtering by tag names or IDs
 * - Agent Scoping: Restricts results to agent-linked documents
 *
 * Retrieval Guidance System:
 * - Document Count Analysis: Adapts strategy (retrieve_all vs selective_retrieval)
 * - Search Intent Detection: Categorizes queries (information_seeking, troubleshooting, etc.)
 * - Relevance Scoring: High/medium/low score distribution analysis
 * - Citation Requirements: Enforces source attribution in AI responses
 *
 * Usage Patterns:
 * - MCP Knowledge Tools: Direct search/retrieval without agent context
 * - Prism Agent Tools: Search with agent ID scoping + status reporting
 * - Context Generation: Combines search + Prism Document creation for RAG
 *
 * @see \App\Services\Knowledge\RAG\KnowledgeRAG
 * @see \App\Services\Knowledge\DocumentInjectionService
 * @see \App\Services\Agents\Tools\KnowledgeSearchTool (Prism)
 * @see \App\MCP\Resources\KnowledgeSearchResource (MCP)
 */
class KnowledgeToolService
{
    protected KnowledgeRAG $rag;

    protected ?StatusReporter $statusReporter;

    protected ?int $interactionId;

    public function __construct(?StatusReporter $statusReporter = null, ?int $interactionId = null)
    {
        $this->rag = app(KnowledgeRAG::class);
        $this->statusReporter = $statusReporter;
        $this->interactionId = $interactionId;

        // Configure RAG service with status reporting
        if ($statusReporter) {
            $this->rag->setStatusReporter($statusReporter);
        } elseif ($interactionId) {
            $this->rag->setInteractionId($interactionId);
        }
    }

    /**
     * Create instance with status reporting context
     */
    public static function withStatusReporting(?StatusReporter $statusReporter = null, ?int $interactionId = null): self
    {
        return new self($statusReporter, $interactionId);
    }

    /**
     * Set the status reporter for execution tracking
     */
    public function setStatusReporter(StatusReporter $statusReporter): self
    {
        $this->statusReporter = $statusReporter;
        $this->interactionId = $statusReporter->getInteractionId();
        $this->rag->setStatusReporter($statusReporter);

        return $this;
    }

    /**
     * Set the interaction ID for tracking
     */
    public function setInteractionId(int $interactionId): self
    {
        $this->interactionId = $interactionId;
        $this->rag->setInteractionId($interactionId);

        return $this;
    }

    /**
     * Perform knowledge search using standardized parameters
     */
    public function search(array $params): array
    {
        $query = $params['query'] ?? '';
        $searchType = $params['search_type'] ?? 'hybrid';
        $limit = $params['limit'] ?? 10;
        $agentId = $params['agent_id'] ?? null;
        $includeContent = $params['include_content'] ?? true;
        $relevanceThreshold = $params['relevance_threshold'] ?? 0.1;
        $includeExpired = $params['include_expired'] ?? false;
        $documentIds = $params['document_ids'] ?? [];
        $tagIds = $params['tag_ids'] ?? [];

        // Map search types to RAG configuration
        $hybridSearch = match ($searchType) {
            'semantic' => false, // Pure semantic search
            'full_text' => false, // Will fallback to text search in RAG
            'hybrid' => true,
            default => true
        };

        // Create RAG query
        $ragQuery = RAGQuery::create(
            query: $query,
            options: [
                'agentId' => $agentId,
                'limit' => $limit,
                'hybridSearch' => $hybridSearch,
                'relevanceThreshold' => $relevanceThreshold,
                'includeExpired' => $includeExpired,
                'documentIds' => $documentIds,
                'tagIds' => $tagIds,
            ]
        );

        // Execute search with status reporting
        Log::info('KnowledgeToolService: Executing knowledge search', [
            'query' => $query,
            'search_type' => $searchType,
            'limit' => $limit,
            'agent_id' => $agentId,
        ]);

        $result = $this->rag->query($ragQuery);

        // Format documents for consistent response

        $documents = $result->documents->map(function ($doc) use ($includeContent) {
            // Extract relevance score from model attribute if available
            $relevanceScore = null;
            if (is_object($doc) && isset($doc->relevanceScore)) {
                $relevanceScore = $doc->relevanceScore;
            } elseif (is_array($doc) && isset($doc['score'])) {
                $relevanceScore = $doc['score'];
            } elseif (is_array($doc) && isset($doc['relevanceScore'])) {
                $relevanceScore = $doc['relevanceScore'];
            }

            $docData = [
                'id' => (is_object($doc) ? $doc->id : ($doc['documentId'] ?? $doc['id'])),
                'title' => (is_object($doc) ? $doc->title : ($doc['title'] ?? 'Untitled')),
                'type' => (is_object($doc) ? $doc->source_type : ($doc['sourceType'] ?? 'unknown')),
                'source' => (is_object($doc) ? $doc->source : ($doc['source'] ?? null)),
                'score' => $relevanceScore,
                'is_expired' => (is_object($doc) ? ($doc->is_expired ?? false) : ($doc['isExpired'] ?? false)),
                'created_at' => (is_object($doc) ? $doc->created_at : ($doc['createdAt'] ?? null)),
            ];

            // Include content if requested
            if ($includeContent && isset($doc['content'])) {
                $docData['content'] = $doc['content'];
                $docData['content_length'] = strlen($doc['content']);
                $docData['summary'] = $doc['summary'] ?? null;
            }

            // Include match positions for contextual excerpts
            if (isset($doc['_matchesPosition'])) {
                $docData['_matchesPosition'] = $doc['_matchesPosition'];
            }

            // Include additional metadata
            if (isset($doc['metadata'])) {
                $docData['metadata'] = $doc['metadata'];
            }

            if (isset($doc['highlights'])) {
                $docData['highlights'] = $doc['highlights'];
            }

            if (isset($doc['tags'])) {
                $docData['tags'] = $doc['tags'];
            }

            return $docData;
        })->toArray();

        $retrievalGuidance = $this->buildRetrievalGuidance($documents, $query, $agentId);

        return [
            'documents' => $documents,
            'total_results' => count($documents),
            'search_metadata' => [
                'processing_time_ms' => round($result->processingTime * 1000, 2),
                'search_method' => $result->metadata['searchType'] ?? $searchType,
                'original_results' => $result->metadata['originalResults'] ?? 0,
                'filtered_results' => $result->metadata['filteredResults'] ?? 0,
                'document_scope' => $result->metadata['documentScope'] ?? 0,
                'filters_applied' => $result->usedFilters ?? [],
                'has_more' => count($documents) >= $limit,
                'has_expired_documents' => $result->hasExpiredDocuments(),
            ],
            'retrieval_guidance' => $retrievalGuidance,
            'rag_result' => $result, // Include original result for advanced use cases
        ];
    }

    /**
     * Generate RAG context from search results
     */
    public function generateContext(array $params): array
    {
        $query = $params['question'] ?? $params['query'] ?? '';
        $searchType = $params['search_type'] ?? 'hybrid';
        $maxContextLength = $params['max_context_length'] ?? 8000;
        $numDocuments = $params['num_documents'] ?? $params['limit'] ?? 5;
        $agentId = $params['agent_id'] ?? null;
        $includeSources = $params['include_sources'] ?? true;
        $includeExpired = $params['include_expired'] ?? false;
        $relevanceThreshold = $params['relevance_threshold'] ?? 0.1;
        $provider = $params['provider'] ?? null; // NEW: Provider parameter for file type validation

        // Use search method to get documents (status reporting is already configured)
        $searchResult = $this->search([
            'query' => $query,
            'search_type' => $searchType,
            'limit' => $numDocuments,
            'agent_id' => $agentId,
            'include_content' => true,
            'include_expired' => $includeExpired,
            'relevance_threshold' => $relevanceThreshold,
        ]);

        // Get full document models for proper content retrieval and Prism conversion
        $documentIds = collect($searchResult['documents'])->pluck('id');
        $documentModels = KnowledgeDocument::whereIn('id', $documentIds)->with('tags')->get();

        // Convert to Prism documents using DocumentInjectionService with provider validation
        $injectionService = app(DocumentInjectionService::class);
        $prismDocuments = $injectionService->createDocumentBatch($documentModels, $provider);

        // Generate text context using the RAG service (for backward compatibility and logging)
        $context = $this->rag->generateContext(
            collect($searchResult['documents']),
            $maxContextLength,
            $query
        );

        // Prepare sources if requested
        $sources = null;
        if ($includeSources) {
            // Create a lookup map for document models by ID
            $documentModelMap = $documentModels->keyBy('id');

            $sources = collect($searchResult['documents'])->map(function ($doc) use ($documentModelMap) {
                $model = $documentModelMap->get($doc['id']);
                $tags = $model ? $model->tags->pluck('name')->toArray() : [];

                return [
                    'id' => $doc['id'],
                    'title' => $doc['title'],
                    'source' => $doc['source'],
                    'type' => $doc['type'],
                    'score' => $doc['score'],
                    'is_expired' => $doc['is_expired'],
                    'tags' => $tags,
                ];
            })->toArray();
        }

        return [
            'context' => $context,                    // Keep text context for backward compatibility
            'prism_documents' => $prismDocuments,     // NEW: Prism Document objects for injection
            'context_length' => strlen($context),
            'documents_used' => count($searchResult['documents']),
            'prism_documents_created' => count($prismDocuments),
            'sources' => $sources,
            'search_metadata' => $searchResult['search_metadata'],
            'warnings' => $searchResult['search_metadata']['has_expired_documents']
                ? ['Some relevant documents were excluded or marked as expired.']
                : [],
            'interaction_id' => $this->interactionId,
        ];
    }

    /**
     * Retrieve a specific document
     */
    public function retrieveDocument(array $params): ?array
    {
        $documentId = $params['document_id'] ?? null;
        $source = $params['source'] ?? null;
        $includeContent = $params['include_content'] ?? true;
        $includeMetadata = $params['include_metadata'] ?? true;

        // Find document by ID or source
        if ($documentId) {
            $document = KnowledgeDocument::find($documentId);
        } elseif ($source) {
            $document = KnowledgeDocument::where('source', $source)->first();
        } else {
            return null;
        }

        if (! $document) {
            return null;
        }

        // Build response data
        $documentData = [
            'id' => $document->id,
            'title' => $document->title,
            'type' => $document->type,
            'source' => $document->source,
            'processing_status' => $document->processing_status,
            'created_at' => $document->created_at?->toISOString(),
            'updated_at' => $document->updated_at?->toISOString(),
        ];

        // Include content if requested
        if ($includeContent) {
            $documentData['content'] = $document->content;
            $documentData['content_length'] = strlen($document->content ?? '');
        }

        // Include metadata if requested
        if ($includeMetadata) {
            $documentData['metadata'] = $document->metadata ? json_decode($document->metadata, true) : null;
            $documentData['ttl'] = $document->ttl?->toISOString();
            $documentData['agent_id'] = $document->agent_id;
            $documentData['meilisearch_document_id'] = $document->meilisearch_document_id;
            $documentData['has_embeddings'] = ! empty($document->meilisearch_document_id);
        }

        return $documentData;
    }

    /**
     * Get tag IDs by tag names for filtering
     */
    public function getTagIdsByNames(array $tagNames): array
    {
        if (empty($tagNames)) {
            return [];
        }

        return \App\Models\KnowledgeTag::whereIn('name', $tagNames)
            ->orWhereIn('slug', array_map('Str::slug', $tagNames))
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get current agent ID from context
     */
    public function getAgentIdFromContext(): ?int
    {
        // Priority 1: Check container binding (set by AgentExecutor)
        if (app()->has('current_agent_id')) {
            return app('current_agent_id');
        }

        // Priority 2: Check if there's an active agent execution in session
        if (session()->has('current_agent_id')) {
            return session('current_agent_id');
        }

        // Priority 3: Check if there's an agent in the request context
        if (request()->has('agent_id')) {
            return (int) request('agent_id');
        }

        // Default to null (will search all accessible knowledge)
        return null;
    }

    /**
     * Build retrieval guidance for the agent based on search results
     */
    protected function buildRetrievalGuidance(array $documents, string $query, ?int $agentId): array
    {
        $documentCount = count($documents);
        $guidance = [
            'strategy' => 'selective_retrieval',
            'document_count' => $documentCount,
            'recommendations' => [],
            'citation_requirements' => [
                'always_cite_sources' => true,
                'format' => 'According to [Source Title], [specific information]...',
                'example' => 'According to Project Documentation, the API supports rate limiting...',
                'avoid_fake_citations' => 'Never create fake or generic citations - only use specific source titles',
            ],
        ];

        // Determine retrieval strategy based on document count (inherited from early injection logic)
        if ($documentCount === 0) {
            $guidance['strategy'] = 'no_results';
            $guidance['recommendations'][] = 'No relevant documents found. Consider broadening search terms or checking document availability.';
            $guidance['recommendations'][] = 'Try alternative keywords or search in different document categories.';
        } elseif ($documentCount <= 5) {
            $guidance['strategy'] = 'retrieve_all';
            $guidance['recommendations'][] = 'Small result set - retrieve and analyze ALL documents for comprehensive coverage.';
            $guidance['recommendations'][] = 'Cross-reference information across all sources for complete understanding.';
            $guidance['recommendations'][] = 'Use retrieve_full_document tool for any documents requiring complete content.';
        } else {
            $guidance['strategy'] = 'selective_retrieval';
            $guidance['recommendations'][] = 'Large result set - prioritize documents with highest relevance scores.';
            $guidance['recommendations'][] = 'Focus on top 3-5 most relevant documents to avoid information overload.';
            $guidance['recommendations'][] = 'Use document summaries first, retrieve full content only when necessary.';
        }

        // Add query-specific guidance
        if (! empty($query)) {
            $guidance['query_analysis'] = [
                'original_query' => $query,
                'search_intent' => $this->analyzeSearchIntent($query),
            ];
        }

        // Add document type analysis
        $types = array_filter(array_column($documents, 'type'), function ($type) {
            return is_string($type) || is_int($type);
        });
        if (! empty($types)) {
            $documentTypes = array_count_values($types);
            $guidance['document_types'] = $documentTypes;
            $guidance['recommendations'][] = 'Document types available: '.implode(', ', array_keys($documentTypes));
        }

        // Add relevance score guidance
        $scores = array_filter(array_column($documents, 'score'), function ($score) {
            return $score !== null;
        });

        if (! empty($scores)) {
            $avgScore = array_sum($scores) / count($scores);
            $maxScore = max($scores);
            $minScore = min($scores);

            $guidance['relevance_analysis'] = [
                'average_score' => round($avgScore, 3),
                'highest_score' => $maxScore,
                'lowest_score' => $minScore,
                'score_distribution' => $this->categorizeScores($scores),
            ];

            if ($avgScore > 0.7) {
                $guidance['recommendations'][] = 'High relevance scores detected - documents are highly relevant to query.';
            } elseif ($avgScore > 0.4) {
                $guidance['recommendations'][] = 'Moderate relevance scores - focus on highest scoring documents first.';
            } else {
                $guidance['recommendations'][] = 'Lower relevance scores - consider refining search query or expanding search scope.';
            }
        }

        // Add expiration guidance
        $expiredCount = count(array_filter($documents, function ($doc) {
            return $doc['is_expired'] ?? false;
        }));

        if ($expiredCount > 0) {
            $guidance['expired_documents'] = $expiredCount;
            $guidance['recommendations'][] = "Warning: {$expiredCount} expired documents found. Information may be outdated.";
        }

        return $guidance;
    }

    /**
     * Analyze search intent from query
     */
    protected function analyzeSearchIntent(string $query): string
    {
        $query = strtolower($query);

        // Question words indicate information seeking
        if (preg_match('/\b(what|how|why|when|where|who|which)\b/', $query)) {
            return 'information_seeking';
        }

        // Action words indicate task-oriented
        if (preg_match('/\b(create|make|build|implement|setup|configure|install)\b/', $query)) {
            return 'task_oriented';
        }

        // Problem words indicate troubleshooting
        if (preg_match('/\b(error|problem|issue|bug|fix|solve|debug)\b/', $query)) {
            return 'troubleshooting';
        }

        // Comparison words indicate analysis
        if (preg_match('/\b(compare|versus|vs|difference|better|best|alternative)\b/', $query)) {
            return 'comparative_analysis';
        }

        return 'general_inquiry';
    }

    /**
     * Categorize relevance scores into bands
     */
    protected function categorizeScores(array $scores): array
    {
        $categories = [
            'high' => 0,      // > 0.7
            'medium' => 0,    // 0.4 - 0.7
            'low' => 0,       // < 0.4
        ];

        foreach ($scores as $score) {
            if ($score > 0.7) {
                $categories['high']++;
            } elseif ($score >= 0.4) {
                $categories['medium']++;
            } else {
                $categories['low']++;
            }
        }

        return $categories;
    }
}
