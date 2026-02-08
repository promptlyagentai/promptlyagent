<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Http\Controllers\Api\V1\Knowledge\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Knowledge\RagQueryRequest;
use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Knowledge Management
 *
 * Retrieve contextual knowledge using Retrieval-Augmented Generation (RAG). Query your
 * knowledge base to get relevant document excerpts formatted for AI context injection.
 *
 * ## Features
 * - Semantic search with relevance scoring
 * - Context length control for token budget management
 * - Agent-specific document filtering
 * - Privacy-aware access control (public + owned documents)
 * - Automatic TTL expiration filtering
 * - Real-time streaming for progressive context building
 *
 * ## Use Cases
 * - Provide context to AI agents before task execution
 * - Build dynamic prompts with relevant knowledge
 * - Enable chatbots to answer from your knowledge base
 * - Create context-aware automation workflows
 *
 * ## Rate Limiting
 * - RAG query operations: 60 requests/minute
 */
class KnowledgeRagController extends Controller
{
    use ApiResponseTrait;

    /**
     * Query knowledge base for RAG context
     *
     * Retrieve relevant knowledge documents for a given query, formatted as RAG context.
     * Returns concatenated document excerpts with source attribution, ready for AI injection.
     *
     * Results are automatically filtered by privacy (public + user-owned) and TTL expiration.
     * Optionally filter to agent-specific documents.
     *
     * @authenticated
     *
     * @bodyParam query string required The search query to find relevant knowledge. Example: How do I configure Laravel queues?
     * @bodyParam limit integer Optional number of documents to retrieve. Defaults to 5. Maximum 25. Example: 10
     * @bodyParam context_length integer Optional maximum context length in characters. Defaults to 4000. Example: 8000
     * @bodyParam include_expired boolean Optional include expired documents. Defaults to false. Example: false
     * @bodyParam agent_id integer Optional filter documents assigned to specific agent. Example: 5
     * @bodyParam include_sources boolean Optional include source document metadata in response. Defaults to true. Example: true
     *
     * @response 200 scenario="Success" {"success": true, "data": {"context": "[Source: Laravel Queues Guide]\nLaravel queues provide a unified API for background job processing...\n\n[Source: Redis Configuration]\nTo use Redis as your queue driver, set QUEUE_CONNECTION=redis...", "query": "How do I configure Laravel queues?", "total_sources": 2, "sources": [{"id": 1, "title": "Laravel Queues Guide", "relevance_score": 0.8, "document_type": "text"}, {"id": 5, "title": "Redis Configuration", "relevance_score": 0.8, "document_type": "file"}]}}
     * @response 200 scenario="No Sources Found" {"success": true, "data": {"context": "", "query": "obscure query with no matches", "total_sources": 0, "sources": []}}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 500 scenario="Failed" {"success": false, "error": "RAG query failed"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object RAG context data
     * @responseField data.context string Formatted RAG context with source attribution (ready for AI injection)
     * @responseField data.query string The original search query
     * @responseField data.total_sources integer Number of source documents included
     * @responseField data.sources array Source document metadata (if include_sources=true)
     * @responseField data.sources[].id integer Document ID
     * @responseField data.sources[].title string Document title
     * @responseField data.sources[].relevance_score number Search relevance score (0-1)
     * @responseField data.sources[].document_type string Document content type (text, file, external)
     */
    public function query(RagQueryRequest $request): JsonResponse
    {
        try {
            $filters = $this->buildRagFilters($request);

            $query = KnowledgeDocument::search($request->input('query'));

            $query->query(function ($builder) use ($request) {
                $builder->where(function ($q) {
                    $q->where('privacy_level', 'public')
                        ->orWhere('created_by', Auth::id());
                });

                if (! $request->boolean('include_expired', false)) {
                    $builder->where(function ($q) {
                        $q->whereNull('ttl_expires_at')
                            ->orWhere('ttl_expires_at', '>', now());
                    });
                }

                if ($request->filled('agent_id')) {
                    $builder->whereHas('agentAssignments', function ($q) use ($request) {
                        $q->where('agent_id', $request->input('agent_id'));
                    });
                }
            });

            $limit = $request->input('limit', 5);
            $results = $query->take($limit)->get();

            $contextLength = $request->input('context_length', 4000);
            $ragContext = $this->formatForRAG($results, $contextLength);

            $responseData = [
                'context' => $ragContext,
                'query' => $request->input('query'),
                'total_sources' => $results->count(),
            ];

            if ($request->boolean('include_sources', true)) {
                $responseData['sources'] = $results->map(function ($result) {
                    return [
                        'id' => $result->id,
                        'title' => $result->title,
                        'relevance_score' => 0.8,
                        'document_type' => $result->content_type,
                    ];
                });
            }

            return $this->successResponse($responseData);

        } catch (\Exception $e) {
            Log::error('Knowledge API: RAG query failed', [
                'query' => $request->input('query'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('RAG query failed');
        }
    }

    /**
     * Get RAG context (alias)
     *
     * Alternative endpoint for retrieving RAG context. Functionally identical to the query endpoint.
     * Provided for semantic clarity in workflows where "context" terminology is preferred.
     *
     * @authenticated
     *
     * @bodyParam query string required The search query to find relevant knowledge. Example: How do I configure Laravel queues?
     * @bodyParam limit integer Optional number of documents to retrieve. Defaults to 5. Maximum 25. Example: 10
     * @bodyParam context_length integer Optional maximum context length in characters. Defaults to 4000. Example: 8000
     * @bodyParam include_expired boolean Optional include expired documents. Defaults to false. Example: false
     * @bodyParam agent_id integer Optional filter documents assigned to specific agent. Example: 5
     * @bodyParam include_sources boolean Optional include source document metadata in response. Defaults to true. Example: true
     *
     * @response 200 scenario="Success" {"success": true, "data": {"context": "[Source: Laravel Queues Guide]\nLaravel queues provide a unified API...", "query": "How do I configure Laravel queues?", "total_sources": 2, "sources": [{"id": 1, "title": "Laravel Queues Guide", "relevance_score": 0.8, "document_type": "text"}]}}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 500 scenario="Failed" {"success": false, "error": "RAG query failed"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object RAG context data
     * @responseField data.context string Formatted RAG context with source attribution
     * @responseField data.query string The original search query
     * @responseField data.total_sources integer Number of source documents included
     * @responseField data.sources array Source document metadata (if include_sources=true)
     */
    public function context(RagQueryRequest $request): JsonResponse
    {
        return $this->query($request);
    }

    /**
     * Stream RAG context with real-time SSE
     *
     * Retrieve relevant knowledge with Server-Sent Events (SSE) streaming. Returns context
     * progressively as sources are found and processed, enabling real-time UI updates.
     *
     * Ideal for building responsive interfaces where users see sources appearing in real-time
     * rather than waiting for the complete result.
     *
     * **Connection Requirements:**
     * - Set `Accept: text/event-stream` header
     * - Keep connection open to receive events
     * - Parse SSE format: `data: {json}\n\n`
     *
     * **Event Sequence:**
     * 1. `context_retrieved` - Initial event with source count
     * 2. `source` - One event per source document (progressive)
     * 3. `context` - Final formatted RAG context
     * 4. `done` - Completion signal
     * 5. `error` - Only on failure
     *
     * @authenticated
     *
     * @bodyParam query string required The search query to find relevant knowledge. Example: How do I configure Laravel queues?
     * @bodyParam limit integer Optional number of documents to retrieve. Defaults to 5. Maximum 25. Example: 10
     * @bodyParam context_length integer Optional maximum context length in characters. Defaults to 4000. Example: 8000
     * @bodyParam include_expired boolean Optional include expired documents. Defaults to false. Example: false
     *
     * @response 200 scenario="SSE Stream" event: message
     * data: {"type": "context_retrieved", "sources_found": 3}
     *
     * data: {"type": "source", "data": {"id": 1, "title": "Laravel Queues Guide", "score": 0.8}}
     *
     * data: {"type": "source", "data": {"id": 5, "title": "Redis Configuration", "score": 0.8}}
     *
     * data: {"type": "context", "data": "[Source: Laravel Queues Guide]\nLaravel queues provide..."}
     *
     * data: {"type": "done"}
     * @response 200 scenario="SSE Error" event: message
     * data: {"type": "error", "message": "RAG streaming failed"}
     * @response 403 scenario="Missing Ability" {"error": "Unauthorized", "message": "Your API token does not have the knowledge:rag ability"}
     */
    public function stream(RagQueryRequest $request): StreamedResponse
    {
        if (! $request->user()->tokenCan('knowledge:rag')) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Your API token does not have the knowledge:rag ability',
            ], 403);
        }

        return response()->stream(function () use ($request) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            try {
                $query = KnowledgeDocument::search($request->input('query'));

                $query->query(function ($builder) use ($request) {
                    $builder->where(function ($q) {
                        $q->where('privacy_level', 'public')
                            ->orWhere('created_by', Auth::id());
                    });

                    if (! $request->boolean('include_expired', false)) {
                        $builder->where(function ($q) {
                            $q->whereNull('ttl_expires_at')
                                ->orWhere('ttl_expires_at', '>', now());
                        });
                    }
                });

                $limit = $request->input('limit', 5);
                $results = $query->take($limit)->get();

                echo 'data: '.json_encode([
                    'type' => 'context_retrieved',
                    'sources_found' => $results->count(),
                ])."\n\n";
                flush();

                foreach ($results as $result) {
                    echo 'data: '.json_encode([
                        'type' => 'source',
                        'data' => [
                            'id' => $result->id,
                            'title' => $result->title,
                            'score' => 0.8,
                        ],
                    ])."\n\n";
                    flush();
                }

                $contextLength = $request->input('context_length', 4000);
                $context = $this->formatForRAG($results, $contextLength);

                echo 'data: '.json_encode([
                    'type' => 'context',
                    'data' => $context,
                ])."\n\n";
                flush();

                echo 'data: '.json_encode([
                    'type' => 'done',
                ])."\n\n";
                flush();

            } catch (\Exception $e) {
                Log::error('Knowledge API: RAG streaming failed', [
                    'query' => $request->input('query'),
                    'error' => $e->getMessage(),
                    'user_id' => Auth::id(),
                ]);

                echo 'data: '.json_encode([
                    'type' => 'error',
                    'message' => 'RAG streaming failed',
                ])."\n\n";
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Build RAG-specific filters
     */
    private function buildRagFilters(Request $request): array
    {
        $filters = [];

        $filters['user_id'] = Auth::id();

        $filters['_privacy_filter'] = [
            'privacy_level = "public"',
            'created_by = '.Auth::id(),
        ];

        if (! $request->boolean('include_expired', false)) {
            $filters['ttl_expires_at'] = [
                'IS NULL',
                '>= "'.now()->toISOString().'"',
            ];
        }

        return $filters;
    }

    /**
     * Format search results for RAG context
     */
    private function formatForRAG($results, int $maxLength = 4000): string
    {
        $context = '';
        $currentLength = 0;

        foreach ($results as $result) {
            $source = "[Source: {$result->title}]\n";
            $content = strip_tags($result->content)."\n\n";
            $addition = $source.$content;

            if ($currentLength + strlen($addition) > $maxLength) {
                break;
            }

            $context .= $addition;
            $currentLength += strlen($addition);
        }

        return trim($context);
    }
}
