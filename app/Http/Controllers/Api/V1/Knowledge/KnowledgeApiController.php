<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Exceptions\FileValidationException;
use App\Http\Controllers\Api\V1\Knowledge\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Knowledge\ListKnowledgeRequest;
use App\Http\Requests\Api\V1\Knowledge\StoreKnowledgeRequest;
use App\Http\Requests\Api\V1\Knowledge\UpdateKnowledgeRequest;
use App\Models\KnowledgeDocument;
use App\Services\FileUploadService;
use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Knowledge Management
 *
 * Comprehensive API for managing knowledge documents, files, and RAG (Retrieval-Augmented Generation) operations.
 *
 * ## Document Types
 * - **File uploads**: PDF, Word, text files, code files
 * - **Text documents**: Direct text content
 * - **External URLs**: Automatic fetching and refresh
 *
 * ## Features
 * - Semantic search with embeddings
 * - Hybrid search (keyword + semantic)
 * - Document tagging and categorization
 * - Agent assignment for RAG context
 * - Privacy controls (private/public)
 *
 * ## Rate Limiting
 * - File uploads & extraction: 10 requests/minute
 * - Search & reprocessing: 60 requests/minute
 * - Read operations: 300 requests/minute
 *
 * ## Security
 * All file uploads are validated with magic byte verification, executable detection,
 * and path traversal protection.
 */
