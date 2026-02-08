<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Http\Controllers\Api\V1\Knowledge\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Knowledge\RegenerateEmbeddingsRequest;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Knowledge Management
 *
 * Manage embeddings for knowledge documents. Embeddings power semantic search and RAG
 * by converting document text into vector representations.
 *
 * ## Features
 * - View system-wide embedding status and statistics
 * - Check embedding status for specific documents
 * - Regenerate missing embeddings in bulk
 * - Regenerate embeddings for specific documents
 *
 * ## Rate Limiting
 * - Status operations: 60 requests/minute
 * - Regeneration operations: 10 requests/minute
 */
class KnowledgeEmbeddingController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected KnowledgeManager $knowledgeManager
    ) {}

    /**
     * Get system-wide embedding status
     *
     * Retrieve overall embedding statistics and status for all documents in the system.
     * Shows counts of documents with/without embeddings and processing status.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"success": true, "data": {"statistics": {"total_documents": 1000, "documents_with_embeddings": 950, "documents_without_embeddings": 50, "total_chunks": 15000, "avg_chunks_per_document": 15.8}, "documents": [{"id": 1, "title": "Laravel Guide", "has_embeddings": true, "chunk_count": 150}]}}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to retrieve embedding status"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Embedding status data
     * @responseField data.statistics object System-wide embedding statistics
     * @responseField data.statistics.total_documents integer Total documents in system
     * @responseField data.statistics.documents_with_embeddings integer Documents that have embeddings
     * @responseField data.statistics.documents_without_embeddings integer Documents missing embeddings
     * @responseField data.statistics.total_chunks integer Total text chunks with embeddings
     * @responseField data.statistics.avg_chunks_per_document number Average chunks per document
     * @responseField data.documents array Array of documents with their embedding status
     */
    public function status(Request $request): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:embeddings:view')) {
                return $this->unauthorizedResponse();
            }

            $status = $this->knowledgeManager->getEmbeddingStatus();
            $statistics = $this->knowledgeManager->getEmbeddingStatistics();

            return $this->successResponse([
                'statistics' => $statistics,
                'documents' => $status['documents'] ?? [],
            ]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to get embedding status', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve embedding status');
        }
    }

    /**
     * Get document embedding status
     *
     * Retrieve embedding status for a specific document including chunk count and last generation time.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @response 200 scenario="Success" {"success": true, "data": {"has_embeddings": true, "chunk_count": 150, "last_embedded_at": "2024-01-01T00:00:00Z", "embedding_model": "text-embedding-ada-002"}}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 403 scenario="No Permission" {"success": false, "error": "You do not have permission to view this document"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to retrieve embedding status"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Document embedding status
     * @responseField data.has_embeddings boolean Whether document has embeddings
     * @responseField data.chunk_count integer Number of text chunks with embeddings
     * @responseField data.last_embedded_at string Last embedding generation timestamp (ISO 8601)
     * @responseField data.embedding_model string Model used for embeddings
     */
    public function documentStatus(Request $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:embeddings:view')) {
                return $this->unauthorizedResponse();
            }

            if (! $request->user()->can('view', $document)) {
                return $this->unauthorizedResponse('You do not have permission to view this document');
            }

            $status = $this->knowledgeManager->getDocumentEmbeddingStatus($document);

            return $this->successResponse($status);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to get document embedding status', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve embedding status');
        }
    }

    /**
     * Regenerate missing embeddings in bulk
     *
     * Process multiple documents that are missing embeddings and generate embeddings for them.
     * Useful for batch processing after system updates or when embeddings fail.
     *
     * @authenticated
     *
     * @bodyParam limit integer Optional maximum number of documents to process. Defaults to 25. Example: 50
     *
     * @response 200 scenario="Success" {"success": true, "data": {"processed": 25, "successful": 23, "failed": 2, "failed_documents": [{"id": 10, "error": "Content too large"}, {"id": 15, "error": "Invalid format"}]}, "message": "Processed 25 documents. Successful: 23, Failed: 2"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to regenerate embeddings"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Regeneration results
     * @responseField data.processed integer Total documents processed
     * @responseField data.successful integer Documents successfully embedded
     * @responseField data.failed integer Documents that failed
     * @responseField data.failed_documents array Details of failed documents with error messages
     * @responseField message string Summary message
     */
    public function regenerateAll(RegenerateEmbeddingsRequest $request): JsonResponse
    {
        try {
            $results = $this->knowledgeManager->regenerateMissingEmbeddings(
                $request->input('limit', 25)
            );

            return $this->successResponse($results, [
                'message' => "Processed {$results['processed']} documents. Successful: {$results['successful']}, Failed: {$results['failed']}",
            ]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to regenerate embeddings', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to regenerate embeddings');
        }
    }

    /**
     * Regenerate embeddings for a document
     *
     * Regenerate embeddings for a specific document. Deletes existing embeddings and generates
     * new ones from the current document content.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @response 200 scenario="Success" {"success": true, "data": {"chunk_count": 150, "generated_at": "2024-01-01T12:00:00Z", "embedding_model": "text-embedding-ada-002"}, "message": "Document embedding regenerated successfully"}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 403 scenario="No Permission" {"success": false, "error": "You do not have permission to modify this document"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to regenerate embedding"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Embedding generation results
     * @responseField data.chunk_count integer Number of chunks created
     * @responseField data.generated_at string Generation timestamp (ISO 8601)
     * @responseField data.embedding_model string Model used for embeddings
     * @responseField message string Success message
     */
    public function regenerateDocument(Request $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:embeddings:regenerate')) {
                return $this->unauthorizedResponse();
            }

            if (! $request->user()->can('update', $document)) {
                return $this->unauthorizedResponse('You do not have permission to modify this document');
            }

            $result = $this->knowledgeManager->regenerateDocumentEmbedding($document);

            return $this->successResponse($result, [
                'message' => 'Document embedding regenerated successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to regenerate document embedding', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to regenerate embedding');
        }
    }
}
