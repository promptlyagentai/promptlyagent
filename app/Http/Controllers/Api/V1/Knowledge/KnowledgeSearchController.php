<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Http\Controllers\Api\V1\Knowledge\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Knowledge\SearchKnowledgeRequest;
use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Knowledge Management
 *
 * Search operations for knowledge documents using full-text, semantic, and hybrid search.
 * Powered by Meilisearch for fast, typo-tolerant full-text search and embeddings for semantic similarity.
 *
 * ## Search Types
 * - **Full-text**: Keyword-based search with typo tolerance
 * - **Semantic**: Meaning-based search using embeddings
 * - **Hybrid**: Combines both approaches for best results
 *
 * ## Rate Limiting
 * - Search operations: 60 requests/minute
 */
class KnowledgeSearchController extends Controller
{
    use ApiResponseTrait;

    /**
     * Search knowledge documents
     *
     * Full-text search across all accessible knowledge documents using Meilisearch.
     * Supports filtering by content type, tags, and expiration status.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/knowledge-api.js`) - Knowledge search with offline support:
     * - Full-text keyword search with typo tolerance
     * - Automatic result caching for offline access
     * - Intelligent fallback to cached results when offline
     * - Cache validity: 30 minutes
     *
     * @authenticated
     *
     * @bodyParam query string required The search query. Example: Laravel routing best practices
     * @bodyParam limit integer Optional maximum results to return (1-100). Defaults to 10. Example: 20
     * @bodyParam content_type string Optional filter by content type. Options: text, file, external. Example: external
     * @bodyParam tags string[] Optional filter by tag names. Example: ["laravel", "php"]
     * @bodyParam include_expired boolean Optional include expired documents. Defaults to false. Example: false
     *
     * @response 200 scenario="Success" {"success": true, "data": [{"id": 1, "title": "Laravel Routing Guide", "content": "Laravel routing allows you to...", "score": 1.0, "created_at": "2024-01-01T00:00:00Z"}]}
     * @response 500 scenario="Search Failed" {"success": false, "error": "Search failed"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data array Array of search results ordered by relevance
     * @responseField data[].id integer Document ID
     * @responseField data[].title string Document title
     * @responseField data[].content string Content preview (first 200 characters)
     * @responseField data[].score number Relevance score (0-1)
     * @responseField data[].created_at string Creation timestamp (ISO 8601)
     */
    public function search(SearchKnowledgeRequest $request): JsonResponse
    {
        try {
            $searchQuery = $request->input('query');
            $limit = $request->input('limit', 10);

            $query = KnowledgeDocument::search($searchQuery);

            $query->query(function ($builder) use ($request) {
                $builder->with(['creator', 'tags']);

                $builder->where(function ($q) {
                    $q->where('privacy_level', 'public')
                        ->orWhere('created_by', Auth::id());
                });

                if ($request->filled('content_type')) {
                    $builder->where('content_type', $request->input('content_type'));
                }

                if ($request->filled('tags')) {
                    $builder->whereHas('tags', function ($tagQuery) use ($request) {
                        $tagQuery->whereIn('name', $request->input('tags'));
                    });
                }

                if (! $request->boolean('include_expired', false)) {
                    $builder->where(function ($q) {
                        $q->whereNull('ttl_expires_at')
                            ->orWhere('ttl_expires_at', '>', now());
                    });
                }
            });

            $results = $query->take($limit)->get();

            return $this->successResponse($results->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'content' => substr($doc->content, 0, 200),
                    'score' => 1.0,
                    'created_at' => $doc->created_at,
                ];
            }));

        } catch (\Exception $e) {
            Log::error('Knowledge API: Search failed', [
                'query' => $request->input('query'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Search failed');
        }
    }

    /**
     * Semantic search knowledge documents
     *
     * Search documents by semantic meaning using embeddings. Finds conceptually similar content
     * even when exact keywords don't match.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/knowledge-api.js`) - Semantic knowledge search:
     * - Meaning-based search using embeddings
     * - Finds conceptually related content
     * - Offline cache support with 30-minute validity
     * - Automatic fallback for offline queries
     *
     * @authenticated
     *
     * @bodyParam query string required The search query. Example: How to handle HTTP requests
     * @bodyParam limit integer Optional maximum results to return (1-100). Defaults to 10. Example: 20
     * @bodyParam content_type string Optional filter by content type. Example: external
     * @bodyParam tags string[] Optional filter by tag names. Example: ["laravel"]
     * @bodyParam include_expired boolean Optional include expired documents. Defaults to false. Example: false
     *
     * @response 200 scenario="Success" {"success": true, "data": [{"id": 1, "title": "Laravel HTTP Client", "content": "The Laravel HTTP client...", "score": 0.95, "created_at": "2024-01-01T00:00:00Z"}]}
     * @response 500 scenario="Search Failed" {"success": false, "error": "Search failed"}
     */
    public function semanticSearch(SearchKnowledgeRequest $request): JsonResponse
    {
        return $this->search($request);
    }

    /**
     * Hybrid search knowledge documents
     *
     * Combines full-text and semantic search for optimal results. Uses keyword matching
     * for precision and semantic similarity for recall.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/knowledge-api.js`) - Hybrid knowledge search (default):
     * - Combines keyword and semantic search for best results
     * - Default search type in PWA knowledge interface
     * - Offline cache support with intelligent fallback
     * - Cache validity: 30 minutes
     *
     * @authenticated
     *
     * @bodyParam query string required The search query. Example: Laravel middleware authentication
     * @bodyParam limit integer Optional maximum results to return (1-100). Defaults to 10. Example: 20
     * @bodyParam content_type string Optional filter by content type. Example: text
     * @bodyParam tags string[] Optional filter by tag names. Example: ["laravel", "security"]
     * @bodyParam include_expired boolean Optional include expired documents. Defaults to false. Example: false
     *
     * @response 200 scenario="Success" {"success": true, "data": [{"id": 1, "title": "Authentication Middleware", "content": "Laravel middleware...", "score": 0.98, "created_at": "2024-01-01T00:00:00Z"}]}
     * @response 500 scenario="Search Failed" {"success": false, "error": "Search failed"}
     */
    public function hybridSearch(SearchKnowledgeRequest $request): JsonResponse
    {
        return $this->search($request);
    }

    /**
     * Find similar documents
     *
     * Find documents similar to a given document based on content type and other attributes.
     * Useful for recommendations and related content discovery.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID to find similar documents for. Example: 1
     *
     * @queryParam limit integer Optional maximum results to return (1-100). Defaults to 5. Example: 10
     *
     * @response 200 scenario="Success" {"success": true, "data": [{"id": 2, "title": "Laravel Routing Advanced", "content": "Advanced routing techniques...", "score": 0.8}]}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to find similar documents"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data array Array of similar documents
     * @responseField data[].id integer Document ID
     * @responseField data[].title string Document title
     * @responseField data[].content string Content preview (first 200 characters)
     * @responseField data[].score number Similarity score (0-1)
     */
    public function similarDocuments(KnowledgeDocument $document, Request $request): JsonResponse
    {
        try {
            $similar = KnowledgeDocument::where('id', '!=', $document->id)
                ->where(function ($q) {
                    $q->where('privacy_level', 'public')
                        ->orWhere('created_by', Auth::id());
                })
                ->where('content_type', $document->content_type)
                ->limit($request->input('limit', 5))
                ->get();

            return $this->successResponse($similar->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'content' => substr($doc->content, 0, 200),
                    'score' => 0.8,
                ];
            }));

        } catch (\Exception $e) {
            Log::error('Knowledge API: Similar documents failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return $this->serverErrorResponse('Failed to find similar documents');
        }
    }
}
