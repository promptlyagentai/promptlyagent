<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Http\Controllers\Api\V1\Knowledge\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Knowledge Management
 *
 * Retrieve statistics and analytics for knowledge documents. Provides insights into
 * document counts, processing status, embedding coverage, and usage patterns.
 *
 * ## Features
 * - Overall knowledge base statistics
 * - Embedding service statistics
 *
 * ## Rate Limiting
 * - Statistics operations: 120 requests/minute
 */
class KnowledgeStatsController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected KnowledgeManager $knowledgeManager
    ) {}

    /**
     * Get knowledge base overview statistics
     *
     * Retrieve comprehensive statistics about your knowledge documents including totals,
     * processing status, content type breakdowns, and recent activity.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"success": true, "data": {"total_documents": 1000, "completed_documents": 950, "failed_documents": 10, "processing_rate": 95.0, "embedding_completion_rate": 92.5, "content_types": {"text": 400, "file": 350, "external": 250}, "recent_activity": 45, "embedding_service_status": {"enabled": true, "provider": "openai", "model": "text-embedding-ada-002"}}}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to retrieve statistics"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Statistics data
     * @responseField data.total_documents integer Total documents owned by user
     * @responseField data.completed_documents integer Documents successfully processed
     * @responseField data.failed_documents integer Documents that failed processing
     * @responseField data.processing_rate number Success rate percentage (0-100)
     * @responseField data.embedding_completion_rate number Percentage of documents with embeddings
     * @responseField data.content_types object Document count by content type (text, file, external)
     * @responseField data.recent_activity integer Documents created in last 7 days
     * @responseField data.embedding_service_status object Embedding service configuration
     * @responseField data.embedding_service_status.enabled boolean Whether embedding service is enabled
     * @responseField data.embedding_service_status.provider string Embedding provider (openai, cohere, etc.)
     * @responseField data.embedding_service_status.model string Model name used for embeddings
     */
    public function overview(Request $request): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:view')) {
                return $this->unauthorizedResponse();
            }

            $userId = Auth::id();

            $totalDocuments = KnowledgeDocument::where('created_by', $userId)->count();
            $completedDocuments = KnowledgeDocument::where('created_by', $userId)
                ->where('processing_status', 'completed')->count();
            $failedDocuments = KnowledgeDocument::where('created_by', $userId)
                ->where('processing_status', 'failed')->count();

            $embeddingStats = $this->knowledgeManager->getEmbeddingStatistics();

            $contentTypes = KnowledgeDocument::where('created_by', $userId)
                ->selectRaw('content_type, COUNT(*) as count')
                ->groupBy('content_type')
                ->pluck('count', 'content_type')
                ->toArray();

            $recentActivity = KnowledgeDocument::where('created_by', $userId)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            return $this->successResponse([
                'total_documents' => $totalDocuments,
                'completed_documents' => $completedDocuments,
                'failed_documents' => $failedDocuments,
                'processing_rate' => $totalDocuments > 0 ? round(($completedDocuments / $totalDocuments) * 100, 1) : 0,
                'embedding_completion_rate' => $embeddingStats['completion_rate'],
                'content_types' => $contentTypes,
                'recent_activity' => $recentActivity,
                'embedding_service_status' => [
                    'enabled' => $embeddingStats['embedding_service_enabled'],
                    'provider' => $embeddingStats['embedding_provider'],
                    'model' => $embeddingStats['embedding_model'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to get overview stats', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve statistics');
        }
    }

    /**
     * Get embedding statistics
     *
     * Retrieve detailed statistics about embedding coverage and service configuration.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"success": true, "data": {"total_documents": 1000, "documents_with_embeddings": 925, "documents_without_embeddings": 75, "completion_rate": 92.5, "total_chunks": 15000, "avg_chunks_per_document": 16.2, "embedding_service_enabled": true, "embedding_provider": "openai", "embedding_model": "text-embedding-ada-002"}}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to retrieve embedding statistics"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Embedding statistics
     * @responseField data.total_documents integer Total documents in system
     * @responseField data.documents_with_embeddings integer Documents that have embeddings
     * @responseField data.documents_without_embeddings integer Documents missing embeddings
     * @responseField data.completion_rate number Percentage with embeddings (0-100)
     * @responseField data.total_chunks integer Total text chunks with embeddings
     * @responseField data.avg_chunks_per_document number Average chunks per document
     * @responseField data.embedding_service_enabled boolean Whether embedding service is enabled
     * @responseField data.embedding_provider string Embedding provider name
     * @responseField data.embedding_model string Model used for embeddings
     */
    public function embeddings(Request $request): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:embeddings:view')) {
                return $this->unauthorizedResponse();
            }

            $statistics = $this->knowledgeManager->getEmbeddingStatistics();

            return $this->successResponse($statistics);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to get embedding stats', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve embedding statistics');
        }
    }
}