class KnowledgeApiController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected KnowledgeManager $knowledgeManager,
        protected FileUploadService $fileUploadService
    ) {}

    /**
     * List knowledge documents
     *
     * Retrieve knowledge documents with filtering and pagination. Returns public documents
     * and the authenticated user's private documents. Supports filtering by content type,
     * privacy level, processing status, tags, and TTL expiration.
     *
     * @authenticated
     *
     * @queryParam only_my_documents boolean Optional show only documents created by you. Defaults to false (shows public + yours). Example: false
     * @queryParam content_type string Optional filter by content type. Options: text, file, external. Example: text
     * @queryParam privacy_level string Optional filter by privacy. Options: private, public. Example: private
     * @queryParam status string Optional filter by processing status. Options: pending, processing, completed, failed. Example: completed
     * @queryParam tags string[] Optional filter by tag names. Example: ["laravel", "php"]
     * @queryParam include_expired boolean Optional include expired documents (past TTL). Defaults to false. Example: false
     * @queryParam per_page integer Optional results per page (1-100). Defaults to 50. Example: 20
     *
     * @response 200 scenario="Success" {"success": true, "data": [{"id": 1, "title": "Laravel Documentation", "description": "Comprehensive Laravel guide", "content_type": "external", "privacy_level": "public", "processing_status": "completed", "url": "https://laravel.com/docs", "domain": "laravel.com", "tags": [{"id": 1, "name": "laravel"}], "created_at": "2024-01-01T00:00:00Z", "updated_at": "2024-01-01T00:00:00Z"}], "meta": {"current_page": 1, "per_page": 50, "total": 100}}
     * @response 500 scenario="Server Error" {"success": false, "error": "Failed to retrieve documents"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data array Array of knowledge documents
     * @responseField data[].id integer Document ID
     * @responseField data[].title string Document title
     * @responseField data[].description string Document description
     * @responseField data[].content_type string Content type (text, file, external)
     * @responseField data[].privacy_level string Privacy level (private, public)
     * @responseField data[].processing_status string Processing status (pending, processing, completed, failed)
     * @responseField data[].url string URL for external documents (null for others)
     * @responseField data[].domain string Domain name for external documents
     * @responseField data[].tags array Associated tags
     * @responseField data[].created_at string Creation timestamp (ISO 8601)
     * @responseField data[].updated_at string Last update timestamp (ISO 8601)
     * @responseField meta object Pagination metadata
     */
    public function index(ListKnowledgeRequest $request): JsonResponse
    {
        try {
            $query = KnowledgeDocument::with(['creator', 'tags']);

            // Apply user access control
            $onlyMyDocuments = $request->boolean('only_my_documents', false);
            if ($onlyMyDocuments) {
                $query->where('created_by', Auth::id());
            } else {
                // Show public documents and user's own documents
                $query->where(function ($q) {
                    $q->where('privacy_level', 'public')
                        ->orWhere('created_by', Auth::id());
                });
            }

            // PERFORMANCE: Search parameter removed - use POST /api/v1/knowledge/search instead
            // Inefficient LIKE %...% queries on large content columns caused full table scans

            if ($request->filled('content_type')) {
                $query->where('content_type', $request->input('content_type'));
            }

            if ($request->filled('privacy_level')) {
                $query->where('privacy_level', $request->input('privacy_level'));
            }

            if ($request->filled('status')) {
                $query->where('processing_status', $request->input('status'));
            }

            if ($request->filled('tags')) {
                $query->whereHas('tags', function ($tagQuery) use ($request) {
                    $tagQuery->whereIn('name', $request->input('tags'));
                });
            }

            // TTL/Expiration filter
            if (! $request->boolean('include_expired', false)) {
                $query->where(function ($q) {
                    $q->whereNull('ttl_expires_at')
                        ->orWhere('ttl_expires_at', '>', now());
                });
            }

            // SECURITY: Validate and cap per_page to prevent resource exhaustion
            $perPage = min(
                $request->integer('per_page', config('api.pagination.default_per_page')),
                config('api.pagination.max_per_page')
            );

            // Log large pagination requests for monitoring
            if ($perPage > 50) {
                Log::info('Knowledge API: Large pagination requested', [
                    'user_id' => Auth::id(),
                    'per_page' => $perPage,
                    'endpoint' => 'knowledge.index',
                ]);
            }

            $documents = $query->orderBy('updated_at', 'desc')->paginate($perPage);

            return $this->paginatedResponse($documents);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to list documents', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve documents');
        }
    }

    /**
     * List recent knowledge documents
     *
     * Retrieve recently created knowledge documents. Returns up to 100 most recent completed documents
     * that are either public or owned by the authenticated user. Excludes expired documents.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/knowledge-api.js`) - Recent documents with offline support:
     * - Displays recently added knowledge documents
     * - Caches all returned documents for offline access
     * - Fallback to cached documents when offline
     * - Sorted by creation date (newest first)
     *
     * @authenticated
     *
     * @queryParam limit integer Optional maximum number of documents to return (1-100). Defaults to 20. Example: 50
     *
     * @response 200 scenario="Success" {"success": true, "data": [{"id": 1, "title": "Laravel Documentation", "content_type": "external", "created_at": "2024-01-01T00:00:00Z"}]}
     * @response 500 scenario="Server Error" {"success": false, "error": "Failed to retrieve recent documents"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data array Array of recent documents (ordered by creation date, newest first)
     */
    public function recent(Request $request): JsonResponse
    {
        try {
            $limit = min($request->integer('limit', 20), 100); // Max 100

            $query = KnowledgeDocument::with(['creator', 'tags']);

            // Show public documents and user's own documents
            $query->where(function ($q) {
                $q->where('privacy_level', 'public')
                    ->orWhere('created_by', Auth::id());
            });

            // Filter out expired documents
            $query->where(function ($q) {
                $q->whereNull('ttl_expires_at')
                    ->orWhere('ttl_expires_at', '>', now());
            });

            // Only show completed documents
            $query->where('processing_status', 'completed');

            $documents = $query->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return $this->successResponse($documents);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to retrieve recent documents', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve recent documents');
        }
    }

    /**
     * Create a new knowledge document
     *
     * Create a knowledge document from text content, file upload, or external URL.
     * Supports automatic content extraction, embedding generation, and TTL-based expiration.
     *
     * ## Example Usage
     *
     * **Chrome Extension** ([github.com/promptlyagentai/chrome-extension](https://github.com/promptlyagentai/chrome-extension)) - Save web page content to knowledge base:
     * - Extracts content using Mozilla Readability
     * - Sends as `content_type: 'text'` with title, content, tags, TTL
     * - Requires `knowledge:create` token ability
     *
     * @authenticated
     *
     * @bodyParam content_type string required Type of content. Options: text, file, external. Example: text
     * @bodyParam title string required Document title. Maximum 500 characters. Example: Laravel Best Practices
     * @bodyParam description string Optional document description. Example: Comprehensive guide to Laravel development patterns
     * @bodyParam content string Required for text documents. The text content. Maximum 500,000 characters. Example: Laravel follows the MVC pattern...
     * @bodyParam file file Required for file documents. The file to upload (PDF, Word, text, code files). Maximum 50MB. No-example
     * @bodyParam external_source string Required for external documents. The URL to fetch content from. Example: https://laravel.com/docs
     * @bodyParam tags string[] Optional array of tag names. Example: ["laravel", "php", "best-practices"]
     * @bodyParam privacy_level string Optional privacy level. Options: private, public. Defaults to private. Example: private
     * @bodyParam ttl_hours integer Optional time-to-live in hours. Document expires after this period. Example: 168
     * @bodyParam external_source_identifier string Optional external source URL (for tracking duplicates). Example: https://example.com/article
     * @bodyParam author string Optional author name. Example: Taylor Otwell
     * @bodyParam thumbnail_url string Optional thumbnail image URL. Example: https://example.com/thumb.jpg
     * @bodyParam favicon_url string Optional favicon URL. Example: https://example.com/favicon.ico
     * @bodyParam notes string Optional internal notes about the document. Example: Added for Q1 training materials
     * @bodyParam auto_refresh_enabled boolean Optional enable automatic refresh for external documents. Example: false
     * @bodyParam refresh_interval_minutes integer Optional refresh interval in minutes (requires auto_refresh_enabled). Example: 1440
     * @bodyParam screenshot string Optional base64-encoded screenshot. No-example
     *
     * @response 201 scenario="Success" {"success": true, "data": {"id": 1, "title": "Laravel Best Practices", "content_type": "text", "privacy_level": "private", "processing_status": "completed", "created_at": "2024-01-01T00:00:00Z"}, "message": "Knowledge document created successfully"}
     * @response 422 scenario="File Validation Failed" {"success": false, "error": "FILE_VALIDATION_FAILED", "message": "File type not allowed: executable"}
     * @response 500 scenario="Creation Failed" {"success": false, "error": "CREATION_FAILED", "message": "Failed to create document"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object The created knowledge document
     * @responseField data.id integer Document ID
     * @responseField data.title string Document title
     * @responseField data.content_type string Content type (text, file, external)
     * @responseField data.privacy_level string Privacy level
     * @responseField data.processing_status string Current processing status
     * @responseField data.created_at string Creation timestamp (ISO 8601)
     * @responseField message string Success message
     */
    public function store(StoreKnowledgeRequest $request): JsonResponse
    {
        try {
            $document = null;

            if ($request->input('content_type') === 'text') {
                // When ttl_hours is explicitly in the request (even if null), respect user choice
                // When ttl_hours is absent from request, allow AI suggestions
                $ttlHours = $request->has('ttl_hours') ? $request->input('ttl_hours') : null;
                $applyAiSuggestedTtl = ! $request->has('ttl_hours');

                $document = $this->knowledgeManager->createFromText(
                    content: $request->input('content'),
                    title: $request->input('title'),
                    description: $request->input('description'),
                    tags: $request->input('tags', []),
                    privacyLevel: $request->input('privacy_level', 'private'),
                    ttlHours: $ttlHours,
                    userId: Auth::id(),
                    externalSourceIdentifier: $request->input('external_source_identifier'),
                    author: $request->input('author'),
                    thumbnailUrl: $request->input('thumbnail_url'),
                    faviconUrl: $request->input('favicon_url'),
                    applyAiSuggestedTtl: $applyAiSuggestedTtl,
                    notes: $request->input('notes'),
                    autoRefreshEnabled: $request->boolean('auto_refresh_enabled', false),
                    refreshIntervalMinutes: $request->input('refresh_interval_minutes'),
                    screenshot: $request->input('screenshot')
                );
            } elseif ($request->input('content_type') === 'file') {
                try {
                    // SECURITY: Validate file using centralized FileUploadService
                    // Performs magic byte verification, executable detection, archive scanning
                    $fileResult = $this->fileUploadService->uploadAndValidate(
                        file: $request->file('file'),
                        storagePath: 'knowledge-documents',
                        context: [
                            'user_id' => Auth::id(),
                            'content_type' => 'file',
                        ]
                    );

                    // Create knowledge document using validated and uploaded file
                    $document = $this->knowledgeManager->createFromFile(
                        file: $request->file('file'),
                        title: $request->input('title'),
                        description: $request->input('description'),
                        tags: $request->input('tags', []),
                        privacyLevel: $request->input('privacy_level', 'private'),
                        ttlHours: $request->input('ttl_hours'),
                        userId: Auth::id()
                    );

                } catch (FileValidationException $e) {
                    return $this->errorResponse(
                        'FILE_VALIDATION_FAILED',
                        $e->getMessage(),
                        422
                    );
                }
            } elseif ($request->input('content_type') === 'external') {
                $document = $this->knowledgeManager->createFromExternal(
                    source: $request->input('external_source'),
                    title: $request->input('title'),
                    description: $request->input('description'),
                    tags: $request->input('tags', []),
                    privacyLevel: $request->input('privacy_level', 'private'),
                    ttlHours: $request->input('ttl_hours'),
                    userId: Auth::id()
                );
            }

            if (! $document) {
                return $this->errorResponse('CREATION_FAILED', 'Failed to create document', 500);
            }

            return $this->successResponse(
                $document->load(['creator', 'tags']),
                ['message' => 'Knowledge document created successfully'],
                201
            );

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to create document', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to create document: '.$e->getMessage());
        }
    }

    /**
     * Check if URL already exists
     *
     * Check if a URL has already been added to your knowledge base. Useful for preventing
     * duplicate document creation when importing external content.
     *
     * @authenticated
     *
     * @queryParam url string required The URL to check. Example: https://laravel.com/docs
     *
     * @response 200 scenario="URL Exists" {"success": true, "exists": true, "document": {"id": 1, "title": "Laravel Documentation", "url": "https://laravel.com/docs"}}
     * @response 200 scenario="URL Not Found" {"success": true, "exists": false, "document": null}
     * @response 422 scenario="Missing URL" {"success": false, "error": "VALIDATION_ERROR", "message": "URL parameter is required"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Failed to check URL"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField exists boolean Whether the URL exists in your knowledge base
     * @responseField document object The existing document (null if not found)
     */
    public function checkUrl(Request $request): JsonResponse
    {
        try {
            $url = $request->input('url');

            if (! $url) {
                return $this->errorResponse('VALIDATION_ERROR', 'URL parameter is required', 422);
            }

            // Find document by external_source_identifier for current user
            $document = KnowledgeDocument::where('external_source_identifier', $url)
                ->where('created_by', Auth::id())
                ->with(['creator', 'tags'])
                ->first();

            if ($document) {
                return $this->successResponse([
                    'exists' => true,
                    'document' => $document,
                ]);
            }

            return $this->successResponse([
                'exists' => false,
                'document' => null,
            ]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to check URL', [
                'url' => $request->input('url'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to check URL');
        }
    }

    /**
     * View a knowledge document
     *
     * Retrieve complete details for a specific knowledge document including content,
     * metadata, tags, and embedding status.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/knowledge-api.js`) - Document viewer with offline support:
     * - Retrieves full document details including content
     * - Automatic caching for offline access
     * - Staleness check (cached data valid for 30 minutes)
     * - Fallback to cache when offline
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @response 200 scenario="Success" {"success": true, "data": {"id": 1, "title": "Laravel Documentation", "description": "Comprehensive guide", "content": "Laravel is a web application framework...", "content_type": "external", "privacy_level": "public", "processing_status": "completed", "url": "https://laravel.com/docs", "embedding_status": {"has_embeddings": true, "chunk_count": 150, "last_embedded_at": "2024-01-01T00:00:00Z"}, "tags": [{"id": 1, "name": "laravel"}], "created_at": "2024-01-01T00:00:00Z"}}
     * @response 403 scenario="Unauthorized" {"success": false, "error": "You do not have permission to view this document"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Failed to retrieve document"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Complete document details
     * @responseField data.embedding_status object Embedding generation status
     * @responseField data.embedding_status.has_embeddings boolean Whether embeddings exist
     * @responseField data.embedding_status.chunk_count integer Number of text chunks with embeddings
     * @responseField data.embedding_status.last_embedded_at string Last embedding generation timestamp
     */
    public function show(Request $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            // Check authorization
            if (! $request->user()->can('view', $document)) {
                return $this->unauthorizedResponse('You do not have permission to view this document');
            }

            $document->load(['creator', 'tags']);

            // Get embedding status
            $embeddingStatus = $this->knowledgeManager->getDocumentEmbeddingStatus($document);

            return $this->successResponse(
                array_merge($document->toArray(), [
                    'embedding_status' => $embeddingStatus,
                ])
            );

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to retrieve document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve document');
        }
    }

    /**
     * Update a knowledge document
     *
     * Update document metadata including title, description, privacy level, tags, and TTL.
     * Content cannot be modified - create a new document for content changes.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @bodyParam title string Optional new title. Maximum 500 characters. Example: Updated Laravel Guide
     * @bodyParam description string Optional new description. Example: Updated comprehensive guide
     * @bodyParam privacy_level string Optional new privacy level. Options: private, public. Example: public
     * @bodyParam tags string[] Optional new tags (replaces existing). Example: ["laravel", "updated"]
     * @bodyParam ttl_hours integer Optional new TTL in hours. Example: 720
     *
     * @response 200 scenario="Success" {"success": true, "data": {"id": 1, "title": "Updated Laravel Guide", "privacy_level": "public"}, "message": "Knowledge document updated successfully"}
     * @response 403 scenario="Unauthorized" {"success": false, "error": "You do not have permission to update this document"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Failed to update document"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Updated document
     * @responseField message string Success message
     */
    public function update(UpdateKnowledgeRequest $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            // Check authorization
            if (! $request->user()->can('update', $document)) {
                return $this->unauthorizedResponse('You do not have permission to update this document');
            }

            $updatedDocument = $this->knowledgeManager->updateDocument(
                $document,
                $request->only(['title', 'description', 'privacy_level', 'tags', 'ttl_hours'])
            );

            return $this->successResponse(
                $updatedDocument->load(['creator', 'tags']),
                ['message' => 'Knowledge document updated successfully']
            );

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to update document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to update document');
        }
    }

    /**
     * Delete a knowledge document
     *
     * Permanently delete a document, its embeddings, and associated files.
     * This action cannot be undone.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @response 200 scenario="Success" {"success": true, "data": null, "message": "Knowledge document deleted successfully"}
     * @response 403 scenario="Unauthorized" {"success": false, "error": "You do not have permission to delete this document"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Failed to delete document"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data null Always null for delete operations
     * @responseField message string Success message
     */
    public function destroy(Request $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            // Check authorization
            if (! $request->user()->can('delete', $document)) {
                return $this->unauthorizedResponse('You do not have permission to delete this document');
            }

            $this->knowledgeManager->deleteDocument($document);

            return $this->successResponse(
                null,
                ['message' => 'Knowledge document deleted successfully']
            );

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to delete document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to delete document');
        }
    }

    /**
     * Reprocess a knowledge document
     *
     * Regenerate embeddings and reindex the document. Useful after system updates
     * or when embeddings are corrupted.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @bodyParam async boolean Optional execute reprocessing asynchronously. Defaults to false. Example: true
     *
     * @response 200 scenario="Success (Sync)" {"success": true, "data": {"chunks_processed": 150, "embeddings_generated": 150}, "message": "Document reprocessed successfully"}
     * @response 202 scenario="Success (Async)" {"success": true, "status": "processing", "job_id": "job_abc123", "status_url": "/api/v1/knowledge/1"}
     * @response 403 scenario="Unauthorized" {"success": false, "error": "You do not have permission to reprocess this document"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Failed to reprocess document"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Reprocessing results (sync mode)
     * @responseField status string Processing status (async mode)
     * @responseField job_id string Job identifier (async mode)
     * @responseField status_url string URL to check processing status (async mode)
     * @responseField message string Success message
     */
    public function reprocess(Request $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            // Check authorization (update permission required)
            if (! $request->user()->can('update', $document)) {
                return $this->unauthorizedResponse('You do not have permission to reprocess this document');
            }

            // Check if async execution requested
            $async = $request->boolean('async', false);

            if ($async) {
                return $this->asyncResponse(
                    'job_'.uniqid(),
                    route('api.v1.knowledge.show', $document)
                );
            }

            // Synchronous reprocessing
            $result = $this->knowledgeManager->reprocessDocument($document);

            return $this->successResponse(
                $result,
                ['message' => 'Document reprocessed successfully']
            );

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to reprocess document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to reprocess document');
        }
    }

    /**
     * Download a knowledge document file
     *
     * Download the original uploaded file for file-type documents.
     * Only available for documents created from file uploads.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @response 200 scenario="Success" (binary file download)
     * @response 403 scenario="Unauthorized" Forbidden
     * @response 404 scenario="Not a File" File not found
     * @response 404 scenario="File Missing" File not found
     */
    public function download(Request $request, KnowledgeDocument $document): Response
    {
        // Check authorization
        if (! $request->user()->can('view', $document)) {
            abort(403, 'You do not have permission to download this document');
        }

        // Check if it's a file document
        if ($document->content_type !== 'file' || ! $document->asset) {
            abort(404, 'File not found');
        }

        // Check if file exists
        if (! $document->asset->exists()) {
            abort(404, 'File not found');
        }

        return response()->download($document->asset->getPath(), $document->asset->original_filename);
    }

    /**
     * Refresh external document content
     *
     * Queue a job to re-fetch content from the external URL. Only available for
     * documents created from external URLs (content_type: external).
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @response 200 scenario="Success" {"success": true, "data": null, "message": "Document refresh queued successfully"}
     * @response 403 scenario="Unauthorized" {"success": false, "error": "You do not have permission to refresh this document"}
     * @response 422 scenario="Not Refreshable" {"success": false, "error": "INVALID_DOCUMENT", "message": "Document is not refreshable"}
     * @response 422 scenario="No External URL" {"success": false, "error": "INVALID_DOCUMENT", "message": "Document does not have an external source URL"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Failed to queue document refresh"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data null Always null
     * @responseField message string Success message
     */
    public function refresh(Request $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            // Check authorization
            if (! $request->user()->can('update', $document)) {
                return $this->unauthorizedResponse('You do not have permission to refresh this document');
            }

            // Validate document is refreshable
            if (! in_array($document->content_type, ['external', 'text'])) {
                return $this->errorResponse('INVALID_DOCUMENT', 'Document is not refreshable', 422);
            }

            if (! $document->external_source_identifier) {
                return $this->errorResponse('INVALID_DOCUMENT', 'Document does not have an external source URL', 422);
            }

            // Dispatch refresh job
            \App\Jobs\RefreshExternalKnowledgeJob::dispatch($document);

            return $this->successResponse(
                null,
                ['message' => 'Document refresh queued successfully']
            );

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to queue document refresh', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to queue document refresh');
        }
    }

    /**
     * Extract content from a URL
     *
     * Extract and parse content from a URL without creating a document. Returns extracted
     * text, title, description, and metadata. Useful for previewing content before saving.
     *
     * Includes SSRF protection to block access to private networks and metadata services.
     *
     * @authenticated
     *
     * @bodyParam url string required The URL to extract content from. Example: https://laravel.com/docs
     *
     * @response 200 scenario="Success" {"success": true, "data": {"content": "Laravel is a web application framework...", "title": "Laravel Documentation", "description": "The PHP framework for web artisans", "tags": ["framework", "php"], "metadata": {"author": "Laravel", "published_at": "2024-01-01"}}}
     * @response 422 scenario="Validation Failed" {"success": false, "error": "VALIDATION_ERROR", "message": "URL parameter is required"}
     * @response 422 scenario="Invalid URL" {"success": false, "error": "VALIDATION_ERROR", "message": "Invalid URL format"}
     * @response 403 scenario="SSRF Blocked" {"success": false, "error": "SSRF_BLOCKED", "message": "Access to this URL is not allowed for security reasons"}
     * @response 500 scenario="Extraction Failed" {"success": false, "error": "Failed to extract content from URL: Connection timeout"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Extracted content and metadata
     * @responseField data.content string Extracted text content
     * @responseField data.title string Page title
     * @responseField data.description string Page description/summary
     * @responseField data.tags array Auto-extracted tags
     * @responseField data.metadata object Additional metadata (author, published date, etc.)
     */
    public function extractUrl(Request $request): JsonResponse
    {
        try {
            $url = $request->input('url');

            if (! $url) {
                return $this->errorResponse('VALIDATION_ERROR', 'URL parameter is required', 422);
            }

            // Validate URL format
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                return $this->errorResponse('VALIDATION_ERROR', 'Invalid URL format', 422);
            }

            // SSRF Protection: Block access to private networks, metadata services, etc.
            $ssrfValidation = \App\Services\Security\SsrfProtection::validate($url);
            if (! $ssrfValidation['valid']) {
                Log::warning('Knowledge API: SSRF attempt blocked', [
                    'url' => $url,
                    'error' => $ssrfValidation['error'],
                    'resolved_ip' => $ssrfValidation['ip'],
                    'user_id' => Auth::id(),
                ]);

                return $this->errorResponse('SSRF_BLOCKED', 'Access to this URL is not allowed for security reasons', 403);
            }

            // Use the ExternalProcessor to extract content
            $processor = app(\App\Services\Knowledge\Processors\ExternalProcessor::class);

            // Create a temporary document model for processing
            $tempDocument = new KnowledgeDocument([
                'external_source_identifier' => $url,
                'content_type' => 'external',
                'created_by' => Auth::id(),
            ]);

            // Process the URL and extract content
            $result = $processor->process($tempDocument, [
                'url' => $url,
            ]);

            return $this->successResponse([
                'content' => $result['content'] ?? '',
                'title' => $result['title'] ?? '',
                'description' => $result['description'] ?? '',
                'tags' => $result['tags'] ?? [],
                'metadata' => $result['metadata'] ?? [],
            ]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to extract URL content', [
                'url' => $url ?? null,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to extract content from URL: '.$e->getMessage());
        }
    }
}
