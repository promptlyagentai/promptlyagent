<?php

namespace App\Tools;

use App\Models\ChatInteractionKnowledgeSource;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeToolService;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * KnowledgeRAGTool - Retrieval-Augmented Generation Pipeline.
 *
 * Prism tool providing semantic search across knowledge base with automatic
 * context generation for AI consumption. Integrates hybrid search (semantic +
 * keyword), relevance scoring, and RAG context assembly.
 *
 * Search Pipeline:
 * 1. Query embedding generation (OpenAI/Ollama)
 * 2. Hybrid search: Semantic (vector) + Keyword (Meilisearch)
 * 3. Relevance filtering (configurable threshold: default 0.65)
 * 4. Document ranking by score
 * 5. Context generation (max 4000 chars)
 * 6. Source tracking in ChatInteractionKnowledgeSource
 *
 * Two-Level Scope Filtering:
 * - Level 1: Scope tags restrict universe (app('knowledge_scope_tags'))
 * - Level 2: AI-provided tags refine within universe (tool parameter)
 * - Agent-level filtering via agent_id context
 *
 * Search Modes:
 * - Full mode: Returns documents + context + metadata
 * - Context-only mode: Returns only generated context (faster)
 *
 * Relevance Scoring:
 * - Configurable threshold (0-1 range)
 * - Default: 0.65 (config: knowledge.search.internal_knowledge_threshold)
 * - Filters: Expired documents, tag matches, document IDs
 *
 * TTL Handling:
 * - Automatically excludes expired documents (unless include_expired=true)
 * - Warns when relevant documents excluded due to expiration
 *
 * Source Persistence:
 * - Tracks retrieved documents in ChatInteractionKnowledgeSource pivot
 * - Validates document existence before persisting
 * - Handles stale Meilisearch index entries gracefully
 *
 * @see \App\Services\Knowledge\RAG\KnowledgeRAG
 * @see \App\Services\Knowledge\KnowledgeToolService
 * @see \App\Models\KnowledgeDocument
 */
class KnowledgeRAGTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('knowledge_search')
            ->for('Search the knowledge base for relevant information using semantic search. This tool provides access to documents, files, and other knowledge sources assigned to the agent.')
            ->withStringParameter('query', 'The search query to find relevant knowledge. Use natural language to describe what information you need.')
            ->withNumberParameter('limit', 'Maximum number of results to return (1-20, default: 5)')
            ->withBooleanParameter('include_expired', 'Whether to include documents that have passed their TTL expiration date (default: false)')
            ->withNumberParameter('relevance_threshold', 'Minimum relevance score (0-1) for results to include (default: 0.65)')
            ->withArrayParameter('document_ids', 'Specific document IDs to search within (optional)', new NumberSchema('document_id', 'Document ID'), false)
            ->withArrayParameter('tags', 'Tags to filter results by (optional)', new StringSchema('tag', 'Tag name'), false)
            ->withBooleanParameter('context_only', 'If true, return only the generated context text suitable for AI consumption (default: false)')
            ->using(function (
                string $query,
                int $limit = 5,
                bool $include_expired = false,
                ?float $relevance_threshold = null,
                array $document_ids = [],
                array $tags = [],
                bool $context_only = false
            ) {
                // Use configurable default for relevance_threshold if not provided
                $relevance_threshold = $relevance_threshold ?? config('knowledge.search.internal_knowledge_threshold', 0.65);

                return static::executeKnowledgeSearch([
                    'query' => $query,
                    'limit' => $limit,
                    'include_expired' => $include_expired,
                    'relevance_threshold' => $relevance_threshold,
                    'document_ids' => $document_ids,
                    'tags' => $tags,
                    'context_only' => $context_only,
                ]);
            });
    }

    protected static function executeKnowledgeSearch(array $arguments = []): string
    {
        try {
            // Check if there's a status reporter available for this execution
            $statusReporter = null;
            $interactionId = null;

            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            // Fallback to agent execution context for interaction ID
            if ($statusReporter && ! $interactionId && app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
                Log::info('KnowledgeRAGTool: Retrieved interaction ID from current_interaction_id fallback', [
                    'interaction_id' => $interactionId,
                ]);
            }

            // Report meaningful start - mark as significant to render as timeline dot
            if ($statusReporter) {
                $statusReporter->report('knowledge_search', "Searching knowledge base: {$arguments['query']}", true, false);
            }

            // Create service with status reporting context
            $knowledgeService = new KnowledgeToolService($statusReporter, $interactionId);

            // Validate input

            $validator = Validator::make($arguments, [
                'query' => 'required|string|min:1|max:500',
                'limit' => 'integer|min:1|max:20',
                'include_expired' => 'boolean',
                'relevance_threshold' => 'numeric|min:0|max:1',
                'document_ids' => 'array',
                'document_ids.*' => 'integer',
                'tags' => 'array',
                'tags.*' => 'string',
                'context_only' => 'boolean',
            ]);

            if ($validator->fails()) {
                Log::warning('KnowledgeRAGTool: Validation failed', [
                    'interaction_id' => $interactionId,
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'KnowledgeRAGTool');
            }

            $validated = $validator->validated();

            // Get agent ID from context
            $agentId = $knowledgeService->getAgentIdFromContext();

            // Knowledge scope filtering: two-level system
            // Level 1: Scope tags restrict universe (stored in container)
            // Level 2: AI tags refine within universe (provided by AI)
            $aiProvidedTags = $validated['tags'] ?? [];
            $scopeTags = [];

            if (app()->has('knowledge_scope_tags')) {
                $scopeTags = app('knowledge_scope_tags');
                if (is_array($scopeTags) && ! empty($scopeTags)) {
                    Log::info('KnowledgeRAGTool: Applying two-level tag filtering', [
                        'interaction_id' => $interactionId,
                        'scope_tags' => $scopeTags,
                        'ai_provided_tags' => $aiProvidedTags,
                        'note' => 'Scope tags restrict universe, AI tags refine within it',
                    ]);
                }
            }

            // Convert AI-provided tag names to IDs for refinement filtering
            // Note: Scope tags are handled at the document retrieval level in KnowledgeRAG
            $tagIds = ! empty($aiProvidedTags) ? $knowledgeService->getTagIdsByNames($aiProvidedTags) : [];

            // Determine if we should return context only
            $contextOnly = $validated['context_only'] ?? false;

            if ($contextOnly) {

                // Generate context only (with status reporting)
                $contextResult = $knowledgeService->generateContext([
                    'query' => $validated['query'],
                    'search_type' => 'hybrid',
                    'max_context_length' => 4000,
                    'num_documents' => $validated['limit'] ?? 5,
                    'agent_id' => $agentId,
                    'include_sources' => true, // CRITICAL FIX: Need sources for persistence
                    'include_expired' => $validated['include_expired'] ?? false,
                    'relevance_threshold' => $validated['relevance_threshold'] ?? config('knowledge.search.internal_knowledge_threshold', 0.65),
                ]);

                // Persist knowledge sources even in context_only mode
                if ($statusReporter && $interactionId && isset($contextResult['sources']) && ! empty($contextResult['sources'])) {
                    foreach ($contextResult['sources'] as $source) {
                        try {
                            // Check if the document actually exists in the database
                            $documentExists = KnowledgeDocument::where('id', $source['id'])->exists();

                            if (! $documentExists) {
                                Log::warning('Skipping knowledge source - document not found in database', [
                                    'interaction_id' => $interactionId,
                                    'document_id' => $source['id'],
                                    'title' => $source['title'] ?? 'Unknown',
                                ]);

                                continue;
                            }

                            $createdRecord = ChatInteractionKnowledgeSource::createOrUpdate(
                                $interactionId,
                                $source['id'],
                                $source['score'],
                                mb_substr($source['title'] ?? '', 0, 200),
                                'knowledge_search',
                                'KnowledgeRAGTool',
                                [
                                    'query' => $validated['query'],
                                    'search_type' => 'hybrid',
                                    'tags' => $source['tags'] ?? [],
                                    'is_expired' => $source['is_expired'] ?? false,
                                    'context_only_mode' => true,
                                ]
                            );

                        } catch (\Exception $e) {
                            Log::error('KnowledgeRAGTool: Failed to persist context_only source', [
                                'interaction_id' => $interactionId,
                                'document_id' => $source['id'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                return static::safeJsonEncode([
                    'success' => true,
                    'data' => [
                        'context' => $contextResult['context'],
                        'document_count' => $contextResult['documents_used'],
                        'processing_time' => $contextResult['search_metadata']['processing_time_ms'].'ms',
                    ],
                ], 'KnowledgeRAGTool');
            }

            // Perform full search (with status reporting)
            try {
                $searchResult = $knowledgeService->search([
                    'query' => $validated['query'],
                    'search_type' => 'hybrid',
                    'limit' => $validated['limit'] ?? 5,
                    'agent_id' => $agentId,
                    'include_content' => true,
                    'include_expired' => $validated['include_expired'] ?? false,
                    'relevance_threshold' => $validated['relevance_threshold'] ?? config('knowledge.search.internal_knowledge_threshold', 0.65),
                    'document_ids' => $validated['document_ids'] ?? [],
                    'tag_ids' => $tagIds,
                ]);
            } catch (\Exception $searchException) {
                Log::error('KnowledgeRAGTool: Search call threw exception', [
                    'interaction_id' => $interactionId,
                    'query' => $validated['query'],
                    'error' => $searchException->getMessage(),
                    'error_type' => get_class($searchException),
                    'file' => $searchException->getFile(),
                    'line' => $searchException->getLine(),
                ]);
                throw $searchException; // Re-throw to be caught by outer catch
            }

            // Generate context from search results using the RAG service directly (avoid duplicate search)
            // Instantiate RAG service with status reporting context
            $ragService = new \App\Services\Knowledge\RAG\KnowledgeRAG($statusReporter, $interactionId);
            $ragResult = $searchResult['rag_result'];
            $context = $ragService->generateContext(
                $ragResult->documents,
                4000,
                $validated['query']
            );

            $contextResult = [
                'context' => $context,
                'context_length' => strlen($context),
                'documents_used' => $ragResult->documents->count(),
            ];

            // Report search completion and persist knowledge sources
            if ($statusReporter && $interactionId && ! empty($searchResult['documents'])) {
                $sourceCount = count($searchResult['documents']);
                $statusReporter->report('knowledge_search', "Found {$sourceCount} relevant knowledge documents", true, false);

                // Persist knowledge sources to database (validate document existence first)
                foreach ($searchResult['documents'] as $doc) {
                    try {
                        // Check if the document actually exists in the database
                        $documentExists = KnowledgeDocument::where('id', $doc['id'])->exists();

                        if (! $documentExists) {
                            Log::warning('KnowledgeRAGTool: Skipping knowledge source - document not found in database', [
                                'interaction_id' => $interactionId,
                                'document_id' => $doc['id'],
                                'title' => $doc['title'] ?? 'Unknown',
                                'note' => 'This indicates a stale Meilisearch index entry',
                            ]);

                            continue;
                        }

                        $createdRecord = ChatInteractionKnowledgeSource::createOrUpdate(
                            $interactionId,
                            $doc['id'],
                            $doc['score'],
                            $doc['summary'] ?? mb_substr(strip_tags($doc['content'] ?? ''), 0, 200),
                            'knowledge_search',
                            'KnowledgeRAGTool',
                            [
                                'query' => $validated['query'],
                                'search_type' => 'hybrid',
                                'tags' => $doc['tags'] ?? [],
                                'is_expired' => $doc['is_expired'] ?? false,
                            ]
                        );
                    } catch (\Exception $e) {
                        Log::error('KnowledgeRAGTool: Failed to persist knowledge source', [
                            'interaction_id' => $interactionId,
                            'document_id' => $doc['id'],
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            }

            // Format response to match original structure
            $response = [
                'query' => $validated['query'],
                'total_results' => $searchResult['total_results'],
                'processing_time' => $searchResult['search_metadata']['processing_time_ms'].'ms',
                'context' => $contextResult['context'],
                'documents' => collect($searchResult['documents'])->map(function ($doc) {
                    return [
                        'id' => $doc['id'],
                        'title' => $doc['title'],
                        'summary' => $doc['summary'] ?? mb_substr(strip_tags($doc['content'] ?? ''), 0, 200),
                        'score' => round($doc['score'], 3),
                        'source_type' => $doc['type'],
                        'is_expired' => $doc['is_expired'] ?? false,
                        'tags' => $doc['tags'] ?? [],
                        'highlights' => $doc['highlights'] ?? [],
                        'created_at' => is_object($doc['created_at'])
                            ? $doc['created_at']->toISOString()
                            : ($doc['created_at'] ?? null),
                    ];
                })->toArray(),
                'metadata' => [
                    'search_type' => $searchResult['search_metadata']['search_method'] ?? 'hybrid',
                    'filters_applied' => $searchResult['search_metadata']['filters_applied'] ?? [],
                    'has_expired_documents' => $searchResult['search_metadata']['has_expired_documents'] ?? false,
                ],
            ];

            // Add warning for expired documents if present
            if (($response['metadata']['has_expired_documents'] ?? false) && ! ($validated['include_expired'] ?? false)) {
                $response['warning'] = 'Some relevant documents were excluded because they have expired. Set "include_expired": true to include them.';
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => $response,
            ], 'KnowledgeRAGTool');

        } catch (\Exception $e) {
            Log::error('KnowledgeRAGTool: Exception caught during search execution', [
                'interaction_id' => $interactionId ?? null,
                'query' => $arguments['query'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Search failed: '.$e->getMessage(),
                'metadata' => [
                    'query' => $arguments['query'] ?? 'unknown',
                    'error_type' => get_class($e),
                ],
            ], 'KnowledgeRAGTool');
        }
    }
}
